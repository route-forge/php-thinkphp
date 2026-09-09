<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Console;

use RouteForge\ThinkPHP\Support\AutoRouteScanner;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Route;

/**
 * route:forge:gen —— 从 ThinkPHP 自动路由「反向物化」出显式命名路由，增量写入
 * 专用生成文件（单应用 route/forge.auto.php；多应用 app/{模块}/route/forge.auto.php），
 * 让习惯用自动路由的项目低成本接入 route-forge。
 *
 * 语义（刻意保守）：
 *   - 只新增、绝不删除：命令永远不动已有规则；删除由开发者自己完成；
 *   - 幂等：已在实时路由表里、或已在生成文件里的名字 → 跳过；
 *   - 悬空提醒：生成文件里有、但对应控制器/方法已不存在 → 只报告，不改动；
 *   - 生成条目不写 tier（先落 unassigned），以 `// ->tier('…') 待填` 注释提示分层；
 *   - 目标串走 Controller 派发（与自动路由行为等价，保留控制器中间件）。
 *
 * 防误用：自动判定单/多应用；单应用禁 --module；多应用必须显式 --module（或 *）。
 */
class RouteForgeGenCommand extends Command
{
    private const HEADER = "<?php\n// +---------------------------------------------------------------\n"
        . "// | route-forge/thinkphp 自动生成，请勿手工编辑本区块以外的内容。\n"
        . "// | route:forge:gen 只在此文件末尾追加新规则、绝不删除；要删改请直接编辑本文件。\n"
        . "// | 每条默认未分层（落 unassigned），按需补 ->tier(...)。\n"
        . "// +---------------------------------------------------------------\n"
        . "use think\\\\facade\\\\Route;\n\n";

    protected function configure(): void
    {
        $this->setName('route:forge:gen')
            ->addOption('module', null, Option::VALUE_REQUIRED, '多应用：仅生成指定模块（逗号分隔），或 * 表示全部')
            ->addOption('path', null, Option::VALUE_REQUIRED, '仅扫描指定控制器目录（绝对路径）')
            ->addOption('namespace', null, Option::VALUE_REQUIRED, '仅扫描指定命名空间前缀（如 app\\controller\\Admin）')
            ->addOption('mode', null, Option::VALUE_REQUIRED, '强制应用模式 single|multi（默认自动检测）')
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅预览将新增/提醒的条目，不写文件')
            ->setDescription('从自动路由增量生成显式命名路由（辅助接入 route-forge）');
    }

    protected function execute(Input $input, Output $output)
    {
        return $this->app->invoke([$this, 'handle'], [$input, $output]);
    }

    public function handle(Input $input, Output $output, AutoRouteScanner $scanner): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $path = $input->getOption('path');
        $ns = $input->getOption('namespace');
        $modeOpt = $input->getOption('mode');

        // 1) 模式判定 + 防误用
        $mode = $modeOpt ?: $scanner->detectMode();
        if (!in_array($mode, ['single', 'multi'], true)) {
            $output->writeln("<error>未知 --mode：{$mode}（应为 single|multi）</error>");

            return 1;
        }

        $modules = $this->resolveModules($input, $scanner, $mode, $output);
        if ($modules === null) {
            return 1; // resolveModules 已输出错误
        }

        // 2) 现有名字（实时表 + 生成文件），用于幂等跳过
        $this->app->event->trigger(\think\event\RouteLoaded::class);
        $existing = $this->existingNames($scanner, $mode, $modules);

        // 3) 扫描 + 去重
        $endpoints = $scanner->scan($mode, $modules, $path, $ns);
        $plannedByFile = [];
        $seenThisRun = [];
        $skipped = 0;
        $invokable = [];

        foreach ($endpoints as $ep) {
            if (!empty($ep['invokable'])) {
                $invokable[] = $ep;
                continue;
            }
            $key = strtolower($ep['name']);
            if (isset($existing[$key]) || isset($seenThisRun[$key])) {
                $skipped++;
                continue;
            }
            $seenThisRun[$key] = true;
            $plannedByFile[$ep['outputRouteFile']][] = $ep;
        }

        // 4) 悬空提醒（生成文件里有、现实已无对应控制器/方法）
        $stale = $this->collectStale($scanner, $mode, $modules);

