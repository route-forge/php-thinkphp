<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Support;

use ReflectionClass;
use ReflectionMethod;
use think\App;

/**
 * 自动路由扫描器：枚举「当前可被 ThinkPHP 自动路由触达」的 控制器 + 操作，
 * 供 route:forge:gen 生成显式 Route 规则（帮助习惯用自动路由的项目快速接入
 * route-forge）。仅扫描、不写文件、不判定幂等——那是命令层的事。
 *
 * 行为等价要点（与 think 的 route\dispatch\Controller 对齐）：
 *   - 动作段 = PHP 方法名原样（dispatch 以 URL 动作段为方法名调用，仅额外拼 action_suffix）；
 *     v1 假设未配 action_suffix，配了会在报告里提示复核。
 *   - 控制器段 = 类基名（去 controller_suffix、去子命名空间）经 parse_name 转 snake，
 *     与 dispatch 的 Str::studly 还原互逆；子目录/分层按 '/' 追加为路径段。
 *   - 仅取「本类自己声明」的 public 非魔术方法（排除从基类继承的 helper/生命周期方法）。
 *
 * 应用模式（单/多应用）判定 + 范围限定（模块 / 目录 / 命名空间）也在这里，用于防误用。
 */
final class AutoRouteScanner
{
    /** 明确归为「基类/框架」的命名空间前缀：这些类的 public 方法不作为端点 */
    private const BASE_CLASS_PREFIXES = ['think\\', 'app\\BaseController'];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * 判定应用模式：'single' | 'multi' | 'ambiguous'。
     *
     * 多应用需 topthink/think-multi-app（服务类存在或 vendor 目录在）：
     *  - 装了多应用、且 app/ 下只有模块级控制器层（无 app/{controller_layer}）→ 'multi'
     *  - 装了多应用、但 app/{controller_layer} 与模块控制器目录**并存** → 'ambiguous'：
     *    两种解读都成立，不替用户猜（历史上从单应用迁多应用的项目常留着 app/controller），
     *    由命令层要求显式 --mode 表态。
     *  - 未装多应用 → 'single'
     *
     * @return 'single'|'multi'|'ambiguous'
     */
    public function detectMode(): string
    {
        $appPath = rtrim($this->app->getAppPath(), '/\\');
        $layer = $this->controllerLayer();

        $multiInstalled = class_exists(\think\app\Service::class)
            || is_dir($this->app->getRootPath() . 'vendor/topthink/think-multi-app');
        $hasSingleDir = is_dir($appPath . DIRECTORY_SEPARATOR . $layer);
        $modules = $this->moduleDirs($appPath, $layer);

        if (!$multiInstalled || $modules === []) {
            return 'single';
        }

        return $hasSingleDir ? 'ambiguous' : 'multi';
    }

    /**
     * 多应用模式下的模块名（app/ 下含控制器层的子目录）。
     *
     * @return string[]
     */
    public function availableModules(): array
    {
        $appPath = rtrim($this->app->getAppPath(), '/\\');

        return $this->moduleDirs($appPath, $this->controllerLayer());
    }

    /**
     * 扫描可自动路由的端点。
     *
     * @param string      $mode        'single' | 'multi'（'ambiguous' 由命令层拦下，不会进到这里）
     * @param string[]    $modules     multi 模式下要扫的模块（已由命令层保证非空）
     * @param string|null $pathFilter  仅扫描此目录（绝对路径，须落在候选根内）
     * @param string|null $nsFilter    仅扫描此命名空间前缀（不含 app 根命名空间也可，按后缀匹配）
     *
     * @return list<array{
     *   outputRouteFile:string, targetFile:string, uri:string, target:string,
     *   name:string, class:string, method:string, module:?string,
     *   mixedCaseAction:bool, invokable?:bool, unreachable?:bool
     * }>
     */
    public function scan(string $mode, array $modules = [], ?string $pathFilter = null, ?string $nsFilter = null): array
    {
        $roots = [];
        if ($mode === 'multi') {
            foreach ($modules as $m) {
                $dir = rtrim($this->app->getAppPath(), '/\\') . DIRECTORY_SEPARATOR . $m
                    . DIRECTORY_SEPARATOR . $this->controllerLayer();
                $roots[] = [
                    'controllerDir' => $dir,
                    'namespace'     => $this->app->getNamespace() . '\\' . $m . '\\' . $this->controllerLayer(),
                    'module'        => $m,
                    // 多应用：写各模块自己的 route 目录，URI 不带模块前缀（MultiApp 已从路径剥离模块）
                    'routeFile'     => rtrim($this->app->getAppPath(), '/\\') . DIRECTORY_SEPARATOR . $m
                        . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR . 'forge.auto.php',
                    'uriPrefix'     => '',
                ];
            }
        } else {
            $dir = rtrim($this->app->getAppPath(), '/\\') . DIRECTORY_SEPARATOR . $this->controllerLayer();
            $roots[] = [
                'controllerDir' => $dir,
                'namespace'     => $this->app->getNamespace() . '\\' . $this->controllerLayer(),
                'module'        => null,
                'routeFile'     => $this->app->getRootPath() . 'route' . DIRECTORY_SEPARATOR . 'forge.auto.php',
                'uriPrefix'     => '',
            ];
        }

        $endpoints = [];
        foreach ($roots as $root) {
            if (!is_dir($root['controllerDir'])) {
                continue;
            }

            foreach ($this->controllerFiles($root['controllerDir'], $pathFilter) as $file) {
                $class = $this->classFromFile($file, $root['controllerDir'], $root['namespace']);
                if ($class === null) {
                    continue;
                }
                if ($nsFilter !== null && !$this->nsMatches($class, $nsFilter)) {
                    continue;
                }

                foreach ($this->controllerEndpoints($class, $root) as $ep) {
                    $endpoints[] = $ep;
                }
            }
        }

        return $endpoints;
    }

