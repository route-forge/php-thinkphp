<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\facade\Route;

/**
 * 层级分配（SPEC §3.1.1-§3.1.4）：显式 tier / group 透传 / config match /
 * classifier / 优先级 / unassigned 兜底 / strict_mode。
 *
 * ThinkPHP 零侵入差异：->tier() / group ->tier() 经 __call 落 option，
 * 定义期不校验层级合法性（无宏机制），扫描期由 common TierResolver 抛
 * UnknownLevelException——本套件对齐验证该行为。
 */
class TierAssignmentTest extends TestCase
{
    private const LEVELS = [
        'public' => ['description' => '公共接口', 'match' => ['prefix' => ['auth']], 'load' => 'eager'],
        'client' => ['description' => '客户端接口', 'match' => ['prefix' => ['client']], 'load' => 'lazy'],
        'manage' => ['description' => '管理接口', 'match' => ['middleware' => ['app\\middleware\\ManageAuth']], 'load' => 'lazy'],
    ];

    public function testExplicitTierWinsOverGroupTier(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::group('admin', function () {
            Route::get('users', function () {
                return 'users';
            })->name('admin.users.index');

            // 组内显式标注覆盖 group 透传（SPEC §3.1.4 优先级 1 > 2）
            Route::get('logs', function () {
                return 'logs';
            })->name('admin.logs.index')->tier('public');
        })->tier('manage');

        $payload = Http::getJson($app, '/_forge/routes/manage');
        self::assertArrayHasKey('admin.users.index', (array) $payload['routes']);

        $public = Http::getJson($app, '/_forge/routes/public');
        self::assertArrayHasKey('admin.logs.index', (array) $public['routes']);
    }

    public function testNestedGroupInnerTierOverridesOuter(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::group('outer', function () {
            Route::get('a', function () {
                return 'a';
            })->name('outer.a.index');

            Route::group('inner', function () {
                Route::get('b', function () {
                    return 'b';
                })->name('outer.inner.b.index');
            })->tier('client');
        })->tier('manage');

        $manage = Http::getJson($app, '/_forge/routes/manage');
        $client = Http::getJson($app, '/_forge/routes/client');

        self::assertArrayHasKey('outer.a.index', (array) $manage['routes']);
        self::assertArrayHasKey('outer.inner.b.index', (array) $client['routes']);
    }

    public function testConfigMatchByPrefixAndLastWins(): void
    {
        // client 与 manage 的 prefix 都命中 manage 前缀路由 → 后定义者胜（last-wins）
        $levels = self::LEVELS;
        $levels['client']['match']['prefix'] = ['manage'];
        $levels['manage']['match']['prefix'] = ['manage'];

        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => $levels]);

        Route::get('manage/orders', function () {
            return 'orders';
        })->name('manage.orders.index');

        $manage = Http::getJson($app, '/_forge/routes/manage');
        self::assertArrayHasKey('manage.orders.index', (array) $manage['routes']);

        $client = Http::getJson($app, '/_forge/routes/client');
        self::assertSame([], (array) $client['routes']);
    }

    public function testConfigMatchByMiddleware(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::get('report/daily', function () {
            return 'report';
        })->name('report.daily')->middleware('app\\middleware\\ManageAuth');

        $manage = Http::getJson($app, '/_forge/routes/manage');
        self::assertArrayHasKey('report.daily', (array) $manage['routes']);
    }

    public function testClassifierPriorityBetweenGroupAndConfigMatch(): void
    {
        // classifier 优先于 config match：auth 前缀本应命中 public（match），
        // classifier 归入 client → client 胜
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS],
            classifier: static fn (\think\route\RuleItem $r): ?string
                => str_starts_with((string) $r->getRule(), 'auth') ? 'client' : null);

        Route::get('auth/login', function () {
            return 'login';
        })->name('auth.login');

        $client = Http::getJson($app, '/_forge/routes/client');
        self::assertArrayHasKey('auth.login', (array) $client['routes']);
    }

    public function testUnmatchedRouteFallsBackToUnassigned(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::get('misc/ping', function () {
            return 'ping';
        })->name('misc.ping');

        $unassigned = Http::getJson($app, '/_forge/routes/unassigned');
        self::assertArrayHasKey('misc.ping', (array) $unassigned['routes']);
        self::assertSame('unassigned', $unassigned['level']);
    }

    public function testStrictModeThrowsRfBe009(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'      => self::LEVELS,
            'strict_mode' => true,
        ]);

        Route::get('misc/ping', function () {
            return 'ping';
        })->name('misc.ping');

        $response = Http::get($app, '/_forge/routes/unassigned');
        $payload = json_decode($response->getContent(), true);

        // 严格模式不再逐条抛 RF_BE_001：仓库取数入口做全量预扫描，聚合为 RF_BE_009 一次报全。
        self::assertSame(500, $response->getCode());
        self::assertSame('RF_BE_009', $payload['error']['code']);
        // 命名路由未归级归入 unassigned 组，message 含该路由名与「未命中任何层级」措辞。
        // 响应体只带 code/message/level（violations 结构化清单未进 HTTP 契约），故只断 message 文本。
        self::assertStringContainsString('misc.ping', $payload['error']['message']);
        self::assertStringContainsString('not matched by any level', $payload['error']['message']);
    }

    public function testExplicitTierNotInLevelsThrowsAtScan(): void
    {
        // ThinkPHP 零侵入差异：定义期不校验，扫描期抛 UnknownLevelException（RF_BE_002）
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::get('x', function () {
            return 'x';
        })->name('x.index')->tier('nope');

        $response = Http::get($app, '/_forge/routes/unassigned');
        $payload = json_decode($response->getContent(), true);

        self::assertSame(404, $response->getCode());
        self::assertSame('RF_BE_002', $payload['error']['code']);
    }

    public function testResourceRoutesAreUnnamedAndExcludedFromMeta(): void
    {
        // ThinkPHP 差异：资源路由生成的规则以「路由地址字符串」为默认标识，
        // 属未命名路由，不出现在任何元信息中（对齐「未命名路由不入元信息」契约）
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::resource('posts', 'PostController')->tier('manage');

        $manage = Http::getJson($app, '/_forge/routes/manage');
        self::assertSame([], (array) $manage['routes']);

        $unassigned = Http::getJson($app, '/_forge/routes/unassigned');
        self::assertSame([], (array) $unassigned['routes']);
    }
}
