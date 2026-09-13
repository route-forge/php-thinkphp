<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Console\Concerns;

use RouteForge\ThinkPHP\Support\ConsoleColorDetector;
use think\console\Input;
use think\console\Output;

/**
 * 让 route:forge:* 的着色在 Windows 上真正生效，观感与 laravel 版对齐。
 *
 * 背景见 ConsoleColorDetector 的类注释：think 的自动着色检测在 Windows 上基本恒为
 * false，导致包内早已写好的 `<info>/<comment>/<error>` 标签全部退化成纯文本。
 *
 * 时序：`Console::run()` 先跑 `configureIO()`（那里处理 --ansi / --no-ansi 与框架自动检测），
 * 之后才 `doRunCommand()` → `Command::run()` → 本 trait 所挂的 `execute()`。因此在这里
 * 覆盖 `setDecorated()` 是安全的，并且天然早于任何一次 writeln。
 *
 * 优先级：用户的显式表态 > 本包自动判定。`--ansi` / `--no-ansi` 任一命中即完全不介入，
 * 把决定权留给用户；`--ansi` 同时也是自动判定偏保守时的逃生舱（README 已说明）。
 */
trait EnsuresAnsiOutput
{
    protected function ensureAnsiOutput(Input $input, Output $output): void
    {
        if ($input->hasParameterOption(['--ansi', '--no-ansi'])) {
            return;
        }

        try {
            // 双向设置：除「把 Windows 的假阴性打开」，也要让 NO_COLOR / 非 tty 生效
            $output->setDecorated(ConsoleColorDetector::colorsSupported(STDOUT));
        } catch (\Throwable $e) {
            // Buffer / Nothing 驱动压根没有 setDecorated（包内测试与非 console 输出场景），
            // 那种输出下装饰本就没有意义，静默跳过。
        }
    }
}
