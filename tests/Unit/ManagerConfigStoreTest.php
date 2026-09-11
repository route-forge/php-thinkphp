<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\Common\Cache\RouteCache;
use RouteForge\ThinkPHP\Support\ConfigPublisher;
use RouteForge\ThinkPHP\Support\ManagerConfigStore;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\ArrayCacheStore;
use Throwable;

/**
 * 管理器落盘编排的两条硬不变量：缓存必须整体失效、值被改动时必须放弃写入。
 */
class ManagerConfigStoreTest extends TestCase
{
    private const LEVELS = [
        'public' => ['description' => '公共接口', 'match' => ['prefix' => ['auth']], 'load' => 'eager'],
        'manage' => ['description' => '运营接口', 'match' => ['prefix' => ['manage']], 'load' => 'lazy'],
    ];

    public function testSaveDropsCompiledConfigAndForgeCaches(): void
    {
        $app = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);

        // 真实桥接的 think 文件缓存在 debug 下会跳过读写，观测不到失效，故注入内存实现
        $store = new ArrayCacheStore();
        $cache = new RouteCache($store, false, 60);
        $cache->set('public', ['routes' => []]);
        $cache->set('summary', ['route_count' => 2]);
        self::assertNotEmpty($store->data, '预热应落下层级键与键索引');

        // think 的 App::load 命中 runtime/config.php 会用它整体覆盖配置，
        // 不清就会出现「页面提示保存成功、运行时仍读旧值」
        $compiled = $app->getRuntimePath() . 'config.php';
        self::assertNotFalse(file_put_contents($compiled, '<?php return [];'));

        (new ManagerConfigStore($app, new ConfigPublisher($app), $cache))
            ->save(self::LEVELS, ['strict_mode' => true]);

        self::assertFileDoesNotExist($compiled, '编译配置缓存必须随保存失效');
        self::assertSame([], $store->data, 'levels 变更后 forge 元信息缓存必须整体失效');
    }

    /**
     * 畸形/对抗性 description 的二选一性质：要么逐位保真地落盘，
     * 要么校验拦下且目标文件一个字节都不动。绝不接受第三种（写进被改坏的值）。
     */
    public function testMalformedDescriptionsEitherPersistVerbatimOrAbortWrite(): void
    {
        $app       = AppFactory::create(debug: true, forgeOverrides: ['levels' => self::LEVELS]);
        $publisher = new ConfigPublisher($app);
        $target    = $publisher->targetPath();
        $store     = new ManagerConfigStore($app, $publisher, new RouteCache(null, true, null));

        $descriptions = [
            "含换行\n与 \"双引号\" 和 '单引号' 以及 */ 与 /*",
            "\n    /*\n    | 层级定义表\n    |----------\n    */\n    'levels' =>",
            '</script><script>alert(1)</script>',
            "带 \\\\ 反斜杠、制表\t与 \\u4e2d 转义样文本",
        ];

        foreach ($descriptions as $description) {
            $before = (string) file_get_contents($target);
            $levels = [
                'odd' => [
                    'description' => $description,
                    'match'       => ['prefix' => ['odd']],
                    'load'        => 'lazy',
                ],
            ];

            try {
                $store->save($levels, []);
            } catch (Throwable $e) {
                self::assertSame(
                    $before,
                    (string) file_get_contents($target),
                    '校验不通过时必须放弃写入，不留半个文件：' . $e->getMessage()
                );

                continue;
            }

            $loaded = include $target;

            self::assertSame(
                $description,
                $loaded['levels']['odd']['description'],
                '保存成功时 description 必须逐位一致'
            );
        }
    }
}
