<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Console;

use RouteForge\ThinkPHP\Support\AutoRouteScanner;
use RouteForge\ThinkPHP\Support\RouteFileLoader;
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
 *   - 幂等：已在实时路由表里（含 route/*.php 经加载器灌进来的）、或已在生成文件里的名字 → 跳过；
 *   - 悬空提醒：生成文件里有、但对应控制器/方法已不存在 → 只报告，不改动；
 *   - 生成条目不写 tier（先落 unassigned），以 `// ->tier('…') 待填` 注释提示分层；
 *   - 目标串走 Controller 派发（与自动路由行为等价，保留控制器中间件）。
 *
 * 防误用：自动判定单/多应用；单应用禁 --module；多应用必须显式 --module（或 *）。
 */
class RouteForgeGenCommand extends Command
{
    /**
     * 生成文件的头部。`use` 那行**必须**用单引号串：双引号里想落一个反斜杠要写 `\\`，
     * 此前写成 `\\\\` 于是落盘成两个反斜杠，产物 `use think\\facade\\Route;` 直接
     * ParseError（1.1.0 的 gen 产物因此从来不是合法 PHP；route/*.php 在 HTTP 与 console
     * 都会被 include，等于跑一次 gen 就把整个应用打挂）。也不能退回双引号写 `\f`——
     * PHP 的双引号里 `\f` 是换页符。
     */
    private const HEADER = "<?php\n// +---------------------------------------------------------------\n"
        . "// | route-forge/thinkphp 自动生成，请勿手工编辑本区块以外的内容。\n"
        . "// | route:forge:gen 只在此文件末尾追加新规则、绝不删除；要删改请直接编辑本文件。\n"
        . "// | 每条默认未分层（落 unassigned），按需补 ->tier(...)。\n"
        . "// +---------------------------------------------------------------\n"
        . 'use think\facade\Route;' . "\n\n";

    /**
     * 1.1.0 产物的坏写法（本常量真身：`use think\\facade\\Route;`，两个连续反斜杠）。
     * 单引号里要写四道反斜杠才落两个——与 HEADER 那次修复正好互为镜像。
     */
    private const LEGACY_BROKEN_USE = 'use think\\\\facade\\\\Route;';

    /** 与上面对照的正确写法（提示里让用户照抄）；单引号里一道反斜杠即落一个。 */
    private const LEGACY_BROKEN_USE_CORRECT = 'use think\facade\Route;';

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

    public function handle(Input $input, Output $output, AutoRouteScanner $scanner, RouteFileLoader $loader): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $path = $input->getOption('path');
        $ns = $input->getOption('namespace');
        $modeOpt = $input->getOption('mode');

        // 1) 模式判定 + 防误用
        $mode = $modeOpt ?: $scanner->detectMode();

        if ($mode === 'ambiguous') {
            // 混合布局：app/{控制器层} 与模块级控制器目录并存，两种解读都成立，不替用户猜
            $available = $scanner->availableModules();
            $output->writeln('<error>无法自动判定应用模式：app/ 根控制器层与模块级控制器目录并存。</error>');
            $output->writeln('请显式表态：--mode=single 只生成根控制器层；'
                . '--mode=multi --module=<模块[,模块]>（或 --module=*）按模块生成。'
                . ($available === [] ? '' : '可用模块：' . implode(', ', $available)));

            return 1;
        }

        if ($mode !== 'single' && $mode !== 'multi') {
            $output->writeln("<error>未知 --mode：{$mode}（应为 single|multi）</error>");

            return 1;
        }

        $modules = $this->resolveModules($input, $scanner, $mode, $output);
        if ($modules === null) {
            return 1; // resolveModules 已输出错误
        }

        // 2) 现有名字（实时表 + 生成文件），用于幂等跳过。
        //    实时表读的是 Route::getName(null)，而 think 只在 HTTP 侧由 Http::loadRoutes()
        //    include 路由文件（RouteLoaded 只是加载完毕的通知，监听者仅来自服务包的
        //    loadRoutesFrom），所以命令里必须按官方 route:list 的姿势自己加载一遍，
        //    否则 route/app.php 里已有的名字查不到，幂等跳过形同虚设、会重复生成。
        //    gen 只消费名称表，故不并入加载器的子目录/with_route 提示——多应用产物落在
        //    app/{模块}/route/ 下，套那条提示会误报。
        $targets = $this->targetFiles($scanner, $mode, $modules);