        // 5) 报告 + 落盘
        return $this->emit($output, $plannedByFile, $skipped, $stale, $invokable, $dryRun, $scanner, $mode, $modules);
    }

    /**
     * @return string[]|null 模块列表；null 表示参数非法（已输出错误）
     */
    private function resolveModules(Input $input, AutoRouteScanner $scanner, string $mode, Output $output): ?array
    {
        $moduleOpt = $input->getOption('module');

        if ($mode === 'single') {
            if ($moduleOpt !== null && $moduleOpt !== '') {
                $output->writeln('<error>单应用模式无需 --module（当前检测到单应用控制器目录）。确为多模块请加 --mode=multi。</error>');

                return null;
            }

            return [];
        }

        // multi
        $available = $scanner->availableModules();
        if ($available === []) {
            $output->writeln('<error>多应用模式但未在 app/ 下发现任何含控制器目录的模块。</error>');

            return null;
        }

        if ($moduleOpt === null || $moduleOpt === '') {
            $output->writeln('<error>多应用模式必须显式指定 --module（逗号分隔）或 --module=* 扫描全部，避免误伤。可用模块：'
                . implode(', ', $available) . '</error>');

            return null;
        }

        if (trim($moduleOpt) === '*') {
            return $available;
        }

        $picked = array_values(array_filter(array_map('trim', explode(',', $moduleOpt))));
        $bad = array_diff($picked, $available);
        if ($bad !== []) {
            $output->writeln('<error>未知模块：' . implode(', ', $bad) . '。可用：' . implode(', ', $available) . '</error>');

            return null;
        }

        return $picked;
    }

    /**
     * 已存在的名字集合（键为 lowercased）：实时路由表 + 相关生成文件里的 ->name()。
     *
     * @param string[] $modules
     *
     * @return array<string,true>
     */
    private function existingNames(AutoRouteScanner $scanner, string $mode, array $modules): array
    {
        $names = [];

        foreach (array_keys(Route::getName(null)) as $registered) {
            $names[strtolower((string) $registered)] = true;
        }

        foreach ($this->targetFiles($scanner, $mode, $modules) as $file) {
            foreach ($this->parseGeneratedNames($file) as $name) {
                $names[strtolower($name)] = true;
            }
        }

        return $names;
    }

    /**
     * 候选生成文件绝对路径列表（单应用一个、多应用每模块一个）。
     *
     * @param string[] $modules
     *
     * @return string[]
     */
    private function targetFiles(AutoRouteScanner $scanner, string $mode, array $modules): array
    {
        if ($mode === 'multi') {
            $appPath = rtrim($this->app->getAppPath(), '/\\');

            return array_map(
                static fn (string $m): string => $appPath . DIRECTORY_SEPARATOR . $m
                    . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR . 'forge.auto.php',
                $modules,
            );
        }

        return [$this->app->getRootPath() . 'route' . DIRECTORY_SEPARATOR . 'forge.auto.php'];
    }

    /**
     * @return string[] 文件内出现的 ->name('...')
     */
    private function parseGeneratedNames(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        preg_match_all("/->name\(\s*'([^']+)'\s*\)/", (string) file_get_contents($file), $m);

        return $m[1] ?? [];
    }

    /**
     * 解析生成文件里的规则条目并核验目标控制器/方法是否仍存在。
     *
     * @param string[] $modules
     *
     * @return list<array{file:string,name:string,why:string}>
     */
    private function collectStale(AutoRouteScanner $scanner, string $mode, array $modules): array
    {
        $stale = [];
        $namespace = $this->app->getNamespace();

        foreach ($this->targetFiles($scanner, $mode, $modules) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $content = (string) file_get_contents($file);
            // 形如：Route::any('uri', 'ctrl/path/action')->name('x')
            $ok = preg_match_all(
                "/Route::\w+\(\s*'[^']+'\s*,\s*'([^']+)'\s*\)[^;]*?->name\(\s*'([^']+)'\s*\)/",
                $content,
                $mm,
                PREG_SET_ORDER,
            );
            if (!$ok) {
                continue;
            }
            // 该文件对应的控制器命名空间根
            $nsPrefix = $this->nsPrefixForFile($file, $mode, $modules, $namespace);

            foreach ($mm as $set) {
                [, $target, $name] = $set;
                $class = $this->classFromTarget($nsPrefix, $target);
                $action = substr($target, (int) strrpos($target, '/') + 1);

                if (!class_exists($class) || ($action !== '' && !method_exists($class, $action))) {
                    $stale[] = [
                        'file' => $file,
                        'name' => $name,
                        'why'  => !class_exists($class) ? '控制器类不存在：' . $class : "方法 {$class}::{$action}() 不存在",
                    ];
                }
            }
        }

        return $stale;
    }

    /**
     * 由规则目标串 '控制器/子目录/动作' 还原控制器类全名（逐段 studly + 可选 Controller 后缀）。
     */
    private function classFromTarget(string $nsPrefix, string $target): string
    {
        $parts = explode('/', $target);
        array_pop($parts); // 去动作段

        $segs = array_map(static fn (string $p): string => \think\helper\Str::studly($p), $parts);
        $suffix = $this->app->config->get('route.controller_suffix') ? 'Controller' : '';

        return $nsPrefix . '\\' . implode('\\', $segs) . $suffix;
    }

    /**
     * @param string[] $modules
     */
    private function nsPrefixForFile(string $file, string $mode, array $modules, string $namespace): string
    {
        $layer = $this->app->config->get('app.controller_layer') ?: 'controller';
        if ($mode === 'multi') {
            // app/{module}/route/forge.auto.php
            $appPath = rtrim($this->app->getAppPath(), '/\\') . DIRECTORY_SEPARATOR;
            $rel = str_replace('\\', '/', substr($file, strlen($appPath)));
            $module = explode('/', $rel)[0] ?? '';

            return $namespace . '\\' . $module . '\\' . $layer;
        }

        return $namespace . '\\' . $layer;
    }

    /**
     * @param array<string, list<array<string,mixed>>> $plannedByFile
     * @param list<array{file:string,name:string,why:string}> $stale
     * @param list<array<string,mixed>> $invokable
     */
    private function emit(
        Output $output,
        array $plannedByFile,
        int $skipped,
        array $stale,
        array $invokable,
        bool $dryRun,
        AutoRouteScanner $scanner,
        string $mode,
        array $modules,
    ): int {
        $totalNew = array_sum(array_map('count', $plannedByFile));

        if ($totalNew === 0) {
            $output->writeln('<info>无需新增：所有可自动路由端点都已显式定义。</info>');
        }

        foreach ($plannedByFile as $file => $eps) {
            $block = $this->renderBlock($eps);
            if ($dryRun) {
                $output->writeln("<comment>[dry-run] 将向 {$file} 追加 " . count($eps) . ' 条：</comment>');
                $output->writeln($block);
                continue;
            }
            $this->appendToFile($file, $block);
            $output->writeln("<info>已生成 " . count($eps) . " 条 → {$file}</info>");
        }

        if ($skipped > 0) {
            $output->writeln("跳过（已定义）：{$skipped} 条");
        }
        foreach ($invokable as $ep) {
            $output->writeln('<comment>提示：invokable 控制器 ' . $ep['class']
                . ' 请手写显式规则（v1 不自动生成）。</comment>');
        }
        foreach ($stale as $s) {
            $output->writeln("<comment>提醒：{$s['file']} 中的 ->name('{$s['name']}') 已悬空（{$s['why']}），可自行清理（命令不会删除）。</comment>");
        }

        if (!$dryRun && $totalNew > 0) {
            $output->writeln('<info>完成。请审阅生成文件、补 ->tier()，并在切换 url_route_must 前用 route:forge:list 核对覆盖。</info>');
        }

        return 0;
    }

    /**
     * @param list<array<string,mixed>> $eps
     */
    private function renderBlock(array $eps): string
    {
        $out = '';
        foreach ($eps as $ep) {
            $uri = $ep['uri'];
            $target = $ep['target'];
            $name = $ep['name'];
            $out .= "Route::any('{$uri}', '{$target}')->name('{$name}'); // ->tier('…') 待填\n";
        }

        return $out;
    }

    private function appendToFile(string $file, string $block): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建目录：' . $dir);
        }
        if (!is_file($file)) {
            file_put_contents($file, self::HEADER . $block);

            return;
        }
        // 追加到既有生成文件末尾
        file_put_contents($file, "\n" . $block, FILE_APPEND);
    }
}
