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
     * 以 JSON 请求体发 PUT（管理器写盘 API 用）。
     *
     * 两处顺序/细节有讲究：
     *   - think 的 contentType() 读 header 集合，而该集合正常由 Request::__make 从
     *     $_SERVER['CONTENT_TYPE'] 派生为连字符键；测试里 new Request 绕过了 __make，
     *     所以必须显式 withHeader(['content-type' => ...])——withHeader 只小写化、
     *     不做下划线转连字符，写成 CONTENT_TYPE 会查不到，raw body 不按 JSON 解析，
     *     $request->put 停在 null 并在 input() 的类型声明上抛 TypeError。
     *   - withInput() 依赖 contentType 判定解析方式，故必须在 withHeader 之后。
     *
     * @param array<string,mixed>  $payload
     * @param array<string,string> $server
     */
    public static function putJson(App $app, string $path, array $payload, array $server = []): Response
    {
        $request = new Request();
        $request->setMethod('PUT');
        $request->setPathinfo(ltrim($path, '/'));
        $request->withServer(['CONTENT_TYPE' => 'application/json'] + $server);
        $request->withHeader(['content-type' => 'application/json']);
        $request->withInput((string) json_encode($payload));

        return $app->http->run($request);
    }

    /**
     * @return array<string,mixed>
     */
    public static function getJson(App $app, string $path, array $server = []): array
    {
        $response = self::get($app, $path, $server);
        $decoded = json_decode($response->getContent(), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Non-JSON response: ' . $response->getContent());
        }

        return $decoded;
    }
}