    /**
     * 列出某控制器类作为自动路由端点的 public 方法；invokable 控制器返回一条 __invoke 提示项。
     *
     * `unreachable` 行（action_suffix 项目里方法名不以 suffix 结尾 / 剔完为空段）没有可达
     * URL，命令层只登记提示、不生成；`mixedCaseAction` 行由命令层提示大小写风险。
     *
     * @param array{controllerDir:string,namespace:string,module:?string,routeFile:string,uriPrefix:string} $root
     *
     * @return list<array{outputRouteFile:string,targetFile:string,uri:string,target:string,name:string,class:string,method:string,module:?string,mixedCaseAction:bool,invokable?:bool,unreachable?:bool}>
     */
    private function controllerEndpoints(string $class, array $root): array
    {
        if (!class_exists($class)) {
            return [];
        }

        try {
            $rc = new ReflectionClass($class);
        } catch (\ReflectionException) {
            return [];
        }

        if ($rc->isInterface() || $rc->isAbstract() || $rc->isTrait() || !$rc->isUserDefined()) {
            return [];
        }
        foreach (self::BASE_CLASS_PREFIXES as $base) {
            if (str_starts_with($rc->getName() . '\\', $base)) {
                return [];
            }
        }

        $controllerSeg = $this->controllerUriSegments($class, $root['namespace']);
        $suffix = (string) $this->app->config->get('route.action_suffix', '');
        $rows = [];

        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $rm) {
            if ($rm->isStatic() || $rm->isAbstract() || str_starts_with($rm->getName(), '_')) {
                continue;
            }
            // 仅本类声明的方法（排除继承自基类的 helper / 生命周期方法）
            if ($rm->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }

            $action = $rm->getName();

            // think 的可达规则：URL 段拼上 action_suffix 才去 is_callable
            // （Dispatch::responseWithMiddlewarePipeline）。故方法名不以 suffix 结尾
            // 时根本无 URL 可达（除 __call 兜底），生成它等于凭空新增端点 → 只登记提示。
            if ($suffix !== '' && !str_ends_with($action, $suffix)) {
                $rows[] = $this->endpoint($root, $controllerSeg, $action, $class, $action)
                    + ['unreachable' => true];

                continue;
            }

            // 可达 URL = 剔掉 suffix 的短形式：batchView 在 suffix=View 时可达于 user/batch，
            // 生成成 user/batchView 就与现状 URL 不一致（切强制路由后旧链接 404）。
            if ($suffix !== '' && $action === $suffix) {
                // 剔完为空段，不构成可达 URL
                $rows[] = $this->endpoint($root, $controllerSeg, $action, $class, $action)
                    + ['unreachable' => true];

                continue;
            }

            $urlAction = $suffix !== '' ? substr($action, 0, -strlen($suffix)) : $action;

            $rows[] = $this->endpoint($root, $controllerSeg, $urlAction, $class, $action);
        }

        // invokable 控制器：无自有 public 方法但有 __invoke → 交人写（v1 不自动生成，仅登记提示）
        if ($rows === [] && $rc->hasMethod('__invoke')) {
            $rows[] = $this->endpoint($root, $controllerSeg, '', $class, '__invoke')
                + ['invokable' => true];
        }

