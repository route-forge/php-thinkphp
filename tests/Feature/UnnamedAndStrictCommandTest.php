<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\App;
use think\console\Input;
use think\console\Output;
use think\facade\Route;

/**
 * 未命名路由暴露 + 严格模式违规清单（common d5df85b / b470446 接线，SPEC §3.2 / §6.1）。
 *
 * 覆盖三个此前静默的缺口：
 *   1. 靠 config match / classifier 命中层级却无名的路由，以前完全静默——现在经
 *      analyze() 的 warnings（unnamedWarnings）与 --unnamed 视图暴露，命中来源各给对应说法；
 *   2. 未命名且未命中任何层级的路由不得进 warnings（否则「warnings 非空即失败」的 CI 门禁被打穿）；
 *   3. 严格模式下 list / types 一次报全「缺名字 + 缺层级」，命令行只报问题、
 *      types 不再静默产出 d.ts，且 --json 的 stdout 恒为纯 JSON（清单走 STDERR）。
 *
 * 命令 stdout 走 think 的 Buffer 驱动（不过 Formatter），故断言的是含 <error> 原文的字符串；
 * warnings / 严格清单在 --json、types、以及未命名视图之外都直写真实 STDERR（Buffer 采不到），
 * 因此这些断言只针对 stdout 可观测部分，STDERR 侧靠「stdout 未被污染」反向确认。
 */
class UnnamedAndStrictCommandTest extends TestCase
{
    /**
     * @return array{0:int,1:string} [退出码, Buffer 内容]
     */
    private function runCommand(App $app, string $name, array $args = []): array
    {
        $input  = new Input(array_merge([$name], $args));
        $output = new Output('buffer');

        $exit = $app->console->find($name)->run($input, $output);

        return [$exit, $output->fetch()];
    }

