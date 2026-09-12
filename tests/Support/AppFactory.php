<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Support;

use RouteForge\ThinkPHP\ForgeService;
use think\App;

/**
 * 测试专用 think 应用工厂：构造最小可运行的 ThinkPHP 8 应用
 * （无骨架依赖），注册 ForgeService 并 boot，供端点/命令/扫描测试复用。
 *
 * 临时目录策略：优先 RF_TEST_TMP 环境变量；本机开发约定 F:\tmp；
 * CI（无 F:\tmp）回退系统临时目录。每个 App 独立临时根目录，互不污染。
 */
final class AppFactory
{
    private static int $seq = 0;

    /**
     * @var array<string,string> 本进程创建过的临时根目录（测试结束后可观测）
     */
    private static array $roots = [];

    /**
     * @param array<string,string> $routeFiles 相对 route/ 的路径 => 文件内容；
     *                                         用于覆盖「路由来自路由文件」这条真实加载路径
     *                                         （测试代码直接 Route::get() 注册是另一条路，
     *                                         1.1.0 的命令侧缺陷正是被它整体遮蔽掉的）
     */
    public static function create(bool $debug = false, array $forgeOverrides = [], ?\Closure $classifier = null, bool $lazyRoute = false, bool $writeForgeConfig = true, array $routeFiles = []): App
    {
        $root = self::makeRoot();
        self::writeConfigs($root, $debug, $forgeOverrides, $lazyRoute, $writeForgeConfig, $routeFiles);

        $app = new App($root);
        $app->initialize();

        // 卸载 think Error initializer 注册的全局 error/exception handler，
        // 避免 PHPUnit 报 risky（http->run 的异常渲染走内部 try/catch，不依赖全局 handler）
        restore_error_handler();
        restore_exception_handler();

        // classifier 闭包无法序列化进配置文件，经内存 Config 注入
        // （ForgeService::register 在此之后执行，读到的即是内存值）
        if ($classifier !== null && is_array($app->config->get('forge'))) {
            $forge = $app->config->get('forge');
            $forge['classifier'] = $classifier;
            $app->config->set(['forge' => $forge]);
        }

        $service = new ForgeService($app);
        $app->register($service);
        $app->bootService($service);

        return $app;
    }

    public static function roots(): array
    {
        return self::$roots;
    }

    private static function makeRoot(): string
    {
        $base = getenv('RF_TEST_TMP') ?: (is_dir('F:/tmp') ? 'F:/tmp' : sys_get_temp_dir());
        $root = $base . DIRECTORY_SEPARATOR . 'route-forge-thinkphp-' . getmypid()
            . '-' . (++self::$seq) . '-' . bin2hex(random_bytes(3));

        foreach (['config', 'runtime', 'route', 'app'] as $dir) {
            if (!mkdir($root . DIRECTORY_SEPARATOR . $dir, 0777, true)) {
                throw new \RuntimeException('mkdir failed: ' . $root . DIRECTORY_SEPARATOR . $dir);
            }
        }

        self::$roots[] = $root;

        return $root;
    }

    private static function writeConfigs(string $root, bool $debug, array $forgeOverrides, bool $lazyRoute = false, bool $writeForgeConfig = true, array $routeFiles = []): void
    {
        $put = static function (string $rel, string $content) use ($root): void {
            file_put_contents($root . DIRECTORY_SEPARATOR . $rel, $content);
        };

        // 路由文件先落盘：下面的「未发布 forge 配置」分支会提前 return，不能写在它之后
        foreach ($routeFiles as $rel => $content) {
            $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rel);
            $dir        = dirname($root . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR . $normalized);

            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException('mkdir failed: ' . $dir);
            }

            $put('route' . DIRECTORY_SEPARATOR . $normalized, $content);
        }

        // .env：think 只认 APP_DEBUG=0/1（'false' 字符串为真值，勿用）
        $put('.env', 'APP_DEBUG=' . ($debug ? '1' : '0') . "\n");

        $put('config/app.php', "<?php return [\n    'default_timezone' => 'Asia/Shanghai',\n];\n");
        $put('config/route.php', "<?php return ['url_lazy_route' => " . var_export($lazyRoute, true) . "];\n");
        $put('config/cache.php', "<?php return [\n"
            . "    'default' => 'file',\n"
            . "    'stores' => ['file' => ['type' => 'file', 'path' => '" . $root . "/runtime/cache']],\n];\n");

        // forge 配置：测试内联默认（不经包 config/forge.php——其中 Env facade
        // 依赖已 boot 的容器）+ 测试覆盖（浅合并，levels 覆盖需整体替换）
        if (!$writeForgeConfig) {
            // 模拟「开发者尚未复制配置」场景：不写 config/forge.php
            return;
        }

        $forge = array_merge([
            'levels'              => [],
            'endpoint_prefix'     => '/_forge/routes',
            'url_prefix'          => null,
            'endpoint_middleware' => [],
            'cache_ttl'           => 3600,
            'cache_driver'        => null,
            'strict_mode'         => false,
            'scheme_version'      => 1,
            'classifier'          => null,
            'aliases'             => [],
        ], $forgeOverrides);
        $put('config/forge.php', "<?php return " . var_export($forge, true) . ";\n");
    }
}
