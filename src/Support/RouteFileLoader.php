<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\App;
use think\event\RouteLoaded;

/**
 * 路由文件加载器：console 场景下把应用的 `route/*.php` 按 HTTP 运行时的同一姿势灌进规则树。
 *
 * 为什么需要它（1.1.0 缺陷的根因）：ThinkPHP 8 里加载路由文件的**不是** `RouteLoaded` 事件，
 * 而是 `think\Http::loadRoutes()`——它先 `include` 完 `route/*.php`，**然后**才 trigger 该事件。
 * 事件的监听者只来自 `think\Service::loadRoutesFrom()`（即服务包自带路由，`Service` 把闭包
 * `event->listen(RouteLoaded::class, $closure)` 进去的），所以 console 里光 trigger 只能捞到
 * forge 自己的端点，应用路由一条都不进规则树。框架自带 `route:list` 同样不靠事件加载：
 * 它自己 `scanRoute()` include 完才 trigger（`console/command/RouteList.php`）。
 *
 * 口径与 HTTP 严格一致——「forge 看到的 == 运行时真在服务的」：
 *   - 目录取 `$app->http->getRoutePath()`，因此 `Http::setRoutePath()` 的覆写（多应用等场景）被尊重；
 *   - 只加载该目录下的扁平 `*.php`，**不递归子目录**：`Http::loadRoutes()` 的 glob 同样不递归，
 *     子目录里的路由文件在运行时根本不存在于规则树里；框架 route:list 按 `route_auto_group`
 *     递归它们是那条命令的展示福利，不是运行时行为。差异由 {@see warnings()} 明说，不静默吞掉；
 *   - `app.with_route=false` 时 think 连 `route/*.php` 都不 include（见 `Http::dispatchToRoute`），
 *     这里同步跳过，并同样给一条提示。
 *
 * 幂等：`$loaded` 标志保证同进程只 include 一次。路由文件里的 `Route::get()` 再执行一遍会注册出
 * **新的 RuleItem 对象**，`RouteCollector` 的 spl_object_id 去重挡不住，输出会整倍儿重复；
 * 故本类由 `ForgeService` 绑成容器单例，同进程内的多个命令共用这一个标志。
 *
 * 零侵入说明：只做「加载」这件事——不碰 `Route::clear()`、不改 `lazy()`、不重绑任何框架对象，
 * 加载结果与 HTTP 请求走完后规则树的样子一致。
 */
final class RouteFileLoader
{
    private bool $loaded = false;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * 加载应用的 `route/*.php`，并按 think 的顺序在其后 trigger `RouteLoaded`
     * （让服务包经 `loadRoutesFrom()` 注册的监听者照常执行）。重复调用无副作用。
     */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if ($this->runtimeLoadsRouteFiles()) {
            $dir = $this->app->http->getRoutePath();

            if (is_dir($dir)) {
                // glob 的返回顺序依赖文件系统，排序保证跨平台与多次运行的输出稳定
                // （加载集合与 HTTP 等价，仅顺序确定；HTTP 侧不排序是它的既有行为）
                $files = glob($dir . '*.php') ?: [];
                sort($files);

                foreach ($files as $file) {
                    // 比 Http::loadRoutes() 更严的一点：glob 也会匹配到**目录**（如写失败留下的
                    // 同名占位目录），include 目录只抛 E_WARNING，而 think 的 Error 初始化器
                    // 会把 warning 转成 ErrorException——一条形态异常的目录就能打挂整条命令。
                    // 不可读文件不在过滤之列：那是真故障，该响。
                    if (!is_file($file)) {
                        continue;
                    }

                    self::includeFile($file);
                }
            }
        }

        $this->app->event->trigger(RouteLoaded::class);
    }

    /**
     * 人类可读提示：把「运行时不会被加载的路由文件」摆到明面，供命令并入 warnings 走 STDERR。
     *
     * 与 load() 无耦合——只看配置与文件系统，因此命令在加载前后调用都给出同一答案。
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if (!$this->runtimeLoadsRouteFiles()) {
            $warnings[] = 'app.with_route=false：ThinkPHP 运行时不加载 route/*.php，'
                . '本命令只能看到服务（含 route-forge 自身）注册的路由。';
        }

        $skipped = $this->subdirectoryFiles();
        if ($skipped !== []) {
            $shown = array_slice($skipped, 0, 5);
            $warnings[] = 'route/ 子目录下的 ' . count($skipped) . ' 个路由文件在运行时不会被加载'
                . '（think 只 include route/*.php），已按运行时口径跳过：'
                . implode('、', $shown) . (count($skipped) > count($shown) ? ' 等' : '') . '。'
                . '框架 route:list 会按 route_auto_group 递归它们，那是那条命令的展示福利，不是运行时行为。';
        }

        return $warnings;
    }

    /** 本次进程是否已执行过加载（诊断与测试用）。 */
    public function loaded(): bool
    {
        return $this->loaded;
    }

    /**
     * think 运行时到底会不会 include 路由文件——判据与 `Http::dispatchToRoute()` 同源。
     */
    private function runtimeLoadsRouteFiles(): bool
    {
        return (bool) $this->app->config->get('app.with_route', true);
    }

    /**
     * include 放在静态方法里：闭包若在实例方法作用域内定义会绑上 `$this`（这里是加载器实例），
     * 路由文件不该写 `$this`，但没必要留这个口子（框架自带 route:list 就绑到了命令实例上）。
     */
    private static function includeFile(string $file): void
    {
        include $file;
    }

    /**
     * `route/` 子目录里的 `.php`（相对 `route/` 的路径，分隔符统一为 `/`，已排序）。
     *
     * @return list<string>
     */
    private function subdirectoryFiles(): array
    {
        $root = realpath($this->app->http->getRoutePath());

        if ($root === false) {
            return [];
        }

        $root    = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $found = [];

        foreach ($iterator as $item) {
            if (!$item->isFile() || !str_ends_with($item->getFilename(), '.php')) {
                continue;
            }

            $relative = substr(
                str_replace('\\', '/', $item->getPathname()),
                strlen($root)
            );

            // 顶层文件（不含 /）运行时会被加载，不属于本提示的范围
            if ($relative === '' || !str_contains($relative, '/')) {
                continue;
            }

            $found[] = $relative;
        }

        sort($found);

        return $found;
    }
}
