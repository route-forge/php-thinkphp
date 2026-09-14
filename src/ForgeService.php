<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP;

use Closure;
use RouteForge\Common\Alias\AliasResolver;
use RouteForge\Common\Analyzer\RouteAnalyzer;
use RouteForge\Common\Cache\RouteCache as CommonRouteCache;
use RouteForge\Common\Contract\ForgeExceptionContract;
use RouteForge\Common\Dto\RouteInfo;
use RouteForge\Common\Exception\CacheDriverException;
use RouteForge\Common\Exception\UnknownLevelException;
use RouteForge\Common\Filter\RouteNameFilter;
use RouteForge\Common\Repository\RouteRepository as CommonRouteRepository;
use RouteForge\Common\Summary\SummaryRenderer;
use RouteForge\Common\Tier\TierResolver as CommonTierResolver;
use RouteForge\ThinkPHP\Adapter\ThinkCacheAdapter;
use RouteForge\ThinkPHP\Adapter\ThinkRouteNormalizer;
use RouteForge\ThinkPHP\Console\RouteForgeClearCommand;
use RouteForge\ThinkPHP\Console\RouteForgeGenCommand;
use RouteForge\ThinkPHP\Console\RouteForgeListCommand;
use RouteForge\ThinkPHP\Console\RouteForgePublishCommand;
use RouteForge\ThinkPHP\Console\RouteForgeTypesCommand;
use RouteForge\ThinkPHP\Http\ForgeManagerController;
use RouteForge\ThinkPHP\Http\Middleware\ManagerAllowedIps;
use RouteForge\ThinkPHP\Support\AutoRouteScanner;
use RouteForge\ThinkPHP\Support\ConfigPublisher;
use RouteForge\ThinkPHP\Support\ManagerConfigStore;
use RouteForge\ThinkPHP\Support\ManagerPageRenderer;
use RouteForge\ThinkPHP\Support\RouteCollector;
use RouteForge\ThinkPHP\Support\RouteFileLoader;
use RouteForge\ThinkPHP\Support\ThinkRouteCollection;
use think\App;
use think\Service;

/**
 * Route Forge 服务接入（ThinkPHP 8 适配）。
 *
 * 注册方式（与 think 生态惯例一致）：
 *   1. composer extra.think.services 自动发现（think-installer 生成 vendor/services.php）；
 *   2. 或在 app/service.php 中手动追加 RouteForge\ThinkPHP\ForgeService::class。
 *
 * 对应 Laravel 版 ForgeServiceProvider 的职责裁剪（v1 范围）：
 *   - 绑定 common 层服务（RouteCache / TierResolver / RouteAnalyzer / RouteRepository）；
 *   - 注册元信息端点 GET /{endpoint_prefix}/{level} 与摘要端点 GET /{endpoint_prefix}；
 *   - 注册 route:forge:list / types / clear / publish / gen 五个 think console 命令；
 *     （gen：从自动路由增量物化显式命名路由，辅助习惯自动路由的项目快速接入）
 *   - 内嵌摘要经全局 helper forge_summary()（模板中 {:forge_summary()} 使用）；
 *   - 注册管理器 /_forge/manager（仅 app_debug=true）：路由总览、配置查看与编辑落盘，
 *     外加来源 IP 白名单守卫。
 *
 * 零侵入说明：ThinkPHP 无宏机制，->tier() / ->forgeAlias() 走 Rule::__call
 * 落 option，本服务不做任何 Router 重绑与继承链替换。
 */
class ForgeService extends Service
{
    /**
     * 框架内部路由前缀：think 自动路由的固定标识（经 RouteNameFilter 排除）。
     */
    private const FRAMEWORK_EXCLUDED_PREFIXES = ['__think_auto_route__'];

    /**
     * 管理器入口前缀：固定值，不随 endpoint_prefix 变化（与 laravel 版一致）。
     */
    private const MANAGER_PREFIX = '/_forge/manager';

    public function register(): void
    {
        // register() 在 RegisterService initializer 中执行，config 已于 App::load() 加载
        $this->registerBindings();
    }

