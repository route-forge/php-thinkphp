<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Support\RouteCollector;
use RouteForge\ThinkPHP\Support\RouteFileLoader;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use think\App;
use think\event\RouteLoaded;
use think\facade\Route;

/**
 * 路由文件加载器：console 场景把 route/*.php 按 HTTP 同姿势灌进规则树。
 *
 * 本包的结构性盲区（1.1.0 缺陷根因）：过去的用例里的业务路由全部由测试代码
 * 直接 `Route::get()` 注册，从不经过「think 加载路由文件」这条真实路径，
 * 于是命令侧收集不到应用路由这件事全量测试全绿也看不见。
 * 因此这里的路由**只来自路由文件**，且断言都打在规则树的实际内容上。
 */
class RouteFileLoaderTest extends TestCase
{
    private const APP_ROUTES = <<<'PHP'
<?php
use think\facade\Route;
Route::get('probe/ping', function () { return 'ping'; })->name('probe.ping');
Route::post('probe/echo', function () { return 'echo'; })->name('probe.echo');
PHP;

    private const INNER_ROUTES = <<<'PHP'
<?php
use think\facade\Route;
Route::get('nested/inner', function () { return 'inner'; })->name('nested.inner');
PHP;

    private const CUSTOM_ROUTES = <<<'PHP'
<?php
use think\facade\Route;
Route::get('custom/thing', function () { return 'thing'; })->name('custom.thing');
PHP;

    /**
     * 规则树里当前的路由规则（URI）列表——重复注册会在这里现形，故用它做幂等断言。
     *
     * @return list<string>
     */
    private function rules(App $app): array
    {
        $rules = [];

        foreach ((new RouteCollector($app->route))->collect() as $item) {
            $rules[] = (string) $item->getRule();
        }

        sort($rules);

        return $rules;
    }

    private function occurrences(array $rules, string $rule): int
    {
        return count(array_keys($rules, $rule, true));
    }

    public function testRoutesComeFromRouteFileIntoRuleTree(): void
    {
        $app    = AppFactory::create(routeFiles: ['app.php' => self::APP_ROUTES]);
        $loader = new RouteFileLoader($app);

        // 加载前：应用路由确实不在树里（这正是 1.1.0 的现场）
        self::assertSame(0, $this->occurrences($this->rules($app), 'probe/ping'));

        $loader->load();

        $rules = $this->rules($app);
        self::assertSame(1, $this->occurrences($rules, 'probe/ping'));
        self::assertSame(1, $this->occurrences($rules, 'probe/echo'));
        // 与框架自带 route:list 不同：我们绝不调 Route::clear()，forge 自身端点必须还在树里
        self::assertContains('_forge/routes', $rules, '加载不得清掉已注册的 forge 端点');
        self::assertTrue($loader->loaded());
        // 只有扁平顶层文件的正常项目不该产生任何提示
        self::assertSame([], $loader->warnings());
    }

    public function testDirectoryMatchingGlobIsSkipped(): void
    {
        $app    = AppFactory::create(routeFiles: ['app.php' => self::APP_ROUTES]);
        $loader = new RouteFileLoader($app);

        // route:forge:gen 写失败会留下同名占位目录；glob('*.php') 连目录一起匹配，
        // 而 include 目录只抛 E_WARNING（think 的 Error 初始化器会转成 ErrorException）
        mkdir($app->http->getRoutePath() . 'forge.auto.php');

        $loader->load();

        self::assertContains('probe/ping', $this->rules($app), '异常目录不该妨碍同目录下的正常路由文件');
    }

    public function testLoadIsIdempotentWithinProcess(): void
    {
        $app    = AppFactory::create(routeFiles: ['app.php' => self::APP_ROUTES]);
        $loader = new RouteFileLoader($app);

        $loader->load();
        $afterFirst = $this->rules($app);

        $loader->load();
        $loader->load();

        self::assertSame($afterFirst, $this->rules($app), '同进程重复加载不得产生第二条同名规则');
    }

    public function testRouteLoadedEventStillFiresAfterIncludingFiles(): void
    {
        $app    = AppFactory::create(routeFiles: ['app.php' => self::APP_ROUTES]);
        $loader = new RouteFileLoader($app);

        // 模拟服务包经 think\Service::loadRoutesFrom() 注册的监听者（事件唯一的真实消费者）
        $ran = 0;
        $app->event->listen(RouteLoaded::class, static function () use (&$ran): void {
            $ran++;
            Route::get('svc/pong', function () {
                return 'pong';
            })->name('svc.pong');
        });

        $loader->load();
        $loader->load();

        self::assertSame(1, $ran, 'RouteLoaded 只该随一次加载触发');
        self::assertContains('svc/pong', $this->rules($app));
    }

    public function testCustomRoutePathIsHonoured(): void
    {
        $app = AppFactory::create(routeFiles: ['app.php' => self::APP_ROUTES]);

        // 覆写路由目录（多应用等场景就是这么改的）：默认 route/ 下的文件不该再被看见
        $custom = $app->getRootPath() . 'custom' . DIRECTORY_SEPARATOR;
        mkdir($custom, 0777, true);
        file_put_contents($custom . 'web.php', self::CUSTOM_ROUTES);
        $app->http->setRoutePath($custom);

        (new RouteFileLoader($app))->load();

        $rules = $this->rules($app);
        self::assertContains('custom/thing', $rules);
        self::assertNotContains('probe/ping', $rules, '路由目录必须以 Http::getRoutePath() 为唯一来源');
    }

    public function testMissingRouteDirectoryIsNotFatal(): void
    {
        $app = AppFactory::create();
        $app->http->setRoutePath($app->getRootPath() . 'no-such-route-dir' . DIRECTORY_SEPARATOR);

        $loader = new RouteFileLoader($app);
        $loader->load();

        self::assertTrue($loader->loaded());
        self::assertSame([], $loader->warnings(), '目录不存在既不该抛错，也不该产生提示');
    }

    public function testSubdirectoryRoutesAreSkippedAndWarned(): void
    {
        $app = AppFactory::create(routeFiles: [
            'app.php'         => self::APP_ROUTES,
            'nested/inner.php' => self::INNER_ROUTES,
            'deep/er/more.php' => self::INNER_ROUTES,
        ]);

        $loader  = new RouteFileLoader($app);
        $warning = $loader->warnings();

        self::assertCount(1, $warning);
        self::assertStringContainsString('2 个路由文件', $warning[0]);
        self::assertStringContainsString('nested/inner.php', $warning[0]);
        self::assertStringContainsString('deep/er/more.php', $warning[0]);

        $loader->load();

        // 与 HTTP 一致：子目录文件运行时不会被 include，故不得凭空多出规则
        $rules = $this->rules($app);
        self::assertContains('probe/ping', $rules);
        self::assertNotContains('nested/inner', $rules);
    }

    public function testWithRouteDisabledMatchesRuntimeAndWarns(): void
    {
        $app = AppFactory::create(routeFiles: ['app.php' => self::APP_ROUTES]);
        // 与 Http::dispatchToRoute 的判据同源：app.with_route=false 时运行时连路由文件都不加载
        $app->config->set(['with_route' => false], 'app');

        $loader = new RouteFileLoader($app);

        self::assertStringContainsString('app.with_route=false', $loader->warnings()[0] ?? '');

        $loader->load();

        $rules = $this->rules($app);
        self::assertNotContains('probe/ping', $rules, '运行时不加载的东西，forge 不得凭空报出来');
        self::assertContains('_forge/routes', $rules, '服务端点由服务注册，与路由文件无关，仍在');
    }
}