    public function testMatchRuleUnnamedRouteSurfacesInWarnings(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels' => [
                'public' => ['description' => '公共', 'match' => ['prefix' => ['anon']], 'load' => 'eager'],
            ],
        ]);

        // 未命名（不 ->name()），但被 match.prefix 命中 public 层级
        Route::get('anon/secret', function () {
            return 'secret';
        });

        [$exit, $content] = $this->runCommand($app, 'route:forge:list', ['--json']);
        $payload = json_decode($content, true);

        self::assertSame(0, $exit);
        $joined = implode("\n", (array) $payload['warnings']);
        self::assertStringContainsString('by a config match rule', $joined);
        self::assertStringContainsString('anon/secret', $joined);
        // 无名路由不进 rows / 不进任何层级计数
        self::assertArrayNotHasKey('anon/secret', array_column((array) $payload['routes'], null, 'uri'));
    }

    public function testClassifierUnnamedRouteSurfacesInWarnings(): void
    {
        $app = AppFactory::create(
            debug: true,
            forgeOverrides: ['levels' => [
                'public' => ['description' => '公共', 'match' => [], 'load' => 'eager'],
            ]],
            classifier: static fn (\think\route\RuleItem $r): ?string
                => str_starts_with((string) $r->getRule(), 'gen') ? 'public' : null,
        );

        // 未命名，经 classifier 归入 public
        Route::get('gen/thing', function () {
            return 'thing';
        });

        [$exit, $content] = $this->runCommand($app, 'route:forge:list', ['--json']);
        $joined = implode("\n", (array) json_decode($content, true)['warnings']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('by the classifier callback', $joined);
        self::assertStringContainsString('gen/thing', $joined);
    }

    public function testUnnamedButUnmatchedRouteDoesNotPolluteWarnings(): void
    {
        // 既未命名、又不被任何 match/classifier/tier 命中：它压根与 forge 无关，
        // 不得进 warnings（否则 CI 门禁被无关路由长期打断）
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels' => [
                'public' => ['description' => '公共', 'match' => ['prefix' => ['named-only']], 'load' => 'eager'],
            ],
        ]);

        Route::get('misc/untracked', function () {
            return 'x';
        });

        [$exit, $content] = $this->runCommand($app, 'route:forge:list', ['--json']);
        $joined = implode("\n", (array) json_decode($content, true)['warnings']);

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('misc/untracked', $joined);
    }

    public function testUnnamedViewListsRoutesWithoutTable(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels' => [
                'public' => ['description' => '公共', 'match' => ['prefix' => ['anon']], 'load' => 'eager'],
            ],
        ]);

        Route::get('anon/secret', function () {
            return 'secret';
        });
        Route::get('misc/free', function () {
            return 'free';
        });
        Route::get('named/route', function () {
            return 'ok';
        })->name('named.route')->tier('public');

        [$exit, $content] = $this->runCommand($app, 'route:forge:list', ['--unnamed']);

        self::assertSame(0, $exit);
        // 全量未命名视图：含命中层级的与被命中的都在内
        self::assertStringContainsString('unnamed route(s) total', $content);
        self::assertStringContainsString('anon/secret', $content);
        self::assertStringContainsString('misc/free', $content);
        // 不与表格双写：不出现表头 / 层级统计
        self::assertStringNotContainsString('Name/Alias', $content);
        self::assertStringNotContainsString('Tier counts:', $content);
        // 已命名的路由不混进未命名视图
        self::assertStringNotContainsString('named.route', $content);
    }

    public function testStrictListReportsBothViolationCategoriesInRed(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'      => [
                'public' => ['description' => '公共', 'match' => ['prefix' => ['anon']], 'load' => 'eager'],
            ],
            'strict_mode' => true,
        ]);

        // missing_name：命中 public 层级但无名
        Route::get('anon/secret', function () {
            return 'secret';
        });
        // unassigned：有名字却不命中任何层级
        Route::get('misc/orphan', function () {
            return 'orphan';
        })->name('misc.orphan');

        [$exit, $content] = $this->runCommand($app, 'route:forge:list');

        self::assertSame(1, $exit);
        // 整行红色清单
        self::assertStringContainsString('<error>', $content);
        self::assertStringContainsString('strict_mode found', $content);
        // 两类问题一次报全
        self::assertStringContainsString('assigned to a level but have no', $content);
        self::assertStringContainsString('not matched by any level', $content);
        self::assertStringContainsString('misc.orphan', $content);
        // 只报问题：不再打印正常表格 / 层级统计
        self::assertStringNotContainsString('Tier counts:', $content);
    }

    public function testStrictListJsonKeepsStdoutPureJson(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'      => [
                'public' => ['description' => '公共', 'match' => ['prefix' => ['anon']], 'load' => 'eager'],
            ],
            'strict_mode' => true,
        ]);

        Route::get('anon/secret', function () {
            return 'secret';
        });
        Route::get('misc/orphan', function () {
            return 'orphan';
        })->name('misc.orphan');

        [$exit, $content] = $this->runCommand($app, 'route:forge:list', ['--json']);

        // --json 退出码 1，但 stdout 仍是可直接管道消费的纯 JSON（红色清单走 STDERR，不在此）
        self::assertSame(1, $exit);
        $payload = json_decode($content, true);
        self::assertIsArray($payload);
        self::assertStringNotContainsString('strict_mode found', $content);
        self::assertStringNotContainsString('<error>', $content);
    }

    public function testStrictTypesRefusesToGenerateOutput(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'      => [
                'public' => ['description' => '公共', 'match' => [], 'load' => 'lazy'],
            ],
            'strict_mode' => true,
        ]);

        Route::get('misc/orphan', function () {
            return 'orphan';
        })->name('misc.orphan');

        [$exit, $content] = $this->runCommand($app, 'route:forge:types');

        // 严格模式违规：不再让未归级路由静默生成 d.ts（清单走 STDERR），退出码 1、无产物
        self::assertSame(1, $exit);
        self::assertStringNotContainsString('export type ForgeLevel', $content);
        self::assertStringNotContainsString('misc.orphan', $content);
    }

    public function testForgeSelfEndpointsNotCountedAsStrictViolations(): void
    {
        // 一个层级的 endpoint_middleware 与另一层级的 match.middleware 同名：
        // forge 自身层级端点带着该中间件，会被别的层级的 match 规则命中。它必须按名/按 URI 排除，
        // 否则包会在每次严格模式扫描里把自己报成配置错误（RF_BE_009 / 命令退出码 1）。
        $app = AppFactory::create(debug: true, forgeOverrides: [
            'levels'      => [
                'admin' => [
                    'description'         => '管理',
                    'match'               => [],
                    'load'                => 'lazy',
                    'endpoint_middleware' => ['forge\\middleware\\SelfMeta'],
                ],
                'public' => [
                    'description' => '公共',
                    'match'       => ['middleware' => ['forge\\middleware\\SelfMeta']],
                    'load'        => 'eager',
                ],
            ],
            'strict_mode' => true,
        ]);

        Route::get('admin/dash', function () {
            return 'dash';
        })->name('admin.dash')->tier('admin');

        // HTTP：自身端点未污染，取数不 500
        $response = Http::get($app, '/_forge/routes/public');
        self::assertSame(200, $response->getCode());

        // 命令：严格扫描无违规（自身端点未计入），退出码 0，且输出不含 forge 自身端点
        [$exit, $content] = $this->runCommand($app, 'route:forge:list', ['--json']);
        self::assertSame(0, $exit);
        self::assertStringNotContainsString('forge.routes', $content);
        self::assertStringNotContainsString('forge.manager', $content);
    }
}
