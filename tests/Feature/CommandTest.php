<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use think\App;
use think\console\Input;
use think\console\Output;
use think\facade\Route;

/**
 * think console 命令（SPEC §3.2）：route:forge:list / types / clear。
 */
class CommandTest extends TestCase
{
    /**
     * 跑命令并返回 [exitCode, buffer 内容]。
     *
     * @return array{0:int,1:string}
     */
    private function runCommand(App $app, string $name, array $args = []): array
    {
        $input = new Input(array_merge([$name], $args));
        $output = new Output('buffer');

        $exit = $app->console->find($name)->run($input, $output);

        return [$exit, $output->fetch()];
    }

    private function makeApp(array $overrides = []): \think\App
    {
        $app = AppFactory::create(debug: true, forgeOverrides: array_merge([
            'levels' => [
                'public' => ['description' => '公共接口', 'match' => [], 'load' => 'eager'],
                'manage' => ['description' => '管理接口', 'match' => [], 'load' => 'lazy'],
            ],
        ], $overrides));

        Route::get('auth/login', function () {
            return 'login';
        })->name('auth.login')->tier('public');

        Route::get('manage/users', function () {
            return 'users';
        })->name('manage.users.index')->tier('manage');

        // 有 tier 无 name 的路由：warning 信号
        Route::get('manage/logs', function () {
            return 'logs';
        })->tier('manage');

        return $app;
    }

    public function testListJsonPayloadContract(): void
    {
        [$exit, $content] = $this->runCommand($this->makeApp(), 'route:forge:list', ['--json']);
        $payload = json_decode($content, true);

        self::assertSame(0, $exit);
        self::assertSame(['public', 'manage', 'unassigned'], $payload['levels']);
        self::assertSame(null, $payload['filter']);
        self::assertSame(2, $payload['count']);
        // tier_counts 过滤前统计只计命名路由；「有 tier 无 name」的路由（manage/logs）
        // 经 warnings 提示，不计入任何层级计数
        self::assertSame(['public' => 1, 'manage' => 1, 'unassigned' => 0], $payload['tier_counts']);
        // JSON 契约内含 warnings（与 Laravel 版一致），供 CI/脚本检测配置问题
        self::assertNotEmpty($payload['warnings']);

        $rows = array_column($payload['routes'], null, 'name');
        self::assertSame('auth/login', $rows['auth.login']['uri']);
        self::assertSame('public', $rows['auth.login']['level']);
        self::assertSame(['GET'], $rows['auth.login']['methods']);
        self::assertSame('manage', $rows['manage.users.index']['level']);
        self::assertNull($rows['auth.login']['alias_of']);
    }

    public function testListLevelFilterAndUnknownLevel(): void
    {
        $app = $this->makeApp();

        [$exit, $content] = $this->runCommand($app, 'route:forge:list', ['--level=public', '--json']);
        $payload = json_decode($content, true);

        self::assertSame(0, $exit);
        self::assertSame(['level' => 'public'], $payload['filter']);
        self::assertSame(1, $payload['count']);

        [$exit, $content] = $this->runCommand($app, 'route:forge:list', ['--level=nope']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Unknown level: nope', $content);
    }

    public function testListUnassignedFilter(): void
    {
        $app = $this->makeApp();

        Route::get('misc', function () {
            return 'misc';
        })->name('misc.ping');

        [$exit, $content] = $this->runCommand($app, 'route:forge:list', ['--unassigned', '--json']);
        $payload = json_decode($content, true);

        self::assertSame(0, $exit);
        self::assertSame(1, $payload['count']);
        self::assertSame('misc.ping', $payload['routes'][0]['name']);
        self::assertSame('unassigned', $payload['routes'][0]['level']);
    }

    public function testTypesJsonAndLevelFilter(): void
    {
        [$exit, $content] = $this->runCommand($this->makeApp(), 'route:forge:types', ['--json']);
        $payload = json_decode($content, true);

        self::assertSame(0, $exit);
        self::assertArrayHasKey('auth.login', (array) $payload['public']);
        self::assertArrayHasKey('manage.users.index', (array) $payload['manage']);
        // unassigned 层级不生成类型
        self::assertArrayNotHasKey('unassigned', (array) $payload);
    }

    public function testTypesOutWritesDtsFile(): void
    {
        $app = $this->makeApp();
        $outFile = $app->getRuntimePath() . 'forge-routes.d.ts';

        [$exit, $content] = $this->runCommand($app, 'route:forge:types', ['--out=' . $outFile]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Written to:', $content);
        $dts = (string) file_get_contents($outFile);
        self::assertStringContainsString("export type ForgeLevel = 'public' | 'manage'", $dts);
        self::assertStringContainsString('端点: /_forge/routes', $dts);
    }

    public function testClearAllAndByLevel(): void
    {
        $app = AppFactory::create(debug: false, forgeOverrides: [
            'levels'    => [
                'public' => ['description' => 'p', 'match' => [], 'load' => 'lazy'],
            ],
            'cache_ttl' => 3600,
        ]);

        Route::get('a', function () {
            return 'a';
        })->name('a.index')->tier('public');

        // 填充缓存
        \RouteForge\ThinkPHP\Tests\Support\Http::getJson($app, '/_forge/routes/public');
        \RouteForge\ThinkPHP\Tests\Support\Http::getJson($app, '/_forge/routes');
        $store = $app->cache->store();
        self::assertNotNull($store->get('route-forge:summary'));

        [$exit, $content] = $this->runCommand($app, 'route:forge:clear');
        self::assertSame(0, $exit);
        self::assertStringContainsString('cleared successfully', $content);
        self::assertNull($store->get('route-forge:summary'));
        self::assertNull($store->get('route-forge:public'));

        // --level 失效指定层级并同步失效摘要
        \RouteForge\ThinkPHP\Tests\Support\Http::getJson($app, '/_forge/routes/public');
        \RouteForge\ThinkPHP\Tests\Support\Http::getJson($app, '/_forge/routes');

        [$exit, $content] = $this->runCommand($app, 'route:forge:clear', ['--level=public']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('cleared for level: public', $content);
        self::assertNull($store->get('route-forge:public'));
        self::assertNull($store->get('route-forge:summary'));
    }

    public function testClearUnknownLevel(): void
    {
        [$exit, $content] = $this->runCommand($this->makeApp(), 'route:forge:clear', ['--level=nope']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Unknown level: nope', $content);
    }
}
