<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Support;

use think\App;
use think\Request;
use think\Response;

/**
 * 测试工具：构造请求并走完整 HTTP 流（中间件管线 + 路由调度），
 * 返回 Response 供断言。
 */
final class Http
{
    public static function get(App $app, string $path, array $server = []): Response
    {
        $request = new Request();
        $request->setMethod('GET');
        // 生产环境 pathinfo 不带前导斜杠（与 REQUEST_URI 解析结果一致）
        $request->setPathinfo(ltrim($path, '/'));
        $request->withServer($server);

        return $app->http->run($request);
    }

    /**
     * @return array<string,mixed>
     */
    public static function getJson(App $app, string $path): array
    {
        $response = self::get($app, $path);
        $decoded = json_decode($response->getContent(), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Non-JSON response: ' . $response->getContent());
        }

        return $decoded;
    }
}
