<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\ForgeService;
use RouteForge\ThinkPHP\Support\RouteCollector;
use RouteForge\ThinkPHP\Support\RouteFileLoader;
use think\App;
use think\console\Input;
use think\console\Output;

/**
 * route:forge:gen 端到端：写入生成文件、幂等、悬空提醒、单应用 --module 防误用。
 */
class RouteForgeGenCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = getenv('RF_TEST_TMP') ?: (is_dir('F:/tmp') ? 'F:/tmp' : sys_get_temp_dir());
        $this->root = $base . '/rf-gen-' . bin2hex(random_bytes(4));
        foreach (['app/controller/admin', 'route', 'runtime', 'config'] as $d) {
            mkdir($this->root . '/' . $d, 0777, true);
        }

        $fixture = dirname(__DIR__) . '/Fixtures/AutoRouteApp';
        copy($fixture . '/app/controller/User.php', $this->root . '/app/controller/User.php');
        copy($fixture . '/app/controller/admin/Dashboard.php', $this->root . '/app/controller/admin/Dashboard.php');
        foreach (['app', 'cache', 'route'] as $c) {
            copy($fixture . "/config/{$c}.php", $this->root . "/config/{$c}.php");
        }
    }

    protected function tearDown(): void
    {
        // 清理临时应用根（仅限本次测试自建目录）
        $this->rmrf($this->root);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function app(): App
    {
        $app = new App($this->root . '/');
        $app->initialize();
        restore_error_handler();
        restore_exception_handler();
        $svc = new ForgeService($app);
        $app->register($svc);
        $app->bootService($svc);

        return $app;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function gen(App $app, array $args = []): array
    {
        $input = new Input(array_merge(['route:forge:gen'], $args));
        $input->setInteractive(false);
        $output = new Output('buffer');
        $exit = $app->console->find('route:forge:gen')->run($input, $output);

        return [$exit, $output->fetch()];
    }

    private function outFile(): string
    {
        return $this->root . '/route/forge.auto.php';
    }

    public function testGeneratesRulesToRouteFile(): void
    {
        [$exit] = $this->gen($this->app());
        self::assertSame(0, $exit);
        self::assertFileExists($this->outFile());

        $php = (string) file_get_contents($this->outFile());
        self::assertStringContainsString("Route::any('user/read', 'user/read')->name('user.read')", $php);
        self::assertStringContainsString("Route::any('admin/dashboard/index', 'admin/dashboard/index')->name('admin.dashboard.index')", $php);
        self::assertStringContainsString("->tier('…') 待填", $php);
        // 过滤：非端点方法不出现
        self::assertStringNotContainsString("->name('user.secret')", $php);
        self::assertStringNotContainsString('staticish', $php);
    }

    /**
     * 回归：产物必须是**能被 include 的合法 PHP**。
     * 1.1.0 的 HEADER 在双引号串里多写了一层转义，落盘成 `use think\\facade\\Route;`
     * 直接 ParseError；旧用例只对条目文本做 assertStringContainsString，从没真正加载过
     * 产物，所以缺陷一直隐身——而 route/*.php 在 HTTP 与 console 都会被 include，
     * 等于跑一次 gen 就把整个应用打挂。
     */
    public function testGeneratedFileIsLoadablePhp(): void
    {
        $this->gen($this->app());
        $php = (string) file_get_contents($this->outFile());

        self::assertStringContainsString('use think\facade\Route;', $php, 'use 行必须是单反斜杠');
        self::assertStringNotContainsString('think\\\\facade', $php, '双反斜杠会让产物不是合法 PHP');

        // 真正的判据：走一遍加载器，产物里的规则要能进规则树
        $app    = $this->app();
        $loader = new RouteFileLoader($app);
        $loader->load();

        $rules = [];
        foreach ((new RouteCollector($app->route))->collect() as $item) {
            $rules[] = (string) $item->getRule();
        }

        self::assertContains('user/read', $rules, '生成文件加载后规则应可见');
        self::assertContains('admin/dashboard/index', $rules);
    }

    /**
     * 回归：1.1.0 写坏的历史产物必须**早于加载**被拦下。
     * 加载器会 include route/ 下的路由文件，而坏头部不是合法 PHP，include 就是
     * ParseError 堆栈；命令只增不删，故只报可操作修法，不自动改写上历史文件。
     */
    public function testLegacyBrokenProductIsBlockedBeforeLoading(): void
    {
        file_put_contents(
            $this->outFile(),
            "<?php\nuse think\\\\facade\\\\Route;\nRoute::any('ghost/go', 'ghost/go')->name('ghost.go');\n"
        );
        $before = (string) file_get_contents($this->outFile());

        [$exit, $out] = $this->gen($this->app());

        self::assertSame(1, $exit, '坏产物应在加载前被拦下，而不是抛 ParseError');
        self::assertStringContainsString('1.1.0 的坏写法', $out);
        self::assertStringContainsString('use think\facade\Route;', $out, '提示要给出可照抄的正确写法');
        self::assertSame($before, (string) file_get_contents($this->outFile()), '不得往坏文件里追加');

        [$dryExit] = $this->gen($this->app(), ['--dry-run']);
        self::assertSame(1, $dryExit, 'dry-run 同样会加载路由文件，必须一起拦下');
    }

    public function testIdempotentSecondRunAddsNothing(): void
    {
        $app = $this->app();
        $this->gen($app);
        $afterFirst = (string) file_get_contents($this->outFile());

        [$exit, $out] = $this->gen($this->app());
        self::assertSame(0, $exit);
        $afterSecond = (string) file_get_contents($this->outFile());

        self::assertSame($afterFirst, $afterSecond, '重复运行不应新增任何规则');
        self::assertStringContainsString('无需新增', $out);
    }

    public function testDryRunWritesNothing(): void
    {
        [$exit, $out] = $this->gen($this->app(), ['--dry-run']);
        self::assertSame(0, $exit);
        self::assertFileDoesNotExist($this->outFile());
        self::assertStringContainsString('dry-run', $out);
    }

    public function testStaleEntryOnlyRemindedNeverDeleted(): void
    {
        $app = $this->app();
        $this->gen($app);

        // 手动在生成文件塞一条指向已不存在控制器的规则（模拟开发者后续删了控制器）
        file_put_contents($this->outFile(), "\nRoute::any('ghost/go', 'ghost/go')->name('ghost.go');\n", FILE_APPEND);

        [$exit, $out] = $this->gen($this->app());
        self::assertSame(0, $exit);

        self::assertStringContainsString('ghost.go', $out);
        self::assertStringContainsString('悬空', $out);
        // 提醒不删除：ghost 规则仍在文件里
        self::assertStringContainsString("->name('ghost.go')", (string) file_get_contents($this->outFile()));
    }

    public function testSingleAppRejectsModuleOption(): void
    {
        [$exit, $out] = $this->gen($this->app(), ['--module=admin']);
        self::assertSame(1, $exit, '单应用模式用 --module 应拒绝');
        self::assertStringContainsString('单应用', $out);
    }

    /**
     * action_suffix='View'：可达条目按短形式生成（think 会自己拼回 suffix 命中方法），
     * 不以 suffix 结尾的方法无可达 URL → 只登记不写；且二次运行不得把短形式 target 误判成悬空。
     */
    public function testActionSuffixGeneratesShortFormAndSkipsUnreachable(): void
    {
        copy(
            dirname(__DIR__) . '/Fixtures/SuffixApp/app/controller/Report.php',
            $this->root . '/app/controller/Report.php',
        );
        file_put_contents($this->root . '/config/route.php', "<?php return ['action_suffix' => 'View'];\n");

        [$exit, $out] = $this->gen($this->app());
        self::assertSame(0, $exit);
        $php = (string) file_get_contents($this->outFile());

        self::assertStringContainsString("Route::any('report/list', 'report/list')->name('report.list')", $php);
        self::assertStringNotContainsString("->name('report.export')", $php);
        // 夹具里的 User::read 等同样不以 View 结尾 → 一律不可达，生成它们等于凭空新增端点
        self::assertStringNotContainsString("->name('user.read')", $php);
        self::assertStringContainsString('没有可达 URL', $out);
        self::assertStringContainsString('Report::export', $out, '提示要点名是哪个方法');

        // 回归：短形式 target 反查方法名时须拼回 suffix，否则每次运行都报假悬空
        [$exitSecond, $outSecond] = $this->gen($this->app());
        self::assertSame(0, $exitSecond);
        self::assertStringNotContainsString('悬空', $outSecond);
    }

    /**
     * 默认无 action_suffix 时，camelCase 方法照其方法名生成，但要给出大小写风险提示：
     * 自动路由时代 URL 靠 is_callable 大小写不敏感命中，物化后若开了 url_case_sensitive
     * 则历史小写写法会 404。
     */
    public function testMixedCaseActionStillGeneratedWithCaseNotice(): void
    {
        [$exit, $out] = $this->gen($this->app());
        self::assertSame(0, $exit);
        $php = (string) file_get_contents($this->outFile());

        self::assertStringContainsString("Route::any('user/batchImport', 'user/batchImport')->name('user.batchImport')", $php);
        self::assertStringContainsString('camelCase 动作段', $out);
        self::assertStringContainsString('url_case_sensitive', $out);

        // 已生成的条目不再反复 nag
        [, $outSecond] = $this->gen($this->app());
        self::assertStringNotContainsString('camelCase 动作段', $outSecond);
    }

    /**
     * 回归：写盘失败过去不判 file_put_contents 返回值——留下半个文件却报成功。
     * 用「生成目标被目录占位」制造跨平台可控的写失败。
     */
    public function testWriteFailureIsReportedNotSilentlySucceeded(): void
    {
        mkdir($this->outFile());

        [$exit, $out] = $this->gen($this->app());

        self::assertSame(1, $exit, '写盘失败不得返回成功');
        self::assertStringContainsString('生成目标是目录', $out);
        self::assertStringContainsString('重跑', $out, '应告知本命令幂等、可修好后重跑补齐');
    }

    /**
     * 回归：多应用已装 + app/controller 与模块控制器目录并存的混合布局。
     * 过去 detectMode 里那个永不命中的重复条件使它静默回落 single，
     * 于是扫错根目录还无故拒绝 --module；现在必须停下来要求显式 --mode。
     */
    public function testMixedLayoutRequiresExplicitMode(): void
    {
        mkdir($this->root . '/vendor/topthink/think-multi-app', 0777, true);
        mkdir($this->root . '/app/shop/controller', 0777, true);

        [$exit, $out] = $this->gen($this->app());
        self::assertSame(1, $exit, '混合布局不应自行猜模式');
        self::assertStringContainsString('无法自动判定应用模式', $out);
        self::assertStringContainsString('--mode=multi', $out);
        self::assertStringContainsString('shop', $out, '提示应列出可用模块');
        self::assertFileDoesNotExist($this->outFile(), '判定失败时不得写出任何产物');

        // 显式表态后继续可用：single 只扫根控制器层，不碰模块目录
        [$exitSingle] = $this->gen($this->app(), ['--mode=single']);
        self::assertSame(0, $exitSingle);
        $php = (string) file_get_contents($this->outFile());
        self::assertStringContainsString("->name('user.read')", $php);
        self::assertStringNotContainsString("->name('shop.", $php);
    }
}
