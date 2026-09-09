<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Fixtures;

/**
 * 测试专用中间件：短路由请求（模拟未通过鉴权，直接 401）。
 *
 * 用于验证 endpoint_middleware / endpoint_middleware 配置确实挂到了
 * forge 端点路由上（命中即 401，不进控制器）。
 */
class DenyAllMiddleware
{
    public function handle($request, \Closure $next)
    {
        return json(['error' => 'denied'], 401);
    }
}
