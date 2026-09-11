<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Support\AutoRouteScanner;
use think\App;

/**
 * AutoRouteScanner：从自动路由夹具反推可枚举端点（命名/URI 派生 + 过滤规则）。
 */
class AutoRouteScannerTest extends TestCase
{
    private function scanner(): AutoRouteScanner
    {
        return $this->scannerAt('AutoRouteApp');
    }

    private function scannerAt(string $fixture): AutoRouteScanner
    {
        $root = dirname(__DIR__) . '/Fixtures/' . $fixture . '/';
        $app = new App($root);
        $app->initialize();
        restore_error_handler();
        restore_exception_handler();

        return new AutoRouteScanner($app);
    }

    private function indexByName(AutoRouteScanner $s): array
    {
        $byName = [];
        foreach ($s->scan('single') as $ep) {
            $byName[$ep['name']] = $ep;
        }

        return $byName;
    }

    public function testDetectsSingleAppMode(): void
    {
        self::assertSame('single', $this->scanner()->detectMode());
    }

    public function testDetectsMultiAppModeAndListsModules(): void
    {
        $scanner = $this->scannerAt('MultiAppLayout');

        self::assertSame('multi', $scanner->detectMode());
        self::assertSame(['admin', 'api'], $scanner->availableModules());
    }

    /**
     * 回归：混合布局（多应用已装，但 app/controller 与模块控制器目录并存）过去因
     * detectMode 里的重复条件永不命中而静默回落 single，令 route:forge:gen 扫错根、
     * 且无故拒绝 --module。现在必须报 ambiguous，由命令层要求显式 --mode。
     */
    public function testAmbiguousWhenRootControllerLayerCoexistsWithModules(): void
    {
        $scanner = $this->scannerAt('MixedAppLayout');

        self::assertSame('ambiguous', $scanner->detectMode());
        self::assertSame(['api'], $scanner->availableModules());
    }

    public function testGeneratesOneEndpointPerPublicOwnMethod(): void
    {
        $names = array_keys($this->indexByName($this->scanner()));
        sort($names);

        self::assertSame(
            ['admin.dashboard.index', 'admin.dashboard.stat', 'user.batchImport', 'user.index', 'user.read', 'user.save'],
            $names,
        );
    }

    /**
     * 动作段沿用方法名原样 → camelCase 方法产出 snake+camel 混排 URL，
     * 必须打标记供命令层提示大小写风险；全小写的不能误报。
     */
    public function testFlagsMixedCaseActionSegment(): void
    {
        $byName = $this->indexByName($this->scanner());

        self::assertTrue($byName['user.batchImport']['mixedCaseAction']);
        self::assertSame('user/batchImport', $byName['user.batchImport']['uri']);
        self::assertFalse($byName['user.read']['mixedCaseAction']);
    }

    /**
     * action_suffix='View'：think 拿「URL 段 + suffix」去命中方法，故
     * listView 的可达 URL 是短形式 report/list（生成成 report/listView 会与现状 URL 不符，
     * 切强制路由后旧链接 404）；不以 suffix 结尾的方法无可达 URL，标 unreachable 不生成。
     */
    public function testActionSuffixDerivesShortUrlAndMarksUnreachable(): void
    {
        $byName = [];
        foreach ($this->scannerAt('SuffixApp')->scan('single') as $ep) {
            $byName[$ep['name']] = $ep;
        }

        $names = array_keys($byName);
        sort($names);
        self::assertSame(['report.export', 'report.index', 'report.list'], $names);

        $list = $byName['report.list'];
        self::assertSame('report/list', $list['uri']);
        self::assertSame('report/list', $list['target']);
        self::assertSame('listView', $list['method'], 'method 必须是真实方法名，供悬空校验反查');
        self::assertArrayNotHasKey('unreachable', $list);

        self::assertTrue($byName['report.export']['unreachable'], 'export() 在该 suffix 下无可达 URL');
    }

    public function testExcludesNonEndpointMethods(): void
    {
        $byName = $this->indexByName($this->scanner());

        // protected/private/static/_ 前缀方法都不应出现
        foreach (['user.secret', 'user.hidden', 'user.staticish', 'user._notanaction', 'user.__construct'] as $absent) {
            self::assertArrayNotHasKey($absent, $byName, "{$absent} 不该被扫描为端点");
        }
    }

    public function testUriAndTargetAndClassDerivation(): void
    {
        $byName = $this->indexByName($this->scanner());

        $read = $byName['user.read'];
        self::assertSame('user/read', $read['uri']);
        self::assertSame('user/read', $read['target']);
        self::assertSame('app\controller\User', $read['class']);
        self::assertSame('read', $read['method']);

        // 子目录控制器 → 路径段 admin/dashboard，名字 admin.dashboard.*
        $dash = $byName['admin.dashboard.index'];
        self::assertSame('admin/dashboard/index', $dash['uri']);
        self::assertSame('app\controller\admin\Dashboard', $dash['class']);
    }

    public function testSingleAppOutputFileIsRootRoute(): void
    {
        $eps = $this->scanner()->scan('single');
        self::assertNotEmpty($eps);
        foreach ($eps as $ep) {
            self::assertStringEndsWith('route' . DIRECTORY_SEPARATOR . 'forge.auto.php', $ep['outputRouteFile']);
            self::assertNull($ep['module']);
        }
    }
}
