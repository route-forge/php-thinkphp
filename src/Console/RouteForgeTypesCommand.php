<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Console;

use InvalidArgumentException;
use RouteForge\Common\Analyzer\RouteAnalyzer;
use RouteForge\Common\Contract\ForgeExceptionContract;
use RouteForge\Common\Repository\RouteRepository;
use RouteForge\Common\Support\StrictViolationScanner;
use RouteForge\Common\Type\TypeGenerator;
use RouteForge\ThinkPHP\Adapter\ThinkRouteNormalizer;
use RouteForge\ThinkPHP\Console\Concerns\EnsuresAnsiOutput;
use RouteForge\ThinkPHP\Console\Concerns\ReportsCommandFailure;
use RouteForge\ThinkPHP\Console\Concerns\WarnsMissingConfig;
use RouteForge\ThinkPHP\Support\RouteFileLoader;
use RuntimeException;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 从路由表生成 TS 类型声明。
 *
 * 对齐 Laravel 版 route:forge:types（SPEC §3.2）：--level / --json / --out。
 * think 差异：console 无独立 stderr 流，故警告与失败信息一律改走 STDERR 真流
 * （types 的 stdout 恒为 d.ts/JSON 产物），--out 时文件内容同样纯净。
 */
class RouteForgeTypesCommand extends Command
{
    use EnsuresAnsiOutput;
    use ReportsCommandFailure;
    use WarnsMissingConfig;

    protected function configure(): void
    {
        $this->setName('route:forge:types')
            ->addOption('level', null, Option::VALUE_REQUIRED, '仅生成指定层级下的路由类型')
            ->addOption('json', null, Option::VALUE_NONE, '输出 JSON 对象格式（键为路由名）')
            ->addOption('out', null, Option::VALUE_REQUIRED, '写入指定文件路径；不传则输出到 stdout')
            ->setDescription('生成 TS 路由类型声明（route:forge:types --level=admin --json --out=src/types/forge-routes.d.ts）');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->ensureAnsiOutput($input, $output);

        return $this->app->invoke([$this, 'handle'], [$input, $output]);
    }

    public function handle(Input $input, Output $output, RouteAnalyzer $analyzer, ThinkRouteNormalizer $normalizer, RouteFileLoader $loader): int
    {
        // types 的 stdout 恒为产物（d.ts / JSON），缺配置只走 STDERR 指路，不污染产物
        $this->guardConfigPublished($input, $output, true, 'TS 类型声明');

        // ThinkPHP 8 只在 HTTP 侧由 Http::loadRoutes() include 路由文件，RouteLoaded 事件是
        // 「加载完毕」的通知（监听者只来自服务包的 loadRoutesFrom），光 trigger 一条应用路由
        // 都进不了规则树——框架自带 route:list 也是自己 scanRoute 完才 trigger 的。
        $loader->load();

        $levels      = array_keys((array) $this->app->config->get('forge.levels', []));
        $filterLevel = $input->getOption('level');

        // level 过滤校验
        if ($filterLevel !== null && $filterLevel !== '' && !in_array($filterLevel, $levels, true)) {
            return $this->fail(
                $output,
                "Unknown level: {$filterLevel}\nAvailable levels: " . (empty($levels) ? '(none)' : implode(', ', $levels)),
                true,
            );
        }

        try {
            $collection = new \RouteForge\ThinkPHP\Support\ThinkRouteCollection(
                new \RouteForge\ThinkPHP\Support\RouteCollector($this->app->route),
            );
            $analysis = $analyzer->analyzeRoutes($collection, $normalizer);
        } catch (ForgeExceptionContract $e) {
            return $this->fail($output, "[{$e->code()}] {$e->getMessage()}", true);
        } catch (RuntimeException | InvalidArgumentException $e) {
            // 适配层自身的 fail-fast（url_lazy_route=true / 非 RuleItem 规则）：
            // 消息已含指路文本，只给消息不给框架堆栈
            return $this->fail($output, $e->getMessage(), true);
        }

        $warnings = array_merge(
            $analysis['warnings'],
            \RouteForge\ThinkPHP\Support\OptionTypoScanner::scan($collection),
            // 结构性提示（route/ 子目录、app.with_route=false）：types 侧只走 STDERR，产物仍是纯 d.ts/JSON
            $loader->warnings(),
        );

        // 严格模式违规：不再让未归级路由静默生成 d.ts——清单走 STDERR（types 的 stdout 恒为
        // d.ts/JSON 产物，不能污染），退出码 1，且不产出任何类型文件。与 list 命令同口径。
        if (StrictViolationScanner::count($analysis['violations']) > 0) {
            foreach (StrictViolationScanner::format($analysis['violations']) as $line) {
                fwrite(STDERR, $line . PHP_EOL);
            }

            return 1;
        }

        // 目标层级：全部已配置层级（--level 时仅该层级），空层级由
        // TypeGenerator::collectTargets() 预置，保证 ForgeLevel 联合类型完整
        $typeGenerator = new TypeGenerator();
        $targets       = $filterLevel !== null && $filterLevel !== '' ? [$filterLevel] : $levels;
        $routesByLevel = $typeGenerator->collectTargets($analysis['rows'], $targets);

        // 输出
        if ((bool) $input->getOption('json')) {
            $outputContent = $typeGenerator->generateJson($routesByLevel);
        } else {
            // 端点注释取实际配置，规范化与端点注册/摘要下发共用同一实现
            $endpointPrefix = RouteRepository::normalizeEndpointPrefix(
                (string) $this->app->config->get('forge.endpoint_prefix', '/_forge/routes'),
            );
            $outputContent = $typeGenerator->generateDts($routesByLevel, $endpointPrefix);
        }

        // 警告走 STDERR：think Output 无独立 stderr 流，直写 STDERR 常量，
        // 保证 stdout 产物纯净（--json 管道消费 / 无 --out 重定向不被污染）
        $this->printWarnings($warnings);

        $outFile = $input->getOption('out');
        if ($outFile !== null && $outFile !== '') {
            $dir = dirname($outFile);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $output->writeln("<error>无法创建输出目录：{$dir}</error>");

                return 1;
            }

            // @ 抑制：think 的 Error 初始化器会把 E_WARNING 抛成 ErrorException，
            // 不抑制则下面的 === false 分支在生产运行时永远走不到
            if (@file_put_contents($outFile, $outputContent) === false) {
                $output->writeln("<error>写入失败（权限/磁盘？）：{$outFile}</error>");

                return 1;
            }

            $abs = realpath($outFile) ?: $outFile;
            $output->writeln("<info>Written to: {$abs}</info>");

            return 0;
        }

        $output->writeln($outputContent);

        return 0;
    }

    /**
     * @param string[] $warnings
     */
    private function printWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            fwrite(STDERR, $warning . PHP_EOL);
        }
    }
}
