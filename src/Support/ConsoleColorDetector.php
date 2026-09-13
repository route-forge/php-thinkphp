<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Support;

/**
 * 终端着色能力判定 —— 修复 think 自带检测在 Windows 上的失效。
 *
 * 为什么需要它：框架 `think\console\output\driver\Console::hasColorSupport()` 的 Windows
 * 分支要求系统版本号**精确等于** `10.0.10586`（那是 Win10 1511 的首发版号，symfony 2.x
 * 时代抄来的写法），并且只认 `TERM` 严格等于 `xterm`。于是 Win11（如 10.0.26200）和
 * Git Bash 的 `TERM=xterm-256color` 全都命不中，`decorated` 恒为 false，
 * `<info>/<comment>/<error>` 标签被 Formatter 剥成纯文本 —— 这正是「同一个包在 laravel
 * 下有颜色、在 think 下没有」的全部原因（symfony 新版早已改成 `>=` 判定 + 认 WT_SESSION
 * + 主动启用控制台 VT 模式）。
 *
 * 本类只读框架的公开能力（`Output::setDecorated`），不重绑、不反射任何框架对象。
 * `decide()` 是纯函数（环境、tty、os 家族、版本号、VT 状态全部由参数注入）以便跨平台单测；
 * `colorsSupported()` 只是采集真实环境的薄壳。
 *
 * 判据一律偏保守：任何一环不成立就不上色 —— 终端没开 VT 时硬写 ANSI 码会显示成
 * `←[32m` 这类乱码，比没颜色更糟。要强制上色永远有框架原生的 `--ansi` 可用。
 */
final class ConsoleColorDetector
{
    /** Win10 1511 起 conhost 支持 VT 转义；think 把这个下界写成了「精确等于」 */
    private const WINDOWS_VT_MIN_VERSION = '10.0.10586';

    public const OS_WINDOWS = 'windows';
    public const OS_OTHER   = 'other';

    /** 影响判定的环境变量（Windows 下 getenv 大小写不敏感，ConEmuANSI 按官方写法登记） */
    private const ENV_KEYS = ['NO_COLOR', 'TERM', 'WT_SESSION', 'ANSICON', 'ConEmuANSI', 'MSYSCON'];

    /**
     * 采集真实环境并判定。
     *
     * $stream 用 STDOUT：think 写的是 `php://stdout`，与 STDOUT 同属底层 fd 1，
     * tty 与 VT 判定等价，而 STDOUT 常量无需反射框架的私有流。
     *
     * @param resource $stream
     */
    public static function colorsSupported($stream = STDOUT): bool
    {
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        // VT 尝试提前求值：它同时充当「背后是不是真控制台」的第二证据
        // （管道/重定向下 sapi_windows_vt100_support 必然失败，不会因此误染色）
        $vtEnabled = $isWindows && self::enableVtMode($stream);

        return self::decide(
            self::envSnapshot(),
            self::isTty($stream) || $vtEnabled,
            $isWindows ? self::OS_WINDOWS : self::OS_OTHER,
            $isWindows ? self::windowsBuild() : '0.0.0',
            $vtEnabled,
        );
    }

    /**
     * 纯判定逻辑（跨平台可单测，不碰任何全局状态）。
     *
     * @param array<string, string> $env          环境变量快照
     * @param bool                  $isConsole    输出流是否连着真正的终端（而非管道/重定向）
     * @param string                $osFamily     self::OS_WINDOWS | self::OS_OTHER
     * @param string                $windowsBuild 形如 10.0.26200；非 Windows 传 '0.0.0'
     * @param bool                  $vtEnabled    conhost 的 VT 处理是否已就绪
     */
    public static function decide(
        array $env,
        bool $isConsole,
        string $osFamily = self::OS_OTHER,
        string $windowsBuild = '0.0.0',
        bool $vtEnabled = false
    ): bool {
        // NO_COLOR（no-color.org）与 TERM=dumb 是「别给我颜色」的通用约定，
        // symfony/laravel 同样遵守；用户的显式 --ansi 优先级更高，由调用侧先行短路。
        if (self::filled($env, 'NO_COLOR')) {
            return false;
        }
        if (($env['TERM'] ?? '') === 'dumb') {
            return false;
        }

        // 重定向 / 管道 / CI：写了 ANSI 码就是污染产物
        // （`route:forge:list --json | jq` 与 types 的 stdout 产物尤其要紧）
        if (!$isConsole) {
            return false;
        }

        if ($osFamily !== self::OS_WINDOWS) {
            return true;
        }

        // 第三方终端（Windows Terminal / mintty / ConEmu / cmder / ansicon）自己解释 ESC，
        // 不依赖 conhost 的 VT 模式
        if (self::hasAnsiCapableTerminal($env)) {
            return true;
        }

        // 剩下是传统 conhost：系统够新 **且** PHP 成功启用了 VT 处理，两者缺一不可
        return version_compare($windowsBuild, self::WINDOWS_VT_MIN_VERSION, '>=') && $vtEnabled;
    }

    /**
     * 是否存在自带 ANSI 解释能力的 Windows 终端。
     *
     * think 那条 `TERM === 'xterm'` 的严格相等是主要失效点之一：现实里的 TERM
     * 几乎都是 xterm-256color / xterm-ghostty 这类带后缀的形态。
     *
     * @param array<string, string> $env
     */
    private static function hasAnsiCapableTerminal(array $env): bool
    {
        if (self::filled($env, 'WT_SESSION')     // Windows Terminal
            || self::filled($env, 'ANSICON')      // ansicon / cmder
            || self::filled($env, 'MSYSCON')) {   // mintty（Git Bash）
            return true;
        }

        if (($env['ConEmuANSI'] ?? '') === 'ON') { // ConEmu 明示 ANSI 可用
            return true;
        }

        $term = $env['TERM'] ?? '';
        foreach (['xterm', 'screen', 'tmux', 'linux', 'vt100', 'ansi', 'cygwin'] as $prefix) {
            if ($term !== '' && str_starts_with($term, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 尝试打开 conhost 的 VT 处理并报告结果。
     * 函数不存在（非 Windows CLI SAPI / 过旧 PHP）或开启失败时返回 false —— 宁可不染色。
     *
     * @param resource $stream
     */
    private static function enableVtMode($stream): bool
    {
        if (!function_exists('sapi_windows_vt100_support')) {
            return false;
        }

        try {
            if (@sapi_windows_vt100_support($stream) === true) {
                return true;
            }

            return @sapi_windows_vt100_support($stream, true) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param resource $stream PHP 7.2+ 有跨平台的 stream_isatty；旧环境回退 posix_isatty
     */
    private static function isTty($stream): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream) === true;
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty($stream) === true;
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function envSnapshot(): array
    {
        $env = [];
        foreach (self::ENV_KEYS as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    private static function windowsBuild(): string
    {
        if (!defined('PHP_WINDOWS_VERSION_MAJOR')) {
            return '0.0.0';
        }

        return sprintf(
            '%d.%d.%d',
            (int) PHP_WINDOWS_VERSION_MAJOR,
            (int) PHP_WINDOWS_VERSION_MINOR,
            (int) PHP_WINDOWS_VERSION_BUILD
        );
    }

    /**
     * 「设了且非空」才算表态：NO_COLOR 与 ANSICON 都是空串也应视为无意义。
     *
     * @param array<string, string> $env
     */
    private static function filled(array $env, string $key): bool
    {
        return trim($env[$key] ?? '') !== '';
    }
}
