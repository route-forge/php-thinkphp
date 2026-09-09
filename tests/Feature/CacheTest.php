<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\facade\Route;

/**
 * 缓存语义（SPEC §3.1.5）：TTL 写入、命中、debug 旁路、clear 命令失效。
 */
class CacheTest extends TestCase
{
    public function testCacheHitServesStaleScanWhenTtlConfigured(): void
    {
        // debug=false（非开发模式）+ cache_ttl>0 → 端点结果进缓存，
        // 缓存有效期内路由变更不反映到端点（直到失效/清除）
        $app = AppFactory::create(debug: false, forgeOverrides: [
            'levels'    => ['public' => ['description' => 'p', 'match' => [], 'load' => 'lazy']],
            'cache_ttl' => 3600,
        ]);

        Route::get('first', function () {
            return 'first';
        })->name('first.index')->tier('public');

        $before = Http::getJson($app, '/_forge/routes/public');
        self::assertArrayHasKey('first.index', (array) $before['routes']);

        // 缓存填充后追加新路由：同一 TTL 内端点仍返回旧扫描结果
        Route::get('second', function () {
            return 'second';
        })->name('second.index')->tier('public');

        $after = Http::getJson($app, '/_forge/routes/public');
        self::assertArrayNotHasKey('second.index', (array) $after['routes']);
    }

    public function testDebugModeBypassesCache(): void
    {
        // debug=true（开发模式）跳过缓存读写：路由变更即时生效
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'    => ['public' => ['description' => 'p', 'match' => [], 'load' => 'lazy']],
            'cache_ttl' => 3600,
        ]);

        Route::get('first', function () {
            return 'first';
        })->name('first.index')->tier('public');

        Http::getJson($app, '/_forge/routes/public');

        Route::get('second', function () {
            return 'second';
        })->name('second.index')->tier('public');

        $payload = Http::getJson($app, '/_forge/routes/public');
        self::assertArrayHasKey('second.index', (array) $payload['routes']);
    }

    public function testCacheTtlNullMeansNoCaching(): void
    {
        $app = AppFactory::create(debug: false, forgeOverrides: [
            'levels'    => ['public' => ['description' => 'p', 'match' => [], 'load' => 'lazy']],
            'cache_ttl' => null,
        ]);

        Route::get('first', function () {
            return 'first';
        })->name('first.index')->tier('public');

        Http::getJson($app, '/_forge/routes/public');

        Route::get('second', function () {
            return 'second';
        })->name('second.index')->tier('public');

        $payload = Http::getJson($app, '/_forge/routes/public');
        self::assertArrayHasKey('second.index', (array) $payload['routes']);
    }

    public function testCacheKeysWrittenToFileStore(): void
    {
        $app = AppFactory::create(debug: false, forgeOverrides: [
            'levels'    => ['public' => ['description' => 'p', 'match' => [], 'load' => 'lazy']],
            'cache_ttl' => 3600,
        ]);

        Route::get('a', function () {
            return 'a';
        })->name('a.index')->tier('public');

        Http::getJson($app, '/_forge/routes/public');
        Http::getJson($app, '/_forge/routes');

        $store = $app->cache->store();

        // 层级缓存 + 摘要缓存 + keys 索引三键齐备
        self::assertNotNull($store->get('route-forge:public'));
        self::assertNotNull($store->get('route-forge:summary'));
        self::assertContains('route-forge:public', (array) $store->get('route-forge:_keys'));
    }
}
