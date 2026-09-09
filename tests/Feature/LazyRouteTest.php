<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\facade\Route;

/**
 * url_lazy_route（路由延迟解析）fail-fast：v1 明确不支持。
 */
class LazyRouteTest extends TestCase
{
    public function testLazyRouteConfigFailsFast(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels' => ['public' => ['description' => 'p', 'match' => [], 'load' => 'lazy']],
        ], lazyRoute: true);

        Route::get('a', function () {
            return 'a';
        })->name('a.index')->tier('public');

        // 端点扫描直接抛清晰异常（渲染为 500 错误页），而非返回失真的空/缺数据
        $response = Http::get($app, '/_forge/routes/public');

        self::assertSame(500, $response->getCode());
        self::assertStringContainsString('url_lazy_route', (string) $response->getContent());
    }
}
