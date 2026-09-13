<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Support\ConsoleColorDetector as Detector;

/**
 * ConsoleColorDetector::decide() 的分支覆盖（纯判定，跨平台一致）。
 *
 * 存在意义：think 自带的 Windows 着色检测要求版本号**精确等于** 10.0.10586，
 * 于是 Win11 上恒判「不支持」，包里的 <info>/<comment>/<error> 全部退化成纯文本。
 * 下面的 windowsConhost* 用例正是锁住这条修复；NO_COLOR 与 TERM=dumb 则保证
 * 「对齐 laravel」不是靠无脑强开颜色，产物纯度（--json / types）也不被 ANSI 码污染。
 */
class ConsoleColorDetectorTest extends TestCase
{
    private function decide(
        array $env = [],
        bool $isConsole = true,
        string $os = Detector::OS_OTHER,
        string $build = '0.0.0',
        bool $vt = false
    ): bool {
        return Detector::decide($env, $isConsole, $os, $build, $vt);
    }

    public function testNonWindowsTtyIsColored(): void
    {
        self::assertTrue($this->decide(), 'Linux/macOS 交互式终端应着色（与 think 的 posix_isatty 同口径）');
        self::assertTrue($this->decide(['TERM' => 'xterm-256color']), '带后缀的 TERM 不应被误判');
    }

    public function testPipeAndRedirectAreNeverColored(): void
    {
        self::assertFalse($this->decide(isConsole: false), '非终端（管道/重定向/CI）不得写 ANSI 码');
        self::assertFalse(
            $this->decide(['TERM' => 'xterm-256color'], isConsole: false),
            'TERM 再漂亮也不能压过「stdout 不是终端」'
        );
    }

    public function testNoColorAndDumbTerminalAreRespected(): void
    {
        self::assertFalse($this->decide(['NO_COLOR' => '1']), 'NO_COLOR 约定（no-color.org）须生效');
        self::assertFalse($this->decide(['NO_COLOR' => 'true']), 'NO_COLOR 只要非空即视为表态');
        self::assertTrue($this->decide(['NO_COLOR' => '']), 'NO_COLOR 显式空串不算表态');
        self::assertFalse($this->decide(['TERM' => 'dumb']), 'TERM=dumb 表示无能力终端');
    }

    // ── Windows：本包修复的主战场 ──────────────────────────────────

    public function testWindowsConhostColorsOnModernBuildWhenVtEnabled(): void
    {
        // think 原版对 10.0.26200 判 false（只认 10.0.10586 精确相等）—— 这条锁住修复
        self::assertTrue(
            $this->decide(os: Detector::OS_WINDOWS, build: '10.0.26200', vt: true),
            'Win11 的 conhost 支持 VT，PHP 已启用则应着色'
        );
        self::assertTrue(
            $this->decide(os: Detector::OS_WINDOWS, build: '10.0.10586', vt: true),
            '下界版本本身仍须着色'
        );
    }

    public function testWindowsConhostWithoutVtStaysPlain(): void
    {
        self::assertFalse(
            $this->decide(os: Detector::OS_WINDOWS, build: '10.0.26200', vt: false),
            'VT 没开成时硬写 ANSI 码会显示成乱码，宁可不上色'
        );
        self::assertFalse(
            $this->decide(os: Detector::OS_WINDOWS, build: '6.1.7601', vt: true),
            'Win7 一类老系统无 VT 能力'
        );
    }

    /**
     * 第三方终端自己解释 ESC，不依赖 conhost 的 VT 模式，因此 vt=false 也要着色。
     * 项目既有测试未使用 dataProvider 元数据（PHPUnit 12 起亦不再支持 doc-comment 写法），
     * 故此处用表驱动循环，失败消息自带场景名。
     */
    public function testAnsiCapableThirdPartyTerminalIsColored(): void
    {
        $signals = [
            'Windows Terminal' => ['WT_SESSION' => 'b1e2c3'],
            'ansicon/cmder'    => ['ANSICON' => '120/300'],
            'mintty(Git Bash)' => ['MSYSCON' => 'mintty.exe'],
            'ConEmu'           => ['ConEmuANSI' => 'ON'],
            'TERM 带后缀'       => ['TERM' => 'xterm-256color'],
            'TERM=screen'       => ['TERM' => 'screen-256color'],
        ];

        foreach ($signals as $name => $env) {
            self::assertTrue(
                $this->decide($env, os: Detector::OS_WINDOWS, build: '10.0.19041', vt: false),
                "终端（{$name}）自带 ANSI 能力时不应再要求 conhost VT"
            );
        }
    }

    public function testBareWindowsConsoleWithoutSignalsStaysPlain(): void
    {
        self::assertFalse(
            $this->decide(['ConEmuANSI' => 'OFF'], os: Detector::OS_WINDOWS, build: '10.0.26200', vt: false),
            'ConEmuANSI 只有 ON 才算表态'
        );
        self::assertFalse(
            $this->decide(['TERM' => 'dumb'], os: Detector::OS_WINDOWS, build: '10.0.26200', vt: true),
            'TERM=dumb 优先于一切着色信号'
        );
    }

    // ── 真实环境入口 ────────────────────────────────────────────────

    public function testColorsSupportedIsConsistentWithCurrentProcessEnvironment(): void
    {
        // phpunit 下 stdout 恒为管道，因此真环境入口必须给出 false ——
        // 这一条守住「跑测试/CI 时不会有人看到 ANSI 码」（示例项目里的交互实测另计）
        self::assertFalse(Detector::colorsSupported(STDOUT));
        self::assertFalse(Detector::colorsSupported(STDERR));
    }
}
