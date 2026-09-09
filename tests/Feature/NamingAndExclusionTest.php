<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\facade\Route;

/**
 * 命名路由甄别与内部路由排除（ThinkPHP 特有适配面）。
 */
class NamingAndExclusionTest extends TestCase
{
    private const LEVELS = [
        'public' => ['description' => '公共接口', 'match' => [], 'load' => 'lazy'],
    ];

    public function testAddressStringIsNotAUserRouteName(): void
    {
        // think 默认把路由地址字符串当作路由标识（RuleGroup::addRule），
        // 未经显式 ->name() 的路由必须视为未命名，不入元信息
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::get('users/index', 'UserController@index')->tier('public');

        $payload = Http::getJson($app, '/_forge/routes/public');
        self::assertSame([], (array) $payload['routes']);
    }

    public function testExplicitNameIsRecognized(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::get('users/index', 'UserController@index')->name('users.index')->tier('public');

        $payload = Http::getJson($app, '/_forge/routes/public');
        self::assertArrayHasKey('users.index', (array) $payload['routes']);
    }

    public function testForgeOwnEndpointsExcludedFromMeta(): void
    {
        // forge 自身端点（forge.routes.*）永远不进元信息、不进 unassigned，
        // strict_mode 下也不会触发 RF_BE_001
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'      => self::LEVELS,
            'strict_mode' => true,
        ]);

        Route::get('auth/login', function () {
            return 'login';
        })->name('auth.login')->tier('public');

        $payload = Http::getJson($app, '/_forge/routes/public');

        foreach (array_keys((array) $payload['routes']) as $name) {
            self::assertStringStartsWith('auth', $name);
        }

        $unassigned = Http::getJson($app, '/_forge/routes/unassigned');
        self::assertSame([], (array) $unassigned['routes']);
    }

    public function testAutoRouteExcludedFromMeta(): void
    {
        // Route::auto() 注册的 __think_auto_route__（框架内部机制）经 filter 排除
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::auto()->tier('public');

        $payload = Http::getJson($app, '/_forge/routes/unassigned');
        self::assertSame([], (array) $unassigned = $payload['routes']);
    }
}
