<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Fixtures\DenyAllMiddleware;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\facade\Route;

/**
 * 元信息端点与摘要端点（SPEC §3.1.5 / §3.1.6）。
 */
class EndpointTest extends TestCase
{
    private const LEVELS = [
        'public' => ['description' => '公共接口', 'match' => [], 'load' => 'eager'],
        'manage' => ['description' => '管理接口', 'match' => [], 'load' => 'lazy'],
    ];

    public function testSummaryPayloadStructure(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'     => self::LEVELS,
            'url_prefix' => 'https://api.example.com/v1',
            'cache_ttl'  => 3600,
        ]);

        Route::get('auth/login', function () {
            return 'login';
        })->name('auth.login')->tier('public');

        $payload = Http::getJson($app, '/_forge/routes');

        self::assertSame(1, $payload['schemeVersion']);
        self::assertArrayHasKey('public', $payload['levels']);
        self::assertArrayHasKey('manage', $payload['levels']);
        self::assertArrayHasKey('unassigned', $payload['levels']);
        self::assertSame('eager', $payload['levels']['public']['load']);
        self::assertSame(1, $payload['levels']['public']['route_count']);
        self::assertSame('/_forge/routes/public', $payload['levels']['public']['route']['uri']);

        self::assertSame([
            'strict_mode'     => false,
            'endpoint_prefix' => '/_forge/routes',
            'url_prefix'      => 'https://api.example.com/v1',
            'cache_ttl'       => 3600,
        ], $payload['config']);
    }

    public function testUnknownLevelReturns404WithRfBe002(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        $response = Http::get($app, '/_forge/routes/nope');
        $payload = json_decode($response->getContent(), true);

        self::assertSame(404, $response->getCode());
        self::assertSame('RF_BE_002', $payload['error']['code']);
        self::assertSame('nope', $payload['error']['level']);
    }

    public function testEmptyLevelRoutesSerializedAsObject(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        $content = (string) Http::get($app, '/_forge/routes/manage')->getContent();

        // 空层级 routes 序列化为 {}（按路由名索引的对象契约，非 []）：
        // 在原始 content 上断言——json_decode(..., true) 会把 {} 与 [] 抹平
        self::assertStringContainsString('"routes":{}', $content);
    }

    public function testUriTemplateAndParameterMeta(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::get('users/<id>', function () {
            return 'user';
        })->name('users.show')->tier('public')->default(['id' => '1']);

        Route::get('posts/<page?>', function () {
            return 'posts';
        })->name('posts.index')->tier('public');

        Route::put('files/<path>', function () {
            return 'file';
        })->name('files.replace')->tier('public');

        $payload = Http::getJson($app, '/_forge/routes/public');

        $show = $payload['routes']['users.show'];
        self::assertSame('users/{id}', $show['uri']);
        self::assertSame(['id'], $show['parameters']);
        self::assertSame(['id' => '1'], $show['parameter_defaults']);

        // 可选参数：{page?}（对齐 Laravel 可选参数 URI 契约）
        $index = $payload['routes']['posts.index'];
        self::assertSame('posts/{page?}', $index['uri']);
        self::assertSame(['page'], $index['parameters']);

        $replace = $payload['routes']['files.replace'];
        self::assertSame(['PUT'], $replace['methods']);
    }

    public function testAnyMethodExpandsToFullSet(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::any('hook', function () {
            return 'hook';
        })->name('hook.receive')->tier('public');

        $payload = Http::getJson($app, '/_forge/routes/public');

        self::assertSame(
            ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            $payload['routes']['hook.receive']['methods'],
        );
    }

    public function testGetRouteMethodsIncludeHead(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        Route::get('a', function () {
            return 'a';
        })->name('a.index')->tier('public');

        Route::rule('b', function () {
            return 'b';
        }, 'get|post')->name('b.multi')->tier('public');

        $payload = Http::getJson($app, '/_forge/routes/public');

        self::assertSame(['GET', 'HEAD'], $payload['routes']['a.index']['methods']);
        self::assertSame(['GET', 'HEAD', 'POST'], $payload['routes']['b.multi']['methods']);
    }

    public function testLevelEndpointMiddlewareProtection(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels' => [
                'manage' => [
                    'description'         => '管理接口',
                    'match'               => [],
                    'load'                => 'lazy',
                    'endpoint_middleware' => [DenyAllMiddleware::class],
                ],
            ],
        ]);

        $response = Http::get($app, '/_forge/routes/manage');

        self::assertSame(401, $response->getCode(), substr((string) $response->getContent(), 0, 400));
        self::assertStringNotContainsString('route_count', (string) $response->getContent());
    }

    /**
     * 单值字符串写法（与 think 的 ->middleware() 同形）必须与单元素数组等价：
     * 修复前的裸值 is_array() 守卫会静默跳过注册，端点裸奔且不报错。
     */
    public function testLevelEndpointMiddlewareProtectionWithSingleString(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels' => [
                'manage' => [
                    'description'         => '管理接口',
                    'match'               => [],
                    'load'                => 'lazy',
                    'endpoint_middleware' => DenyAllMiddleware::class,
                ],
            ],
        ]);

        $response = Http::get($app, '/_forge/routes/manage');

        self::assertSame(401, $response->getCode(), substr((string) $response->getContent(), 0, 400));
        self::assertStringNotContainsString('route_count', (string) $response->getContent());
    }

    public function testSummaryEndpointMiddlewareProtection(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'              => self::LEVELS,
            'endpoint_middleware' => [DenyAllMiddleware::class],
        ]);

        $response = Http::get($app, '/_forge/routes');

        self::assertSame(401, $response->getCode());
    }

    /**
     * 摘要侧同样接受单值字符串：此处此前已有 (array) 归一，本用例锁定该口径不被回退。
     */
    public function testSummaryEndpointMiddlewareProtectionWithSingleString(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'              => self::LEVELS,
            'endpoint_middleware' => DenyAllMiddleware::class,
        ]);

        $response = Http::get($app, '/_forge/routes');

        self::assertSame(401, $response->getCode());
    }

    public function testCustomEndpointPrefixRegisteredAndNormalized(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'          => self::LEVELS,
            'endpoint_prefix' => 'forge/routes/',
        ]);

        Route::get('auth/login', function () {
            return 'login';
        })->name('auth.login')->tier('public');

        $payload = Http::getJson($app, '/forge/routes');

        // 摘要下发的 endpoint_prefix 与实际注册路径使用同一规范化
        self::assertSame('/forge/routes', $payload['config']['endpoint_prefix']);
        self::assertArrayHasKey('auth.login', (array) Http::getJson($app, '/forge/routes/public')['routes']);
    }
}
