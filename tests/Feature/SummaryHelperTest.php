<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use think\facade\Route;

/**
 * 内嵌摘要 helper forge_summary()（Laravel @forgeSummary 的 ThinkPHP 等价物，
 * SPEC §3.1.8）。
 */
class SummaryHelperTest extends TestCase
{
    public function testHelperRendersSelfDeletingScript(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels' => ['public' => ['description' => '公共接口', 'match' => [], 'load' => 'eager']],
        ]);

        Route::get('auth/login', function () {
            return 'login';
        })->name('auth.login')->tier('public');

        $html = \forge_summary();

        // <script> 包裹 + 固定全局 key + defineProperty 一次性 getter
        self::assertStringStartsWith('<script>', $html);
        self::assertStringEndsWith('</script>', $html);
        self::assertStringContainsString('window.__ROUTE_FORGE__', $html);
        self::assertStringContainsString('Object.defineProperty', $html);
        self::assertStringContainsString('delete window.__ROUTE_FORGE__', $html);

        // 内嵌 JSON 与摘要端点同一 producer：抽取 JSON.parse('..') 表达式解码比对。
        // 表达式内容 = 第一层 JSON 文本经第二层 JSON 字符串编码（剥外引号），
        // 补回外引号用 json_decode 还原第一层 JSON 文本
        self::assertMatchesRegularExpression("/JSON\.parse\('(.+)'\)/sU", $html, 'missing JSON.parse expression');
        preg_match("/JSON\.parse\('(.+)'\)/sU", $html, $m);

        // script 不可被内嵌 JSON 截断（JsSafeEncoder 的 </script> 逃逸红线）
        self::assertSame(1, substr_count($html, '</script>'));

        $jsonText = json_decode('"' . $m[1] . '"');
        self::assertIsString($jsonText);

        $decoded = json_decode($jsonText, true);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['schemeVersion']);
        self::assertArrayHasKey('public', $decoded['levels']);
    }

    public function testHelperContainsOnlySummaryNotLevelDetails(): void
    {
        // 红线：只嵌摘要，绝不内嵌层级路由表（受保护层级数据不得预置进公开 HTML）
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels' => ['public' => ['description' => '公共接口', 'match' => [], 'load' => 'eager']],
        ]);

        Route::get('auth/login', function () {
            return 'login';
        })->name('auth.login')->tier('public');

        // 先触达层级端点确保路由数据已被扫描
        \RouteForge\ThinkPHP\Tests\Support\Http::getJson($app, '/_forge/routes/public');

        $html = \forge_summary();

        self::assertStringNotContainsString('auth/login', $html);
        self::assertStringNotContainsString('auth.login', $html);
    }
}
