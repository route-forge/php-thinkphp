<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\ForgeService;
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