    public function boot(): void
    {
        $this->registerMetadataEndpoints();
        $this->registerManagerRoutes();
        $this->commands([
            RouteForgeListCommand::class,
            RouteForgeTypesCommand::class,
            RouteForgeClearCommand::class,
            RouteForgePublishCommand::class,
            RouteForgeGenCommand::class,
        ]);
    }

    /**
     * 绑定 common 层核心服务（对应 Laravel 版 registerBindings）。
     */
    protected function registerBindings(): void
    {
        // 路由文件加载器（list / types / gen 三个命令共用）。必须是单例：它的进程内幂等标志
        // 要跨命令共享——路由文件被 include 第二遍会注册出**新的** RuleItem 对象，
        // RouteCollector 的 spl_object_id 去重挡不住，输出会整倍儿重复。
        $this->app->instance(RouteFileLoader::class, new RouteFileLoader($this->app));
        // 配置发布器（route:forge:publish 与三命令的缺配置守卫共用同一实例）
        $this->app->instance(ConfigPublisher::class, new ConfigPublisher($this->app));
        // 自动路由扫描器（route:forge:gen 使用）
        $this->app->instance(AutoRouteScanner::class, new AutoRouteScanner($this->app));
        // 管理器页面渲染器：包内自包含模板直出，不依赖 topthink/think-view
        $this->app->instance(
            ManagerPageRenderer::class,
            new ManagerPageRenderer(ManagerPageRenderer::packageTemplatePath())
        );
        $this->app->instance(CommonRouteCache::class, $this->makeRouteCache());
        $this->app->instance(CommonTierResolver::class, $this->makeTierResolver());
        $this->app->instance(RouteAnalyzer::class, $this->makeRouteAnalyzer());
        $this->app->instance(CommonRouteRepository::class, $this->makeRouteRepository());
        // 管理器配置落盘编排（生成 → 外壳适配 → 回读校验 → 备份写入 → 缓存失效）。
        // 必须在 RouteCache 绑定之后：它要拿到同一个缓存单例才能显式失效。
        $this->app->instance(ManagerConfigStore::class, new ManagerConfigStore(
            $this->app,
            $this->app->make(ConfigPublisher::class),
            $this->app->make(CommonRouteCache::class),
        ));
    }

    /**
     * RouteCache：think Cache store 桥接 common CacheInterface。
     *
     * debug 模式（app_debug=true）跳过全部缓存读写，路由变更即时生效；
     * cache_driver=null 使用默认缓存驱动。
     */
    protected function makeRouteCache(): CommonRouteCache
    {
        $driver = $this->config('cache_driver');

        try {
            $store = $this->app->cache->store($driver);
        } catch (\Throwable $e) {
            throw new CacheDriverException(
                'Cache driver [' . (is_string($driver) ? $driver : '(default)') . '] error: ' . $e->getMessage(),
                previous: $e,
            );
        }

        return new CommonRouteCache(
            new ThinkCacheAdapter($store),
            $this->app->isDebug(),
            $this->config('cache_ttl') !== null ? (int) $this->config('cache_ttl') : null,
        );
    }

    /**
     * TierResolver：classifier 回调按 think 类型书写（fn(\think\route\RuleItem $r): ?string），
     * 经包装后消费 RouteInfo（从 source 取回原始 RuleItem），与 Laravel 版同构。
     */
    protected function makeTierResolver(): CommonTierResolver
    {
        $classifier = $this->config('classifier');
        $classifier = is_callable($classifier) ? Closure::fromCallable($classifier) : null;

        if ($classifier !== null) {
            $userClassifier = $classifier;
            $classifier = static fn (RouteInfo $info): mixed => $userClassifier($info->source);
        }

        return new CommonTierResolver(
            levelsConfig: (array) $this->config('levels', []),
            classifier: $classifier,
            strictMode: (bool) $this->config('strict_mode', false),
            logger: $this->app->bound(\Psr\Log\LoggerInterface::class)
                ? $this->app->make(\Psr\Log\LoggerInterface::class)
                : null,
        );
    }