        return $rows;
    }

    /**
     * 组装单个端点描述（uri / name / 显式规则目标串）。
     *
     * 注意动作段与控制器段的口径差异：控制器段一律 `Str::snake`，动作段沿用
     * think 反推出的 URL 形式。方法名含大写（`batchImport`）时会产出 snake+camel
     * 混排 URL —— 记入 `mixedCaseAction`，由命令层提示大小写风险。
     *
     * @param array{controllerDir:string,namespace:string,module:?string,routeFile:string,uriPrefix:string} $root
     * @param string[] $controllerSeg
     *
     * @return array{outputRouteFile:string,targetFile:string,uri:string,target:string,name:string,class:string,method:string,module:?string,mixedCaseAction:bool}
     */
    private function endpoint(array $root, array $controllerSeg, string $action, string $class, string $method): array
    {
        // 单应用 target = '控制器/动作'（与自动路由一致，走 Controller 派发、保留控制器中间件）
        // 多应用 target 同为控制器相对串（MultiApp 已按当前模块解析命名空间）
        $ctrlPath = implode('/', $controllerSeg);
        $uri = $root['uriPrefix'] . ($action !== '' ? $ctrlPath . '/' . $action : $ctrlPath);
        $target = $action !== '' ? $ctrlPath . '/' . $action : $ctrlPath;
        // 名字用点分（admin/dashboard 控制器 → admin.dashboard），与命名路由习惯一致
        $name = implode('.', $controllerSeg) . ($action !== '' ? '.' . $action : '');

        return [
            'outputRouteFile' => $root['routeFile'],
            'targetFile'      => $class,
            'uri'             => $uri,
            'target'          => $target,
            'name'            => $name,
            'class'           => $class,
            'method'          => $method,
            'module'          => $root['module'],
            'mixedCaseAction' => $action !== '' && preg_match('/[A-Z]/', $action) === 1,
        ];
    }

    /**
     * 控制器类 → URI 段数组：相对命名空间根的子目录 + 去后缀类基名，各段 snake 化。
     *
     * @return string[]
     */
    private function controllerUriSegments(string $class, string $namespacePrefix): array
    {
        $relative = substr($class, strlen($namespacePrefix) + 1); // e.g. Admin/UserProfile
        $parts = explode('\\', $relative);
        // controller_suffix 与 dispatch 同源（route 配置）
        $suffix = $this->app->config->get('route.controller_suffix') ? 'Controller' : '';
        $base = array_pop($parts);
        if ($suffix !== '' && str_ends_with($base, $suffix)) {
            $base = substr($base, 0, -strlen($suffix));
        }
        $segs = array_map(static fn (string $p): string => \think\helper\Str::snake($p), $parts);
        $segs[] = \think\helper\Str::snake($base);

        return $segs;
    }

    private function classFromFile(string $file, string $controllerDir, string $namespacePrefix): ?string
    {
        $relative = trim(substr($file, strlen($controllerDir)), '/\\');
        $relative = preg_replace('/\.php$/', '', $relative) ?? '';
        if ($relative === '') {
            return null;
        }
        $ns = str_replace('/', '\\', str_replace('\\', '/', dirname($relative)));
        $base = basename($relative, '.php');
        $ns = $ns === '.' || $ns === '' ? '' : '\\' . ltrim($ns, '\\');

        return $namespacePrefix . $ns . '\\' . $base;
    }

    /**
     * @return string[]
     */
    private function controllerFiles(string $dir, ?string $pathFilter): array
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        $files = [];
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
                continue;
            }
            $real = $f->getRealPath();
            if ($pathFilter !== null) {
                $filter = rtrim(str_replace('\\', '/', $pathFilter), '/');
                $needle = rtrim(str_replace('\\', '/', $real), '/');
                if (strpos($needle, $filter) !== 0 && strpos(str_replace('\\', '/', $real), $filter) !== 0) {
                    continue;
                }
            }
            $files[] = $real;
        }
        sort($files);

        return $files;
    }

    private function nsMatches(string $class, string $nsFilter): bool
    {
        $ns = str_replace('/', '\\', trim($nsFilter, '\\'));

        return stripos($class, $ns) === 0;
    }

    /**
     * @return string[]
     */
    private function moduleDirs(string $appPath, string $layer): array
    {
        $mods = [];
        foreach (glob($appPath . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $d) {
            $name = basename($d);
            if (in_array($name, ['controller', 'view', 'common'], true)) {
                continue;
            }
            if (is_dir($d . DIRECTORY_SEPARATOR . $layer)) {
                $mods[] = $name;
            }
        }
        sort($mods);

        return $mods;
    }

    private function controllerLayer(): string
    {
        return $this->app->config->get('app.controller_layer') ?: 'controller';
    }
}
