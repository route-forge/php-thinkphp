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
use RouteForge\ThinkPHP\Support\AutoRouteScanner;
use RouteForge\ThinkPHP\Support\ConfigPublisher;
use RouteForge\ThinkPHP\Support\ThinkRouteCollection;
use RouteForge\ThinkPHP\Support\RouteCollector;
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
 *   - 内嵌摘要经全局 helper forge_summary()（模板中 {:forge_summary()} 使用）。
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

    public function register(): void
    {
        // register() 在 RegisterService initializer 中执行，config 已于 App::load() 加载
        $this->registerBindings();
    }

    public function boot(): void
    {
        $this->registerMetadataEndpoints();
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
        // 配置发布器（route:forge:publish 与三命令的缺配置守卫共用同一实例）
        $this->app->instance(ConfigPublisher::class, new ConfigPublisher($this->app));
        // 自动路由扫描器（route:forge:gen 使用）
        $this->app->instance(AutoRouteScanner::class, new AutoRouteScanner($this->app));
        $this->app->instance(CommonRouteCache::class, $this->makeRouteCache());
        $this->app->instance(CommonTierResolver::class, $this->makeTierResolver());
        $this->app->instance(RouteAnalyzer::class, $this->makeRouteAnalyzer());
        $this->app->instance(CommonRouteRepository::class, $this->makeRouteRepository());
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
     * RouteAnalyzer：命令层（list / types）共用分析器。
     * 框架内部路由排除规则（__think_auto_route__）在此单点声明。
     */
    protected function makeRouteAnalyzer(): RouteAnalyzer
    {
        $filter = RouteNameFilter::withExtraPrefixes(self::FRAMEWORK_EXCLUDED_PREFIXES);

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
            filter: RouteNameFilter::withExtraPrefixes(self::FRAMEWORK_EXCLUDED_PREFIXES),
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
