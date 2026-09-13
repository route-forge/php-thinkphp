<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Support\RouteFileLoader;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use think\App;
use think\console\Input;
use think\console\Output;

/**
 * 命令必须看得见「来自路由文件」的路由（1.1.0 缺陷的回归面）。
 *
 * 与 CommandTest 的分工：那边的业务路由由测试代码直接 `Route::get()` 注册，
 * 走的是「内存注册」这条路——正因如此 129 例全绿也遮住了命令侧收集不到
 * route/*.php 的事实。这里的路由**只来自路由文件**，把 think 真实加载路由文件
 * 那条路径纳入覆盖。
 */
class ConsoleRouteFileLoadingTest extends TestCase
{
    private const ROUTES = <<<'PHP'
<?php
use think\facade\Route;

Route::get('auth/login', function () {
    return 'login';
})->name('auth.login')->tier('public');

Route::get('manage/users', function () {
    return 'users';
})->name('manage.users')->tier('manage');

// 有 tier 无 name：与示例项目的 manage/logs 同形，应给 warning 而不进任何计数
Route::get('manage/logs', function () {
    return 'logs';
})->tier('manage');
PHP;

    /**
     * @return array{0:int,1:string}
     */
    private function runCommand(App $app, string $name, array $args = []): array
    {
        $input = new Input(array_merge([$name], $args));
        $output = new Output('buffer');

        $exit = $app->console->find($name)->run($input, $output);

        return [$exit, $output->fetch()];
    }

    /**
     * @param array<string,string> $routeFiles
     */
    private function app(array $forgeOverrides = [], array $routeFiles = ['app.php' => self::ROUTES]): App
    {
        return AppFactory::create(debug: true, forgeOverrides: array_merge([
            'levels' => [
                'public' => ['description' => '公共接口', 'match' => [], 'load' => 'eager'],
                'manage' => ['description' => '管理接口', 'match' => [], 'load' => 'lazy'],
            ],
        ], $forgeOverrides), routeFiles: $routeFiles);
    }

    public function testListSeesRoutesRegisteredOnlyInRouteFile(): void
    {
        [$exit, $content] = $this->runCommand($this->app(), 'route:forge:list', ['--json']);
        $payload = json_decode($content, true);

        self::assertSame(0, $exit);
        // 有 tier 无 name 的那条不进计数，故 routes/tier_counts 只含两条命名路由
        self::assertSame(2, $payload['count']);
        self::assertSame(['public' => 1, 'manage' => 1, 'unassigned' => 0], $payload['tier_counts']);

        $byName = array_column($payload['routes'], null, 'name');
        self::assertArrayHasKey('auth.login', $byName);
        self::assertArrayHasKey('manage.users', $byName);
        self::assertSame('public', $byName['auth.login']['level']);
        self::assertSame('auth/login', $byName['auth.login']['uri']);
        self::assertSame(['GET'], $byName['auth.login']['methods']);

        // 未命名但有 tier 的路由：警告照给（口径与 HTTP 侧一致）
        self::assertSame(
            1,
            count(array_filter($payload['warnings'], static fn (string $w): bool => str_contains($w, 'manage/logs'))),
            '路由文件里「有 tier 无 name」应触发 warning',
        );
    }

    public function testAliasPointingAtRouteFileRouteResolves(): void
    {
        // 1.1.0 的现场：别名目标只存在于 route/app.php，收集结果里查不到 → RF_BE_008 退出
        $app = $this->app(['aliases' => ['legacy.login' => 'auth.login']]);

        [$exit, $content] = $this->runCommand($app, 'route:forge:list', ['--json']);
        $payload = json_decode($content, true);

        self::assertSame(0, $exit, '别名目标在路由文件里也必须解析成功，不得抛 RF_BE_008');
        self::assertSame(3, $payload['count'], '两条真实路由 + 一条别名条目');

        $alias = array_values(array_filter(
            $payload['routes'],
            static fn (array $r): bool => ($r['name'] ?? '') === 'legacy.login',
        ));
        self::assertCount(1, $alias);
        self::assertSame('auth.login', $alias[0]['alias_of']);
        self::assertSame('public', $alias[0]['level'], '别名跟随目标层级');
    }

    public function testLevelFilterAndTypesConsumeRouteFileRoutes(): void
    {
        $app = $this->app();

        [, $listOut] = $this->runCommand($app, 'route:forge:list', ['--json', '--level=manage']);
        $rows = json_decode($listOut, true)['routes'];
        self::assertSame(['manage.users'], array_column($rows, 'name'));

        [, $typesOut] = $this->runCommand($app, 'route:forge:types', ['--json']);
        $types = json_decode($typesOut, true);

        self::assertArrayHasKey('manage', $types);
        self::assertArrayHasKey('auth.login', $types['public'] ?? [], 'types 产物同样要含路由文件里的命名路由');
        self::assertArrayHasKey('manage.users', $types['manage']);
    }

    public function testTableOutputListsRouteFileRoutes(): void
    {
        // 非 --json 形态（人类可读表格）同样受本修复影响，别只验 JSON
        [$exit, $content] = $this->runCommand($this->app(), 'route:forge:list');

        self::assertSame(0, $exit);
        self::assertStringContainsString('auth.login', $content);
        self::assertStringContainsString('Tier counts: public: 1 | manage: 1 | unassigned: 0', $content);
    }

    public function testSameProcessSecondCommandDoesNotDuplicateRoutes(): void
    {
        // 加载器是容器单例：同一进程里跑第二条命令不得把路由文件再 include 一遍
        $app  = $this->app();
        $args = ['--json'];

        [, $first]  = $this->runCommand($app, 'route:forge:list', $args);
        [, $second] = $this->runCommand($app, 'route:forge:list', $args);
        [, $third]  = $this->runCommand($app, 'route:forge:types', ['--json']);

        self::assertSame(json_decode($first, true), json_decode($second, true), '重复运行不得整倍儿重复');
        self::assertTrue($app->make(RouteFileLoader::class)->loaded());

        $types = json_decode($third, true);
        self::assertCount(1, $types['public']);
    }

    public function testMixedSourcesAreBothVisible(): void
    {
        // 内存注册（老路径）与路由文件（新覆盖）并存时，两侧都该在结果里
        $app = $this->app();
        \think\facade\Route::get('inline/ping', function () {
            return 'ping';
        })->name('inline.ping')->tier('public');

        [, $content] = $this->runCommand($app, 'route:forge:list', ['--json']);
        $names = array_column(json_decode($content, true)['routes'], 'name');

        self::assertContains('auth.login', $names);
        self::assertContains('inline.ping', $names);
    }
}