        // 旧坏产物必须早于 load() 拦下：加载器会 include route/ 下这些文件，
        // 而 1.1.0 写出的头部不是合法 PHP，include 就是 ParseError 堆栈。
        // 本命令只增不删，不自动改写上历史文件——报清楚修法让人自己拍板。
        $legacy = $this->legacyBrokenFiles($targets);
        if ($legacy !== []) {
            $output->writeln('<error>生成文件头部是 1.1.0 的坏写法（use 行含双反斜杠），不是合法 PHP：'
                . implode('、', $legacy) . '</error>');
            $output->writeln('<comment>加载它会让整个应用 ParseError（route/*.php 在 HTTP 与 console 都会被 include）。'
                . '二选一后重跑本命令：</comment>');
            $output->writeln('  1) 把那行手工改成 ' . self::LEGACY_BROKEN_USE_CORRECT . '；');
            $output->writeln('  2) 删掉该文件后重跑（本命令幂等，条目会重新生成）。');

            return 1;
        }

        $loader->load();
        $existing = $this->existingNames($scanner, $mode, $modules);

        // 3) 扫描 + 去重
        $endpoints = $scanner->scan($mode, $modules, $path, $ns);
        $plannedByFile = [];
        $seenThisRun = [];
        $skipped = 0;
        // 三类「不生成、只登记」的行：invokable / unreachable（见 AutoRouteScanner）/ mixedCase
        $notices = ['invokable' => [], 'unreachable' => [], 'mixedCase' => []];

        foreach ($endpoints as $ep) {
            if (!empty($ep['invokable'])) {
                $notices['invokable'][] = $ep;
                continue;
            }
            if (!empty($ep['unreachable'])) {
                // action_suffix 项目里无可达 URL 的方法：生成它等于凭空新增端点，只登记
                $notices['unreachable'][] = $ep;
                continue;
            }
            $key = strtolower($ep['name']);
            if (isset($existing[$key]) || isset($seenThisRun[$key])) {
                $skipped++;
                continue;
            }
            $seenThisRun[$key] = true;
            $plannedByFile[$ep['outputRouteFile']][] = $ep;

            // 仅对真正新写入的条目提示大小写风险，已生成过的不再 nag
            if (!empty($ep['mixedCaseAction'])) {
                $notices['mixedCase'][] = $ep;
            }
        }

        // 4) 悬空提醒（生成文件里有、现实已无对应控制器/方法）
        $stale = $this->collectStale($scanner, $mode, $modules);

        // 5) 报告 + 落盘
        return $this->emit($output, $plannedByFile, $skipped, $stale, $notices, $dryRun);
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
     * 检出 1.1.0 写坏的旧产物（头部 use 行含双反斜杠，不是合法 PHP）。
     *
     * 只读文件头部若干字节：坏写法必然来自 HEADER（总在文件开头），用户此后手工追加的
     * 内容不参与判定。刻意不做通用 lint（不 shell 出去调 php -l），也不该误判正确产物——
     * 正确形态是单反斜杠，不含这个 needle。
     *
     * @param string[] $files
     *
     * @return string[] 命中的文件路径
     */
    private function legacyBrokenFiles(array $files): array
    {
        $bad = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $head = (string) file_get_contents($file, false, null, 0, 400);

            if (str_contains($head, self::LEGACY_BROKEN_USE)) {
                $bad[] = $file;
            }
        }

        return $bad;
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
        $suffix = (string) $this->app->config->get('route.action_suffix', '');

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
                $class  = $this->classFromTarget($nsPrefix, $target);
                $action = substr($target, (int) strrpos($target, '/') + 1);
                // target 里的动作段是剔掉 action_suffix 的短形式（与自动路由一致），
                // 故按 think 的同一规则判定：短形式命中、或拼回 suffix 命中都算存在。
                $exists = $action === ''
                    || method_exists($class, $action)
                    || ($suffix !== '' && method_exists($class, $action . $suffix));