    /**
     * 路由名/URI 排除过滤器：命令层（list / types）与仓库（HTTP 端点）必须用同一份，
     * 否则两处口径分叉——此前两个 new 分别构造就是漂移源头。
     *
     * 排除两类：
     *   1. 框架内部路由前缀（__think_auto_route__）与 forge 自身端点名前缀
     *      （RouteNameFilter::FORGE_PREFIXES，随 withExtraPrefixes 一并带上）；
     *   2. forge 自身端点的 URI 前缀（endpoint_prefix，如 /_forge/routes）。
     *      未命名路由只能按 URI 排除：层级端点带 endpoint_middleware 时会被别的
     *      层级的 match.middleware 命中，不靠 URI 排除，包就会把自身端点报成
     *      「未命名路由落进层级」的配置错误（RouteAnalyzer / StrictViolationScanner 同口径）。
     */
    protected function makeNameFilter(): RouteNameFilter
    {
        return RouteNameFilter::withExtraPrefixes(self::FRAMEWORK_EXCLUDED_PREFIXES)
            ->withUriPrefixes([
                CommonRouteRepository::normalizeEndpointPrefix(
                    (string) $this->config('endpoint_prefix', '/_forge/routes'),
                ),
            ]);
    }

    /**
     * RouteAnalyzer：命令层（list / types）共用分析器。
     * 框架内部路由排除规则（__think_auto_route__）在此单点声明。
     */
    protected function makeRouteAnalyzer(): RouteAnalyzer
    {
        $filter = $this->makeNameFilter();

        return new RouteAnalyzer(
            tierResolver: $this->app->make(CommonTierResolver::class),
            aliasResolver: new AliasResolver(
                (array) $this->config('aliases', []),
                $filter,
            ),
            filter: $filter,
        );
    }

    /**
     * RouteRepository：think 路由树 + 归一化器 + tier 解析 + 缓存组合。
     * routes 为可重复迭代的实时集合视图（每次扫描重新收集）。
     */
    protected function makeRouteRepository(): CommonRouteRepository
    {
        return new CommonRouteRepository(
            routes: new ThinkRouteCollection(new RouteCollector($this->app->route)),
            normalizer: new ThinkRouteNormalizer(),
            tierResolver: $this->app->make(CommonTierResolver::class),
            cache: $this->app->make(CommonRouteCache::class),
            levelsConfig: (array) $this->config('levels', []),
            aliasesConfig: (array) $this->config('aliases', []),
            runtimeConfig: [
                'endpoint_prefix' => $this->config('endpoint_prefix', '/_forge/routes'),
                'url_prefix'      => $this->config('url_prefix'),
                'strict_mode'     => (bool) $this->config('strict_mode', false),
                'cache_ttl'       => $this->config('cache_ttl'),
                'scheme_version'  => $this->config('scheme_version', CommonRouteRepository::SCHEME_VERSION),
            ],
            filter: $this->makeNameFilter(),
        );
    }

    /**
     * 注册元信息端点 GET /{endpoint_prefix}/{level} 与摘要端点 GET /{endpoint_prefix}。
     *
     * 与 Laravel 版方案 B 同构：按层级注册独立路由（各自挂 endpoint_middleware），
     * 末尾注册兜底路由承接未知层级名（404 / RF_BE_002）。
     *
     * 注册时机：boot() 先于应用 route/*.php 文件加载（Http::dispatchToRoute
     * 才触发 RouteLoaded），forge 端点先入规则树、URI 唯一且 completeMatch，
     * 不与业务路由互相遮蔽。
     */
    protected function registerMetadataEndpoints(): void
    {
        $router = $this->app->route;
        $prefix = CommonRouteRepository::normalizeEndpointPrefix(
            (string) $this->config('endpoint_prefix', '/_forge/routes'),
        );

        $levels = (array) $this->config('levels', []);

        // 层级端点：每个层级独立路由，支持各自的 endpoint_middleware
        foreach (array_keys($levels) as $level) {
            $route = $router->get($prefix . '/' . $level, function () use ($level) {
                return $this->levelResponse((string) $level);
            })
                ->name('forge.routes.' . $level)
                ->completeMatch();

            // endpoint_middleware 与 think 的 ->middleware() 同形：数组或单个字符串都接受。
            // 入口必须自己 (array) 归一——该配置项不经 common 任何读取路径（common 只在
            // ConfigFileGenerator 写文件时归一，TierResolver 只吃 match 三项）。此前的裸值
            // is_array() 守卫遇单值写法会静默跳过：配置写了、中间件没挂，该层级元信息端点
            // 直接裸奔，属危险方向的静默失效（与下方摘要端点侧、laravel 版同口径）。
            $endpointMiddleware = (array) ($levels[$level]['endpoint_middleware'] ?? []);
            if ($endpointMiddleware !== []) {
                $route->middleware($endpointMiddleware);
            }
        }

        // 兜底路由：匹配不在 levels 中的层级名 → 404（RF_BE_002）
        $router->get($prefix . '/<level>', function (string $level) {
            return $this->levelResponse($level);
        })
            ->name('forge.routes.show')
            ->completeMatch();

        // 摘要端点：GET /{prefix}
        $summaryMiddleware = (array) $this->config('endpoint_middleware', []);
        $summaryRoute = $router->get($prefix, function () {
            return $this->summaryResponse();
        })
            ->name('forge.routes.index')
            ->completeMatch();

        if (count($summaryMiddleware) > 0) {
            $summaryRoute->middleware($summaryMiddleware);
        }
    }

