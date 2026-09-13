<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Console\Concerns\EnsuresAnsiOutput;
use RouteForge\ThinkPHP\Support\ConsoleColorDetector;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * EnsuresAnsiOutput 的接线契约（命令层，SPEC §3.2 的着色部分）。
 *
 * 关注三件事：
 *   1. 用户的显式 --ansi / --no-ansi 一律优先，本包绝不后手覆盖；
 *   2. 没有显式表态时按 ConsoleColorDetector 的结论双向设置 decorated；
 *   3. think 的 Buffer / Nothing 驱动压根没有 setDecorated（包内测试都走 Buffer），
 *      自愈必须静默降级而不是把命令打挂。
 *
 * 「开了之后终端确实出颜色」属于框架 Formatter + 真实 tty 的行为，
 * 由示例项目 route-forge-thinkphp-example 的 A/B 实测覆盖（见 AGENTS.md 的验证纪律）。
 */
class EnsuresAnsiOutputTest extends TestCase
{
    public function testExplicitAnsiOptionsAreLeftAlone(): void
    {
        foreach (['--ansi', '--no-ansi'] as $option) {
            $output = new RecordingOutput();
            (new AnsiProbeCommand())->probe(new Input(['route:forge:list', $option]), $output);

            self::assertSame([], $output->decoratedCalls, "显式 {$option} 时本包不得介入 decorated 状态");
        }
    }

    public function testAutomaticModeSetsDecoratedToDetectorVerdict(): void
    {
        $output = new RecordingOutput();
        (new AnsiProbeCommand())->probe(new Input(['route:forge:list']), $output);

        self::assertSame(
            [ConsoleColorDetector::colorsSupported(STDOUT)],
            $output->decoratedCalls,
            '无显式表态时应按本包判据双向设置（既补 Windows 假阴性，也让 NO_COLOR/非 tty 生效）'
        );
    }

    public function testBufferDriverDegradesSilently(): void
    {
        // Buffer 驱动没有 setDecorated：Output::__call 会抛「method not exists」，
        // 自愈必须吞掉它，否则包内所有命令测试与任何非 console 输出场景都会被带崩。
        $output = new Output('buffer');
        $input  = new Input(['route:forge:list']);

        (new AnsiProbeCommand())->probe($input, $output);

        $output->writeln('<info>plain-on-purpose</info>');
        self::assertStringContainsString('<info>plain-on-purpose</info>', $output->fetch());
    }
}

/**
 * 承载 trait 的最小命令桩（trait 方法是 protected，这里开一个 public 入口）。
 */
class AnsiProbeCommand extends Command
{
    use EnsuresAnsiOutput;

    protected function configure(): void
    {
        $this->setName('forge:ansi-probe');
    }

    public function probe(Input $input, Output $output): void
    {
        $this->ensureAnsiOutput($input, $output);
    }
}

/**
 * 记录 setDecorated 调用的 Output 桩：定义同名方法即可截走 Output::__call 的转发。
 */
class RecordingOutput extends Output
{
    /** @var list<bool> */
    public array $decoratedCalls = [];

    public function __construct()
    {
        parent::__construct('buffer');
    }

    public function setDecorated($decorated)
    {
        $this->decoratedCalls[] = (bool) $decorated;
    }
}
