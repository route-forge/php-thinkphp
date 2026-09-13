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

    /**
     * 铁律：debug 下注册的管理器路由（`forge.manager.*`）与元信息端点路由
     * （`forge.routes.*`）都不许出现在 list 的任何一种输出形态里——它们不带 tier，
     * 一旦泄漏，strict_mode 下命令会因包自身路由未命中层级直接失败。
     * 现有用例只靠 count 隐式兜住，这里把性质写成直白断言。
     */
    public function testForgeOwnRoutesNeverAppearInListOutput(): void
    {
        [$exit, $json] = $this->runCommand($this->makeApp(), 'route:forge:list', ['--json']);
        self::assertSame(0, $exit);
        self::assertStringNotContainsString('forge.manager', $json);
        self::assertStringNotContainsString('forge.routes', $json);

        [$exit, $table] = $this->runCommand($this->makeApp(), 'route:forge:list');
        self::assertSame(0, $exit);
        self::assertStringNotContainsString('forge.manager', $table);
        self::assertStringNotContainsString('forge.routes', $table);
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

    /**
     * table 模式整行着色优先级：unassigned 品红 > 别名黄 > 默认（对齐 Laravel 版 fg=magenta）。
     * 品红是「该配层级却没配」的肉眼信号，故指向 unassigned 的别名行也被品红覆盖，
     * 别名身份退由 Alias Of 列文字表达；已分级路由的别名黄与绿色真实名不受影响。
     * think 的 Buffer 驱动不过 Formatter，因此断言的是原始标签文本。
     */
    public function testListTableColorizesUnassignedRowsMagenta(): void
    {
        $app = $this->makeApp([
            'aliases' => [
                'legacy.misc'  => 'misc.ping',   // 目标未分级 → 品红覆盖别名黄
                'legacy.login' => 'auth.login',  // 目标已分级 → 保持别名黄
            ],
        ]);

        Route::get('misc', function () {
            return 'misc';
        })->name('misc.ping');

        [$exit, $table] = $this->runCommand($app, 'route:forge:list');
        self::assertSame(0, $exit);

        // 未分级路由整行品红（Name 与 Level 列都被包裹）
        self::assertStringContainsString('<fg=magenta>misc.ping</fg=magenta>', $table);
        self::assertStringContainsString('<fg=magenta>unassigned</fg=magenta>', $table);

        // 指向未分级路由的别名行同样是品红，且不再上别名黄；Alias Of 列文字仍在（品红不吞语义）
        self::assertStringContainsString('<fg=magenta>legacy.misc</fg=magenta>', $table);
        self::assertStringNotContainsString('<comment>legacy.misc</comment>', $table);
        self::assertStringContainsString('<fg=magenta>misc.ping</fg=magenta>', $table);

        // 已分级路由不着色，其别名行保持黄色（说明新增分支没有把黄色通道抢走）
        self::assertStringNotContainsString('<fg=magenta>auth.login</fg=magenta>', $table);
        self::assertStringNotContainsString('<fg=magenta>public</fg=magenta>', $table);
        self::assertStringContainsString('<comment>legacy.login</comment>', $table);

        // 着色只属于 table 形态：--json 产物必须是纯文本，不得混入标签
        [$exit, $json] = $this->runCommand($app, 'route:forge:list', ['--json']);
        self::assertSame(0, $exit);
        self::assertStringNotContainsString('fg=magenta', $json);
        self::assertStringNotContainsString('<comment>', $json);
        $names = array_column((array) json_decode($json, true)['routes'], 'name');
        sort($names);
        self::assertSame(['auth.login', 'legacy.login', 'legacy.misc', 'manage.users.index', 'misc.ping'], $names);
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