    /**
     * 注册管理器路由（两层访问控制）。
     *
     * 第一层：非 debug 环境不注册任何管理器路由——生产环境连「403 探测面」都不给。
     * 判定必须走 isDebug()：think 只认 APP_DEBUG=0/1，`.env` 里 `app_debug=false`
     * 字符串对 think 的 env 解析是真值，读原始 env 会把生产当开发。
     * 第二层：ManagerAllowedIps 按 forge.manager_allowed_ips 限制来源 IP。
     *
     * 注册时机与元信息端点相同（boot 先于 route/*.php 加载），URI 唯一且
     * completeMatch，不与业务路由互相遮蔽。路由名统一带 forge.manager. 前缀，
     * common 的 RouteNameFilter::FORGE_PREFIXES 已含该前缀，故管理器自身不会出现在
     * 元信息端点与命令输出中，strict_mode 也不会因包自身路由未命中层级而必然抛错。
     */
    protected function registerManagerRoutes(): void
    {
        if (!$this->app->isDebug()) {
            return;
        }

        $router = $this->app->route;

        $routes = [
            $router->get(self::MANAGER_PREFIX, [ForgeManagerController::class, 'index'])
                ->name('forge.manager.index'),
            $router->get(self::MANAGER_PREFIX . '/api/routes', [ForgeManagerController::class, 'routes'])
                ->name('forge.manager.api.routes'),
            $router->get(self::MANAGER_PREFIX . '/api/config', [ForgeManagerController::class, 'config'])
                ->name('forge.manager.api.config'),
            $router->put(self::MANAGER_PREFIX . '/api/config', [ForgeManagerController::class, 'updateConfig'])
                ->name('forge.manager.api.config.update'),
        ];

        foreach ($routes as $route) {
            $route->completeMatch()->middleware([ManagerAllowedIps::class]);
        }
    }

    /**
     * 层级端点响应：Forge 异常 → [code, message, level] + 对应 HTTP 状态。
     */
    protected function levelResponse(string $level)
    {
        try {
            $payload = $this->app->make(CommonRouteRepository::class)->getRoutesByLevel($level);
        } catch (ForgeExceptionContract $e) {
            return json([
                'error' => [
                    'code'    => $e->code(),
                    'message' => $e->getMessage(),
                    'level'   => $level,
                ],
            ], $e->httpStatus());
        }

        return json($payload);
    }

    /**
     * 摘要端点响应。
     */
    protected function summaryResponse()
    {
        try {
            $payload = $this->app->make(CommonRouteRepository::class)->getSummary();
        } catch (ForgeExceptionContract $e) {
            return json([
                'error' => [
                    'code'    => $e->code(),
                    'message' => $e->getMessage(),
                ],
            ], $e->httpStatus());
        }

        return json($payload);
    }

    /**
     * 读取 forge 配置（config/forge.php，用户拷贝至应用 config 目录）。
     */
    protected function config(string $name, mixed $default = null): mixed
    {
        return $this->app->config->get('forge.' . $name, $default);
    }
}
