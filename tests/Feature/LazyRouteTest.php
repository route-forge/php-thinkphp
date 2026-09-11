<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\facade\Route;

/**
 * url_lazy_route（路由延迟解析）fail-fast：v1 明确不支持。
 */
class LazyRouteTest extends TestCase
{
    /**
     * @return array{0:int,1:string}
     */
    private function runCommand(\think\App $app, string $name, array $args = []): array
    {
        $input = new \think\console\Input(array_merge([$name], $args));
        $input->setInteractive(false);
        $output = new \think\console\Output('buffer');
        $exit = $app->console->find($name)->run($input, $output);

        return [$exit, $output->fetch()];
    }

    private function lazyApp(): \think\App
    {
        return AppFactory::create(debug: true, forgeOverrides: [
            'levels' => ['public' => ['description' => 'p', 'match' => [], 'load' => 'lazy']],
        ], lazyRoute: true);
    }

    public function testLazyRouteConfigFailsFast(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels' => ['public' => ['description' => 'p', 'match' => [], 'load' => 'lazy']],
        ], lazyRoute: true);

        Route::get('a', function () {
            return 'a';
        })->name('a.index')->tier('public');

        // 端点扫描直接抛清晰异常（渲染为 500 错误页），而非返回失真的空/缺数据
        $response = Http::get($app, '/_forge/routes/public');

        self::assertSame(500, $response->getCode());
        self::assertStringContainsString('url_lazy_route', (string) $response->getContent());
    }

    /**
     * 命令层：适配层自己的 fail-fast 异常不得糊成框架堆栈（此前只捕
     * ForgeExceptionContract，RuntimeException 直接逃到 console 异常处理器）。
     */
    public function testListReportsLazyRouteAsActionableMessageNotStackTrace(): void
    {
        [$exit, $content] = $this->runCommand($this->lazyApp(), 'route:forge:list');

        self::assertSame(1, $exit);
        self::assertStringContainsString('url_lazy_route', $content);
        self::assertStringNotContainsString('Stack trace', $content);
        self::assertStringNotContainsString("\n#0 ", $content, '不应把框架堆栈倒给用户');
    }

    public function testListJsonKeepsStdoutCleanOnFailure(): void
    {
        [$exit, $content] = $this->runCommand($this->lazyApp(), 'route:forge:list', ['--json']);

        self::assertSame(1, $exit);
        self::assertSame('', trim($content), '--json 的 stdout 不得混入失败文本（改走 STDERR）');
    }

    public function testTypesKeepsArtifactStreamCleanOnFailure(): void
    {
        [$exit, $content] = $this->runCommand($this->lazyApp(), 'route:forge:types');

        self::assertSame(1, $exit);
        self::assertSame('', trim($content), 'types 的 stdout 恒为产物，失败信息必须走 STDERR');
    }
}
