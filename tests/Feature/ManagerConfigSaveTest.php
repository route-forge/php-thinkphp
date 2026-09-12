<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Fixtures\DenyAllMiddleware;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\App;
use think\facade\Config;
use think\Response;

/**
 * 管理器写盘：PUT /_forge/manager/api/config 的重写、备份、透传与门禁。
 */
class ManagerConfigSaveTest extends TestCase
{
    private const LOCAL_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];

    private const SUBMIT_LEVELS = [
        'public' => ['description' => '公共接口', 'match' => ['prefix' => ['auth']], 'load' => 'eager'],
        'admin'  => ['description' => '后台接口', 'match' => ['middleware' => ['auth']], 'load' => 'lazy'],
    ];

    private const GLOBAL = [
        'endpoint_prefix' => '/_forge/routes',
        'url_prefix'      => null,
        'cache_ttl'       => 60,
        'cache_driver'    => null,
        'strict_mode'     => false,
        'scheme_version'  => 1,
    ];

    /**
     * @param array<string,mixed>  $payload
     * @param array<string,string> $server
     */
    private function put(App $app, array $payload, array $server = self::LOCAL_SERVER): Response
    {
        return Http::putJson($app, '/_forge/manager/api/config', $payload, $server);
    }

    private function configFile(App $app): string
    {
        return $app->getConfigPath() . 'forge.php';
    }

    /**
     * @return array<string,mixed>
     */
    private function readConfigFile(App $app): array
    {
        $loaded = include $this->configFile($app);

        self::assertIsArray($loaded, '重写后的 config/forge.php 必须是可回读的合法 PHP');

        return $loaded;
    }

    public function testSaveRewritesConfigInThinkStyleWithBackup(): void
    {
        $app    = AppFactory::create(debug: true, forgeOverrides: ['levels' => ['public' => self::SUBMIT_LEVELS['public']]]);
        $before = (string) file_get_contents($this->configFile($app));

        $response = $this->put($app, ['levels' => self::SUBMIT_LEVELS, 'global' => self::GLOBAL]);

        self::assertSame(200, $response->getCode(), (string) $response->getContent());

        $payload = json_decode((string) $response->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertTrue($payload['backed_up'], '覆盖既有配置必须先备份');
        self::assertStringNotContainsString('.bak-', (string) $response->getContent(), '只回是否备份，不回绝对路径');

        $content = (string) file_get_contents($this->configFile($app));
        // think 风味外壳：单行注释，无 Laravel 桶形注释块
        self::assertStringContainsString('// 层级定义表', $content);
        self::assertStringNotContainsString('|---', $content);

        $loaded = $this->readConfigFile($app);
        self::assertSame('后台接口', $loaded['levels']['admin']['description']);
        self::assertSame(['auth'], $loaded['levels']['public']['match']['prefix']);
        self::assertSame(60, $loaded['cache_ttl']);

        // 备份里就是改动前的原文，可回退
        $backups = glob($this->configFile($app) . '.bak-*');
        self::assertCount(1, $backups);
        self::assertSame($before, (string) file_get_contents($backups[0]));
    }

    /**
     * 不在表单里编辑的三项必须在一次保存后原样存活。
     */
    public function testPreservedKeysSurviveSave(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'              => ['public' => self::SUBMIT_LEVELS['public']],
            'endpoint_middleware' => [DenyAllMiddleware::class],
            'aliases'             => ['legacy.login' => 'auth.login'],
            // 单值字符串写法：保存后固化为等价的单元素数组
            'manager_allowed_ips' => '10.0.0.5',
        ]);

        // 该用例把白名单配成了 10.0.0.5，来源必须用它，否则被守卫按预期拦掉
        $response = $this->put(
            $app,
            ['levels' => self::SUBMIT_LEVELS, 'global' => self::GLOBAL],
            ['REMOTE_ADDR' => '10.0.0.5']
        );

        self::assertSame(200, $response->getCode(), (string) $response->getContent());

        $loaded = $this->readConfigFile($app);
        self::assertSame([DenyAllMiddleware::class], $loaded['endpoint_middleware']);
        self::assertSame(['legacy.login' => 'auth.login'], $loaded['aliases']);
        self::assertSame(['10.0.0.5'], $loaded['manager_allowed_ips']);
    }

    /**
     * 页面里把 match.prefix / match.middleware 写成单值字符串（与 think 的
     * ->middleware() 同形的直觉写法）必须能保存：common 1.1.2 起生成侧按 (array)
     * 归一，落盘为等价的单元素数组。
     *
     * 本用例就是 composer 下限 ^1.1.2 的实际守卫——装在 1.1.1 会在
     * exportInlineArray(array) 的类型声明上 TypeError，表现为保存返回 500
     * 「配置写入失败」，而真正原因藏在日志里。
     */
    public function testSingleStringMatchValuesArePersistedAsArrays(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => []]);

        $levels = [
            'admin' => [
                'description' => '后台接口',
                'match'       => ['prefix' => 'admin', 'middleware' => 'auth'],
                'load'        => 'lazy',
            ],
        ];

        $response = $this->put($app, ['levels' => $levels, 'global' => self::GLOBAL]);

        self::assertSame(200, $response->getCode(), (string) $response->getContent());

        $loaded = $this->readConfigFile($app);
        self::assertSame(['admin'], $loaded['levels']['admin']['match']['prefix']);
        self::assertSame(['auth'], $loaded['levels']['admin']['match']['middleware']);
    }

    /**
     * classifier 是闭包，无法序列化进配置文件——必须拒存而不是抹平成 null。
     */
    public function testSaveIsRefusedWhenClassifierIsConfigured(): void
    {
        $app = AppFactory::create(
            debug: true,
            forgeOverrides: ['levels' => ['public' => self::SUBMIT_LEVELS['public']]],
            classifier: static fn ($route): ?string => 'public'
        );
        $before = (string) file_get_contents($this->configFile($app));

        self::assertNotNull(Config::get('forge.classifier'), '前置条件：classifier 已在配置中');

        $response = $this->put($app, ['levels' => self::SUBMIT_LEVELS, 'global' => self::GLOBAL]);

        self::assertSame(422, $response->getCode());
        self::assertStringContainsString('classifier', (string) $response->getContent());
        self::assertSame($before, (string) file_get_contents($this->configFile($app)), '拒存时不得动过配置文件');
    }

    public function testNonArrayPayloadsAreRejectedWithoutWriting(): void
    {
        $cases = [
            'levels 非数组'  => ['levels' => 'oops', 'global' => self::GLOBAL],
            'levels 缺失'    => ['global' => self::GLOBAL],
            'global 非数组'  => ['levels' => self::SUBMIT_LEVELS, 'global' => 'oops'],
            'global 为 null' => ['levels' => self::SUBMIT_LEVELS, 'global' => null],
        ];

        foreach ($cases as $label => $payload) {
            $app    = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::SUBMIT_LEVELS]);
            $before = (string) file_get_contents($this->configFile($app));

            $response = $this->put($app, $payload);

            self::assertSame(422, $response->getCode(), $label);
            self::assertSame($before, (string) file_get_contents($this->configFile($app)), $label . '：非法入参不得写盘');
        }
    }

    /**
     * 写盘路由与页面、只读 API 共用同一道守卫。
     */
    public function testForeignSourceCannotWriteConfig(): void
    {
        $app    = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::SUBMIT_LEVELS]);
        $before = (string) file_get_contents($this->configFile($app));

        $response = $this->put($app, ['levels' => [], 'global' => self::GLOBAL], ['REMOTE_ADDR' => '10.0.0.5']);

        self::assertSame(403, $response->getCode());
        self::assertSame($before, (string) file_get_contents($this->configFile($app)));
    }

    /**
     * 第一层门禁同样覆盖写盘路由：非 debug 环境该 URI 不存在。
     */
    public function testWriteEndpointNotRegisteredOutsideDebug(): void
    {
        $app    = AppFactory::create(debug: false, forgeOverrides: ['levels' => self::SUBMIT_LEVELS]);
        $before = (string) file_get_contents($this->configFile($app));

        $response = $this->put(
            $app,
            ['levels' => [], 'global' => self::GLOBAL],
            self::LOCAL_SERVER + ['HTTP_ACCEPT' => 'application/json']
        );

        self::assertSame(404, $response->getCode());
        self::assertSame($before, (string) file_get_contents($this->configFile($app)));
    }
}
