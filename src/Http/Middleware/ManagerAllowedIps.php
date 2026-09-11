<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Http\Middleware;

use Closure;
use think\facade\Config;
use think\Request;
use think\Response;

/**
 * 管理器路由的来源 IP 白名单守卫（ThinkPHP 8 适配，语义与 laravel 版逐条对齐）。
 *
 * 挂在 /_forge/manager* 路由上，按 forge.manager_allowed_ips 限制可访问来源：
 *   - IP 列表（精确匹配）：仅列表中的来源可访问；元素 '*' 放行任意来源；
 *     单个字符串（如 '192.168.1.10'）等价于只含它的数组；
 *   - null 或空数组：不做 IP 限制（开发者显式放开，局域网环境需注意暴露风险）；
 *   - 键缺失：按 self::DEFAULT_ALLOWED_IPS 处理（仅本机回环）；
 *   - 匹配失败 → 403。
 *
 * 生产环境不依赖本守卫：app_debug=false 时管理器路由根本不注册
 * （见 ForgeService::registerManagerRoutes），两层防护互为兜底。
 */
class ManagerAllowedIps
{
    /**
     * 配置键名（位于 forge 配置组下）。
     */
    public const CONFIG_KEY = 'manager_allowed_ips';

    /**
     * 键缺失时的默认白名单：仅本机回环。
     * 浏览器访问 localhost 时可能解析为 IPv6 的 ::1，故与 127.0.0.1 一并放行。
     */
    public const DEFAULT_ALLOWED_IPS = ['127.0.0.1', '::1'];

    /**
     * 生效白名单（已转字符串并去空白）；空数组 = 不做 IP 限制。
     *
     * 守卫与管理器展示侧共用本方法，使同一配置键的两个读取点对「键缺失」
     * 不再给出相反答案。
     *
     * 为什么不走 Config::get('forge.manager_allowed_ips', $default)：think 的点号路径
     * 以 isset() 判定命中，显式写成 null（语义＝不限制）会被误当成「键缺失」而返回
     * 默认值，把开发者主动放开读成仅本机放行。故整组取出后按 array_key_exists 判别。
     *
     * @return string[]
     */
    public static function allowedIps(): array
    {
        $forge = Config::get('forge');

        $raw = is_array($forge) && array_key_exists(self::CONFIG_KEY, $forge)
            ? $forge[self::CONFIG_KEY]
            : self::DEFAULT_ALLOWED_IPS;

        // 入口统一 (array) 归一：单值字符串与只含它的数组同形。此前的裸值 is_array()
        // 守卫会把单值写法整段丢掉——配置写了、白名单没挂，管理器页面对任意来源开放，
        // 属「失保护」的危险方向。
        return array_values(array_map(
            static fn ($entry): string => trim((string) $entry),
            (array) $raw
        ));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $allowed = self::allowedIps();

        // 空数组 / null = 开发者显式放开，不做 IP 限制
        if ($allowed !== []) {
            $ip = $request->ip();

            foreach ($allowed as $entry) {
                if ($entry === '*' || $entry === $ip) {
                    return $next($request);
                }
            }

            return Response::create(
                'Route Forge manager is not accessible from this IP address.',
                'html',
                403
            );
        }

        return $next($request);
    }
}
