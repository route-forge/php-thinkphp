<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\facade\Route;

/**
 * 管理器只读 API：GET /_forge/manager/api/routes 与 /api/config。
 */
class ForgeManagerApiTest extends TestCase
{
    private const LEVELS = [
        'public' => ['description' => '公共接口', 'match' => [], 'load' => 'eager'],
        'manage' => ['description' => '管理接口', 'match' => [], 'load' => 'lazy'],
    ];

    /**
     * 白名单默认仅放行本机回环；不带 REMOTE_ADDR 时 think 的 ip() 返回空串会被 403。
     */
    private const LOCAL = ['REMOTE_ADDR' => '127.0.0.1'];

    public function testRoutesApiPayloadShape(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::get('auth/login', function () {
            return 'login';
        })->name('auth.login')->tier('public');

        $payload = Http::getJson($app, '/_forge/manager/api/routes', self::LOCAL);

        self::assertArrayHasKey('routes', $payload);
        self::assertArrayHasKey('tiers', $payload);

        $rows = [];
        foreach ($payload['routes'] as $row) {
            $rows[$row['name']] = $row;
        }

        self::assertArrayHasKey('auth.login', $rows);
        // getAllRoutesWithTiers 与元信息端点同口径：剔除 HEAD
        self::assertSame(['GET'], $rows['auth.login']['methods']);
        self::assertSame('public', $rows['auth.login']['tier']);
        self::assertSame(1, $payload['tiers']['public']);
    }

    /**
     * 铁律回归：管理器自身路由（forge.manager.*）与元信息端点路由（forge.routes.*）
     * 不得出现在管理器 API 里——泄漏会让 strict_mode 因包自身路由未命中层级必然 500。
     */
    public function testForgeOwnRoutesAreExcludedFromRoutesApi(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'      => self::LEVELS,
            'strict_mode' => true,
        ]);

        Route::get('auth/login', function () {
            return 'login';
        })->name('auth.login')->tier('public');

        $response = Http::get($app, '/_forge/manager/api/routes', ['REMOTE_ADDR' => '127.0.0.1']);

        self::assertSame(200, $response->getCode(), substr((string) $response->getContent(), 0, 400));

        $payload = json_decode((string) $response->getContent(), true);
        foreach ($payload['routes'] as $row) {
            self::assertFalse(
                str_starts_with($row['name'], 'forge.'),
                '管理器自身路由泄漏进 API: ' . $row['name']
            );
        }
    }

    /**
     * strict_mode=true 且业务路由全部命中层级时，只读 API 必须正常工作。
     */
    public function testRoutesApiSucceedsUnderStrictMode(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'      => self::LEVELS,
            'strict_mode' => true,
        ]);

        Route::get('manage/users', function () {
            return 'users';
        })->name('manage.users')->tier('manage');

        $payload = Http::getJson($app, '/_forge/manager/api/routes', self::LOCAL);

        $rows = [];
        foreach ($payload['routes'] as $row) {
            $rows[$row['name']] = $row;
        }

        self::assertArrayHasKey('manage.users', $rows);
        self::assertSame('manage', $rows['manage.users']['tier']);
    }

    public function testRoutesApiExpandsAliasesWithAliasOfMarker(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'  => self::LEVELS,
            'aliases' => ['legacy.login' => 'auth.login'],
        ]);

        Route::get('auth/login', function () {
            return 'login';
        })->name('auth.login')->tier('public');

        $payload = Http::getJson($app, '/_forge/manager/api/routes', self::LOCAL);

        $rows = [];
        foreach ($payload['routes'] as $row) {
            $rows[$row['name']] = $row;
        }

        self::assertArrayHasKey('legacy.login', $rows);
        self::assertSame('auth.login', $rows['legacy.login']['alias_of']);
        // 别名跟随目标层级
        self::assertSame('public', $rows['legacy.login']['tier']);
        self::assertArrayNotHasKey('alias_of', $rows['auth.login']);
    }

    public function testConfigApiExposesLevelsAndEffectiveGlobals(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'  => self::LEVELS,
            'aliases' => ['old.name' => 'auth.login'],
        ]);

        $payload = Http::getJson($app, '/_forge/manager/api/config', self::LOCAL);

        self::assertSame(self::LEVELS, $payload['levels']);

        $global = $payload['global'];
        self::assertSame('/_forge/routes', $global['endpoint_prefix']);
        self::assertNull($global['url_prefix']);
        self::assertSame(3600, $global['cache_ttl']);
        self::assertNull($global['cache_driver']);
        self::assertFalse($global['strict_mode']);
        self::assertSame(1, $global['scheme_version']);
        self::assertSame(['old.name' => 'auth.login'], $global['aliases']);
        // 键缺失时展示的就是守卫真正生效的那份默认白名单（两个读取点同源）
        self::assertSame(['127.0.0.1', '::1'], $global['manager_allowed_ips']);
    }

    /**
     * 归一化红线的展示侧：单值字符串写入后，config API 与守卫看到的是同一份归一结果。
     */
    public function testConfigApiNormalizesSingleStringWhitelist(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'             => self::LEVELS,
            'manager_allowed_ips' => '10.0.0.5',
        ]);

        $payload = Http::getJson($app, '/_forge/manager/api/config', ['REMOTE_ADDR' => '10.0.0.5']);

        self::assertSame(['10.0.0.5'], $payload['global']['manager_allowed_ips']);
    }

    /**
     * 第一层防护：非 debug 环境根本不注册管理器路由，连探测面都不存在。
     */
    public function testManagerRoutesNotRegisteredOutsideDebug(): void
    {
        $app = AppFactory::create(debug: false, forgeOverrides: ['levels' => self::LEVELS]);

        // Accept: application/json 让 think 走 JSON 异常分支渲染 404：其 HTML 异常模板
        // 在 PHP 8.5 下会触发 htmlentities(null) 弃用告警（框架内部问题，不该混进测试结果）
        $response = Http::get($app, '/_forge/manager/api/config', self::LOCAL + ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(404, $response->getCode());
    }

    /**
     * 元信息端点在非 debug 下照常注册（门禁只针对管理器，不影响数据端点）。
     */
    public function testMetadataEndpointsStillRegisteredOutsideDebug(): void
    {
        $app = AppFactory::create(debug: false, forgeOverrides: ['levels' => self::LEVELS]);

        Route::get('auth/login', function () {
            return 'login';
        })->name('auth.login')->tier('public');

        $response = Http::get($app, '/_forge/routes/public');

        self::assertSame(200, $response->getCode());
    }
}
