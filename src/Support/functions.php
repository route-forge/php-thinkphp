<?php

declare(strict_types=1);

use RouteForge\Common\Repository\RouteRepository as CommonRouteRepository;
use RouteForge\Common\Summary\SummaryRenderer;

if (!function_exists('forge_summary')) {
    /**
     * 内嵌摘要 helper（Laravel @forgeSummary 指令的 ThinkPHP 等价物）。
     *
     * 全局函数（与 think helper.php 同惯例）：命名空间函数无法 autoload，
     * 且需在任意模板 {:forge_summary()} 处直接可用。
     *
     * 在服务端渲染模板的 <head> 内、早于前端 bundle 处使用：
     *
     *     <head>
     *         {:forge_summary()}
     *     </head>
     *
     * 输出一段 <script>，以一次性、消费即自删、不可枚举的 window.__ROUTE_FORGE__
     * 访问器暴露摘要端点的返回值，@route-forge/core 读取后跳过首屏的摘要 HTTP 往返。
     *
     * 契约与红线（对齐 common SummaryRenderer）：
     *   - 复用 RouteRepository::getSummary() 同一 producer，继承其缓存语义；
     *   - 只嵌摘要，不嵌层级路由表；XSS 安全编码；不递增 schemeVersion。
     *
     * 返回值应原样输出，勿再经模板转义。
     */
    function forge_summary(): string
    {
        $container = \think\Container::getInstance();

        // 未注册 ForgeService 时容器无法解析出 RouteRepository（其构造依赖接口+可迭代集合），
        // 与其抛「cannot resolve parameter」栈，不如直接给可操作的提示
        if (!$container->bound(CommonRouteRepository::class)) {
            throw new \RuntimeException(
                'forge_summary() 不可用：请先注册 RouteForge\ThinkPHP\ForgeService'
                . '（composer 自动发现，或在 app/service.php 追加该服务类）。',
            );
        }

        return SummaryRenderer::render($container->make(CommonRouteRepository::class)->getSummary());
    }
}
