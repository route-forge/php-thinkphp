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
 * types --out 目录/写入健壮性 + ->tier() 拼写告警（易用性修复）。
 */
class TypesOutAndTypoTest extends TestCase
{
    /**
     * @return array{0:int,1:string}
     */
    private function runCommand(App $app, string $name, array $args = []): array
    {
        $input = new Input(array_merge([$name], $args));
        $input->setInteractive(false);
        $output = new Output('buffer');

        $exit = $app->console->find($name)->run($input, $output);

        return [$exit, $output->fetch()];
    }

    private function appWithRoutes(): App
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels' => [
                'public' => ['description' => '公共', 'match' => [], 'load' => 'lazy'],
            ],
        ]);

        Route::get('a', function () {
            return 'a';
        })->name('a.index')->tier('public');

        return $app;
    }

    public function testTypesOutCreatesNestedDirAndReportsAbsolutePath(): void
    {
        $app = $this->appWithRoutes();
        $target = $app->getRuntimePath() . 'nested/deep/forge-routes.d.ts';

        [$exit, $out] = $this->runCommand($app, 'route:forge:types', ['--out=' . $target]);

        self::assertSame(0, $exit);
        self::assertFileExists($target, '缺失的父目录应自动创建');
        self::assertStringContainsString('Written to:', $out);
        self::assertStringContainsString('forge-routes.d.ts', $out);
    }

    public function testTypesOutFailsWhenPathUnderFile(): void
    {
        $app = $this->appWithRoutes();
        // runtime 下先放一个普通文件，再把它当"目录"用 → mkdir 必然失败
        $blocker = $app->getRuntimePath() . 'blocker.txt';
        file_put_contents($blocker, 'x');

        [$exit, $out] = $this->runCommand($app, 'route:forge:types', ['--out=' . $blocker . '/nope.d.ts']);

        self::assertSame(1, $exit, '目录无法创建时不得假成功');
        self::assertStringContainsString('无法创建输出目录', $out);
    }

    public function testListJsonWarnsAboutMisspelledTierMethod(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels' => [
                'public' => ['description' => '公共', 'match' => [], 'load' => 'lazy'],
            ],
        ]);

        // 故意拼错：->tiere() 经 __call 落 option['tiere']，不会生效
        Route::get('b', function () {
            return 'b';
        })->name('b.index')->tiere('public');

        [$exit, $out] = $this->runCommand($app, 'route:forge:list', ['--json']);
        self::assertSame(0, $exit);

        $payload = json_decode(trim($out), true);
        $joined = implode("\n", $payload['warnings']);

        self::assertStringContainsString('b.index', $joined, '应定位到拼错的路由');
        self::assertStringContainsString('tiere', $joined, '应点名疑似拼错的链式方法');
        self::assertStringContainsString('->tier(', $joined, '应提示正确写法');
    }

    public function testListJsonDoesNotFlagCorrectTierMethod(): void
    {
        $app = $this->appWithRoutes();

        [$exit, $out] = $this->runCommand($app, 'route:forge:list', ['--json']);
        $payload = json_decode(trim($out), true);

        self::assertSame(0, $exit);
        foreach ($payload['warnings'] as $w) {
            self::assertStringNotContainsString('疑似拼错', (string) $w, '合法 ->tier() 不应误报');
        }
    }
}
