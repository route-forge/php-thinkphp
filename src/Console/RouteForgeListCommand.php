<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Console;

use RouteForge\Common\Analyzer\RouteAnalyzer;
use RouteForge\Common\Contract\ForgeExceptionContract;
use RouteForge\ThinkPHP\Adapter\ThinkRouteNormalizer;
use RouteForge\ThinkPHP\Console\Concerns\WarnsMissingConfig;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 列出所有命名路由的层级分配（含别名条目）。
 *
 * 对齐 Laravel 版 route:forge:list（SPEC §3.2）：
 *   - table 模式：别名整行黄色、被别名依赖的真实路由名绿色、
 *     撞车声明整行红色（think console <comment>/<info>/<error> 标签）；
 *   - --json 模式：结构化对象，契约结构由 common RouteAnalyzer::listPayload 保证。
 *
 * think 差异：无独立 stderr，警告与表格同流输出。
 */
class RouteForgeListCommand extends Command
{
    use WarnsMissingConfig;

    protected function configure(): void
    {
        $this->setName('route:forge:list')
            ->addOption('level', null, Option::VALUE_REQUIRED, '仅列出指定层级下的路由')
            ->addOption('json', null, Option::VALUE_NONE, '输出 JSON 格式')
            ->addOption('unassigned', null, Option::VALUE_NONE, '仅列出未分配层级的路由')
            ->addOption('aliases', null, Option::VALUE_NONE, '仅列出别名条目（旧名 → 真实路由名）')
            ->setDescription('列出所有命名路由的层级分配（route:forge:list --level=admin --json --unassigned）');
    }

    protected function execute(Input $input, Output $output)
    {
        return $this->app->invoke([$this, 'handle'], [$input, $output]);
    }

