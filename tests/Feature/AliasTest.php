<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\facade\Route;

/**
 * 路由别名（SPEC §3.1.7）：->forgeAlias() 宏通道（经 think Rule::__call 落 option）
 * 与 config aliases 通道的合并、冲突与悬空语义。
 */
class AliasTest extends TestCase
{
    private const LEVELS = [
        'manage' => ['description' => '管理接口', 'match' => [], 'load' => 'lazy'],
    ];

    public function testForgeAliasMacroSingleAndMultiple(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        // 单参调用：__call → option['forgeAlias'] = '旧名'
        Route::get('members', function () {
            return 'members';
        })->name('admin.members.index')->tier('manage')
            ->forgeAlias('admin.users.index');

        // 多参调用：__call → option['forgeAlias'] = [['旧1','旧2'], ...]
        Route::get('orders', function () {
            return 'orders';
        })->name('manage.orders.index')->tier('manage')
            ->forgeAlias('client.orders.list', 'client.orders.old');

        $payload = Http::getJson($app, '/_forge/routes/manage');
        $routes = (array) $payload['routes'];

        // 别名条目与目标路由元信息完全一致（纯复制）
        foreach (['admin.users.index' => 'admin.members.index', 'client.orders.list' => 'manage.orders.index', 'client.orders.old' => 'manage.orders.index'] as $alias => $target) {
            self::assertArrayHasKey($alias, $routes, "alias {$alias} missing");
            self::assertEquals($routes[$target], $routes[$alias]);
        }

        self::assertCount(5, $routes); // 2 真实路由 + 3 别名
    }

    public function testConfigAliases(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'  => self::LEVELS,
            'aliases' => ['old.name' => 'new.name'],
        ]);

        Route::get('new', function () {
            return 'new';
        })->name('new.name')->tier('manage');

        $routes = (array) Http::getJson($app, '/_forge/routes/manage')['routes'];

        self::assertArrayHasKey('old.name', $routes);
        self::assertEquals($routes['new.name'], $routes['old.name']);
    }

    public function testMacroAliasWinsOverConfigAlias(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'  => self::LEVELS,
            // 同一别名在 config 指向 new.name，在宏指向 new.name2 → 宏优先
            'aliases' => ['stable.name' => 'new.name'],
        ]);

        Route::get('new', function () {
            return 'new';
        })->name('new.name')->tier('manage');

        Route::get('new2', function () {
            return 'new2';
        })->name('new.name2')->tier('manage');

        Route::get('new3', function () {
            return 'new3';
        })->name('new.name3')->tier('manage')->forgeAlias('stable.name');

        $routes = (array) Http::getJson($app, '/_forge/routes/manage')['routes'];

        self::assertEquals($routes['new.name3'], $routes['stable.name']);
    }

    public function testCollisionAliasIgnored(): void
    {
        // 别名与真实路由名撞车：真实路由优先，别名声明被忽略
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'  => self::LEVELS,
            'aliases' => ['dup.name' => 'new.name'],
        ]);

        Route::get('dup', function () {
            return 'dup';
        })->name('dup.name')->tier('manage');

        Route::get('new', function () {
            return 'new';
        })->name('new.name')->tier('manage');

        $routes = (array) Http::getJson($app, '/_forge/routes/manage')['routes'];

        // dup.name 是真实路由 → 条目为其自身元信息（别名被忽略）
        self::assertSame('dup', $routes['dup.name']['uri']);
    }

    public function testDanglingAliasThrowsRfBe008(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'  => self::LEVELS,
            'aliases' => ['ghost.name' => 'missing.target'],
        ]);

        Route::get('real', function () {
            return 'real';
        })->name('real.name')->tier('manage');

        $response = Http::get($app, '/_forge/routes/manage');
        $payload = json_decode($response->getContent(), true);

        self::assertSame(500, $response->getCode());
        self::assertSame('RF_BE_008', $payload['error']['code']);
    }

    public function testSummaryRouteCountIncludesAliases(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'  => self::LEVELS,
            'aliases' => ['old.name' => 'new.name'],
        ]);

        Route::get('new', function () {
            return 'new';
        })->name('new.name')->tier('manage');

        $payload = Http::getJson($app, '/_forge/routes');

        // 摘要 route_count 计入别名，与层级端点 routes 键数量一致
        self::assertSame(2, $payload['levels']['manage']['route_count']);
    }
}
