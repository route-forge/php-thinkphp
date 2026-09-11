<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Http;

use RouteForge\Common\Repository\RouteRepository;
use RouteForge\ThinkPHP\Http\Middleware\ManagerAllowedIps;
use RouteForge\ThinkPHP\Support\ManagerConfigStore;
use RouteForge\ThinkPHP\Support\ManagerPageRenderer;
use think\facade\Config;
use think\facade\Log;
use think\Request;
use think\Response;
use Throwable;

/**
 * 管理器控制器：仅在开发环境（app_debug=true）下注册与可用。
 *
 * 提供可视化路由管理面板的数据侧能力：
 *   - 路由列表与层级总览（含别名条目）
 *   - 当前配置展示（levels + 全局设置）
 *   - 配置编辑与落盘（见 updateConfig）
 *
 * 与 laravel 版的分工一致：配置文件的 PHP 源码生成由 common 层 ConfigFileGenerator
 * 完成（框架无关的转义与注入防护），本控制器只负责请求校验、think 侧外壳适配与写盘。
 */
class ForgeManagerController
{
    public function __construct(
        private readonly RouteRepository $repository,
        private readonly ManagerPageRenderer $page,
    ) {
    }

    /**
     * 管理器页面（HTML）。
     *
     * 页面由包内自包含模板直出（见 ManagerPageRenderer），不依赖 think 视图引擎；
     * 前端取数走同前缀下的相对路径 API，故 endpoint_prefix 与部署子路径都不影响。
     */
    public function index(): Response
    {
        $levelsConfig = (array) Config::get('forge.levels', []);

        $tiers = [];
        foreach ($levelsConfig as $name => $cfg) {
            $cfg     = (array) $cfg;
            $tiers[] = [
                'name'        => (string) $name,
                'description' => (string) ($cfg['description'] ?? ''),
                'load'        => (string) ($cfg['load'] ?? 'lazy'),
            ];
        }

        $global = $this->globalConfig();

        return Response::create($this->page->render([
            'tiers'        => $tiers,
            'levelsConfig' => $levelsConfig,
            'globalConfig' => $global,
        ], (int) ($global['scheme_version'] ?? 1)), 'html');
    }

    /**
     * API：获取所有命名路由及其层级分配（JSON）。
     */
    public function routes(): Response
    {
        $data = $this->repository->getAllRoutesWithTiers();

        return json([
            'routes' => $data['routes'],
            'tiers'  => $data['tiers'],
        ]);
    }

    /**
     * API：获取当前配置（JSON）。
     */
    public function config(): Response
    {
        return json([
            'levels' => (array) Config::get('forge.levels', []),
            'global' => $this->globalConfig(),
        ]);
    }

    /**
     * API：更新配置文件（levels + 全局设置）。
     *
     * 校验口径与 laravel 版一致：两半都必须是数组；配置了 classifier 时直接拒存——
     * 闭包无法序列化进配置文件，让生成器把它抹平成 null 等于静默丢掉用户的分类逻辑。
     *
     * 写盘细节只进日志不回显：异常消息可能带服务器绝对路径，而管理器页面的可达面
     * 不等于可信面（manager_allowed_ips 可被显式配成 '*'）。
     */
    public function updateConfig(Request $request, ManagerConfigStore $store): Response
    {
        $levels = $request->put('levels');
        $global = $request->put('global');

        if (!is_array($levels)) {
            return json(['error' => 'levels 必须是对象（层级名 → 层级配置）'], 422);
        }

        if (!is_array($global)) {
            return json(['error' => 'global 必须是对象（全局设置）'], 422);
        }

        if (Config::get('forge.classifier') !== null) {
            return json([
                'error' => 'config/forge.php 中配置了 classifier 回调，'
                    . '管理器无法把闭包序列化进配置文件，请手工编辑 config/forge.php。',
            ], 422);
        }

        try {
            $result = $store->save($levels, $global);
        } catch (Throwable $e) {
            Log::error('[route-forge] 管理器保存配置失败：' . $e->getMessage());

            return json(['error' => '配置写入失败，详情见应用日志。'], 500);
        }

        return json([
            'success'   => true,
            'message'   => '配置已保存并生效',
            // 只回「是否留了备份」，不回绝对路径
            'backed_up' => $result['backup'] !== null,
        ]);
    }

    /**
     * 管理器页面与 config API 共用的全局配置展示结构。
     *
     * 与写盘侧的 preserved 键集合保持一致：endpoint_middleware / manager_allowed_ips /
     * aliases 不在表单中编辑（保存时原样透传），只读展示便于审计。
     *
     * @return array<string, mixed>
     */
    private function globalConfig(): array
    {
        return [
            'endpoint_prefix'     => (string) Config::get('forge.endpoint_prefix', '/_forge/routes'),
            'url_prefix'          => Config::get('forge.url_prefix'),
            'cache_ttl'           => Config::get('forge.cache_ttl'),
            'cache_driver'        => Config::get('forge.cache_driver'),
            'strict_mode'         => (bool) Config::get('forge.strict_mode', false),
            'scheme_version'      => (int) Config::get('forge.scheme_version', 1),
            // 与守卫同源：展示的就是真正生效的那份白名单
            'manager_allowed_ips' => ManagerAllowedIps::allowedIps(),
            'aliases'             => (array) Config::get('forge.aliases', []),
        ];
    }
}