    public function handle(Input $input, Output $output, RouteAnalyzer $analyzer, ThinkRouteNormalizer $normalizer): int
    {
        // config/forge.php 未发布时：json 形态只 STDERR 指路，table 形态交互式提示复制
        $this->guardConfigPublished($input, $output, (bool) $input->getOption('json'));

        // 命令流中路由文件尚未加载（think RouteList 同款姿势），触发加载
        $this->app->event->trigger(\think\event\RouteLoaded::class);

        $levels = array_keys((array) $this->app->config->get('forge.levels', []));

        $filterLevel    = $input->getOption('level');
        $onlyUnassigned = (bool) $input->getOption('unassigned');
        $onlyAliases    = (bool) $input->getOption('aliases');
        $asJson         = (bool) $input->getOption('json');

        // level 过滤校验（unassigned 特殊层级合法）
        if ($filterLevel !== null && $filterLevel !== '' && !in_array($filterLevel, array_merge($levels, ['unassigned']), true)) {
            $output->writeln("<error>Unknown level: {$filterLevel}</error>");
            $output->writeln('Available levels: ' . (empty($levels) ? '(none)' : implode(', ', $levels)));

            return 1;
        }

        try {
            $collection = new \RouteForge\ThinkPHP\Support\ThinkRouteCollection(
                new \RouteForge\ThinkPHP\Support\RouteCollector($this->app->route),
            );
            $analysis = $analyzer->analyzeRoutes($collection, $normalizer);
        } catch (ForgeExceptionContract $e) {
            // 悬空别名 / resolve 抛出的 Forge 系异常：输出 [错误码] 消息而非裸堆栈
            $output->writeln("<error>[{$e->code()}] {$e->getMessage()}</error>");

            return 1;
        }

        $warnings = array_merge(
            $analysis['warnings'],
            \RouteForge\ThinkPHP\Support\OptionTypoScanner::scan($collection),
        );
        $aliases  = $analysis['aliases'];
        $rows     = $analyzer->filterRows($analysis['rows'], $filterLevel, $onlyUnassigned, $onlyAliases);
        $payload  = $analyzer->listPayload($levels, $rows, $analysis['tier_counts'], $warnings, $filterLevel, $onlyUnassigned, $onlyAliases);

        // JSON 输出（结构化对象，便于脚本消费）
        if ($asJson) {
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $orderedCounts = $payload['tier_counts'];

        // 层级统计汇总（unassigned 非零是「match 规则漏配」最常见的信号）
        $output->writeln('Tier counts: ' . implode(' | ', array_map(
            fn (string $l, int $c): string => "{$l}: {$c}",
            array_keys($orderedCounts),
            $orderedCounts,
        )));
        if ($orderedCounts['unassigned'] > 0) {
            $output->writeln('<comment>' . $orderedCounts['unassigned'] . " route(s) are unassigned and only available via the 'unassigned' tier. "
                . 'Check match rules in config/forge.php or add explicit ->tier(...) markers.</comment>');
        }

        // 警告在任何过滤结果下都输出；走 STDERR（think Output 无独立 stderr 流，
        // 直写 STDERR 常量）避免污染 --json 的 stdout 管道消费
        $this->printWarnings($warnings);

        if (empty($rows)) {
            $output->writeln('<info>No routes found matching the filter.</info>');

            return 0;
        }

        // 被别名依赖的真实路由名（Name 列绿色标识：改名时需同步更新别名映射）
        $aliasedTargets = array_values(array_unique(array_values($aliases)));

        $tableRows = array_map(function (array $r) use ($aliasedTargets): array {
            $methods = static fn (array $row): string => implode('|', RouteAnalyzer::withoutHead($row['methods']));

            // 别名整行黄色（仅 table 模式；JSON 输出保持纯文本契约不变）
            if ($r['alias_of'] !== null) {
                return $this->colorizeRow(
                    [$r['name'], $r['level'], $methods($r), $r['uri'], (string) $r['alias_of']],
                    'comment',
                );
            }

            // 真实路由名被别名指向 → Name 列绿色（长期稳定对外名的审计信号）
            $name = in_array($r['name'], $aliasedTargets, true)
                ? "<info>{$r['name']}</info>"
                : $r['name'];

            return [$name, $r['level'], $methods($r), $r['uri'], '—'];
        }, $rows);

        // 撞车声明红行（仅 table 展示）：被忽略的别名声明，不进入 --json 的 routes
        $rowByName = array_column($analysis['rows'], null, 'name');
        foreach ($analysis['collisions'] as $alias => $target) {
            $info = $rowByName[$target] ?? null;
            $tableRows[] = $this->colorizeRow([
                $alias,
                $info['level'] ?? '—',
                isset($info['methods']) ? implode('|', RouteAnalyzer::withoutHead($info['methods'])) : '—',
                $info['uri'] ?? '—',
                $target,
            ], 'error');
        }

        $output->writeln($this->renderTable(
            ['Name/Alias', 'Level', 'Methods', 'URI', 'Alias Of'],
            $tableRows,
        ));

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

    /**
     * 整行着色：包裹单元格文本（think console 标签，渲染为 ANSI 色）。
     *
     * @return string[]
     */
    private function colorizeRow(array $cells, string $tag): array
    {
        return array_map(
            static fn (string $cell): string => "<{$tag}>{$cell}</{$tag}>",
            $cells,
        );
    }

    /**
     * 轻量等宽表格渲染（think console Table 不支持带标签单元格的宽度计算）。
     * 列宽按去标签后的可见文本计算，含中文的列按 mb_strlen 保守估算。
     *
     * @param string[] $header
     * @param list<array<int, string>> $rows（单元格可含着色标签）
     */
    private function renderTable(array $header, array $rows): string
    {
        $visible = static fn (string $s): string => preg_replace('/<[^>]+>/', '', $s) ?? $s;
        $width   = static fn (string $s): int => mb_strlen($visible($s));

        $columns = count($header);
        $widths  = array_map(static fn (string $h): int => $width($h), $header);

        foreach ($rows as $row) {
            for ($i = 0; $i < $columns; $i++) {
                $widths[$i] = max($widths[$i], $width((string) ($row[$i] ?? '')));
            }
        }

        $pad = static function (string $cell, int $i) use ($widths, $width, $visible): string {
            $text = $visible($cell);
            $gap  = $widths[$i] - mb_strlen($text);

            // 着色标签包住原文，补齐空格放标签外，避免标签内尾随空格影响 ANSI 输出
            return $cell !== $text
                ? $cell . str_repeat(' ', max(0, $gap))
                : $cell . str_repeat(' ', max(0, $gap));
        };

        $line = static fn (string $left, string $mid, string $right): string
            => $right . implode($mid, array_map(static fn (int $w): string => str_repeat('-', $w + 2), $widths)) . $left;

        $out = [$line('+', '+', '+')];
        $out[] = '| ' . implode(' | ', array_map($pad, $header, array_keys($widths))) . ' |';
        $out[] = $line('+', '+', '+');
        foreach ($rows as $row) {
            $out[] = '| ' . implode(' | ', array_map($pad, $row, array_keys($widths))) . ' |';
        }
        $out[] = $line('+', '+', '+');

        return implode("\n", $out);
    }
}