                if (!class_exists($class) || !$exists) {
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
     * @param array{invokable:list<array<string,mixed>>,unreachable:list<array<string,mixed>>,mixedCase:list<array<string,mixed>>} $notices
     */
    private function emit(
        Output $output,
        array $plannedByFile,
        int $skipped,
        array $stale,
        array $notices,
        bool $dryRun,
    ): int {
        $totalNew = array_sum(array_map('count', $plannedByFile));

        if ($totalNew === 0) {
            $output->writeln('<info>无需新增：所有可自动路由端点都已显式定义。</info>');
        }

        $written = [];
        foreach ($plannedByFile as $file => $eps) {
            $block = $this->renderBlock($eps);
            if ($dryRun) {
                $output->writeln("<comment>[dry-run] 将向 {$file} 追加 " . count($eps) . ' 条：</comment>');
                $output->writeln($block);
                continue;
            }
            try {
                $this->appendToFile($file, $block);
            } catch (\RuntimeException $e) {
                // 多模块逐个追加：中途失败必须说清落了哪些、还剩哪些没落。
                // 本命令幂等（实时表/生成文件里已有的名字会跳过），不假装回滚——
                // 修好权限后重跑同一条命令即可补齐。
                $output->writeln("<error>{$e->getMessage()}</error>");
                $output->writeln('<comment>本次未全部写完：'
                    . ($written === [] ? '尚无文件落盘' : '已写入 ' . implode(', ', $written))
                    . '。修好后重跑同一命令即可补齐（幂等，已生成的条目会跳过）。</comment>');

                return 1;
            }
            $written[] = $file;
            $output->writeln("<info>已生成 " . count($eps) . " 条 → {$file}</info>");
        }

        if ($skipped > 0) {
            $output->writeln("跳过（已定义）：{$skipped} 条");
        }
        foreach ($notices['invokable'] as $ep) {
            $output->writeln('<comment>提示：invokable 控制器 ' . $ep['class']
                . ' 请手写显式规则（v1 不自动生成）。</comment>');
        }

        if ($notices['unreachable'] !== []) {
            $total    = count($notices['unreachable']);
            $examples = implode('、', array_map(
                static fn (array $ep): string => $ep['class'] . '::' . $ep['method'],
                array_slice($notices['unreachable'], 0, 3),
            )) . ($total > 3 ? ' 等' : '');

            $output->writeln("<comment>提示：{$total} 个方法在当前 route.action_suffix 下没有可达 URL（{$examples}）——"
                . 'think 是「URL 段 + action_suffix」才命中方法名，生成它们等于凭空新增端点，'
                . '故本次未写入。请手写显式规则，或按你的 suffix 约定调整方法名。</comment>');
        }

        if ($notices['mixedCase'] !== []) {
            $total    = count($notices['mixedCase']);
            $examples = implode('、', array_map(
                static fn (array $ep): string => $ep['uri'],
                array_slice($notices['mixedCase'], 0, 3),
            )) . ($total > 3 ? ' 等' : '');

            $output->writeln("<comment>注意：{$total} 条沿用了 camelCase 动作段（{$examples}）。"
                . '默认 url_case_sensitive=false 时新旧大小写写法都能命中；若你把 url_case_sensitive 设为 true，'
                . '历史上自动路由靠大小写不敏感命中的小写写法物化成显式规则后会 404，需要自行改写规则。</comment>');
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
        if (is_dir($file)) {
            throw new \RuntimeException('生成目标是目录，无法写入：' . $file);
        }

        $exists  = is_file($file);
        $payload = $exists ? "\n" . $block : self::HEADER . $block;

        // 必须 @ 抑制：think 的 Error 初始化器会把 E_WARNING 抛成 ErrorException，
        // 不抑制就轮不到我们自己的可操作消息（对齐 route:forge:types 的写盘校验）
        if (@file_put_contents($file, $payload, $exists ? FILE_APPEND : 0) === false) {
            throw new \RuntimeException("写入失败（权限/磁盘？）：{$file}");
        }
    }
}
