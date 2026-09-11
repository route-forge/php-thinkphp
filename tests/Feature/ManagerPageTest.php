<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\Response;

/**
 * 管理器页面：GET /_forge/manager（包内自包含模板直出，不依赖 think 视图引擎）。
 */
class ManagerPageTest extends TestCase
{
    private const LOCAL_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];

    private const LEVELS = [
        'public' => ['description' => '公共接口（无需登录）', 'match' => [], 'load' => 'eager'],
        'manage' => ['description' => '运营管理接口', 'match' => [], 'load' => 'lazy'],
    ];

    /**
     * @param array<string,mixed> $overrides forge 配置覆盖（levels 整体替换）
     * @param array<string,string> $server   请求服务器变量（含来源 IP / Accept）
     */
    private function page(array $overrides = [], array $server = self::LOCAL_SERVER, bool $debug = true): Response
    {
        $app = AppFactory::create(debug: $debug, forgeOverrides: $overrides + ['levels' => self::LEVELS]);

        return Http::get($app, '/_forge/manager', $server);
    }

    public function testPageRendersSelfContainedHtml(): void
    {
        $response = $this->page();

        self::assertSame(200, $response->getCode());
        self::assertStringContainsString('text/html', (string) $response->getHeader('Content-Type'));

        $html = (string) $response->getContent();

        self::assertStringContainsString('Route Forge 管理器', $html);
        self::assertStringContainsString('id="tier-cards"', $html);
        self::assertStringContainsString('id="le"', $html);
        self::assertStringContainsString('id="bsave"', $html);

        // 占位符必须全部替换，blade 语法不得残留
        self::assertStringNotContainsString('__FORGE_', $html);
        self::assertStringNotContainsString('@json', $html);
        self::assertStringNotContainsString('{{ ', $html);
    }

    public function testPageEmbedsLevelsAndGlobalsVerbatim(): void
    {
        $html = (string) $this->page()->getContent();

        // 中文层级描述原样注入（不被 \uXXXX 转义），且 tiers 概览数据齐备
        self::assertStringContainsString('公共接口（无需登录）', $html);
        self::assertStringContainsString('"tiers":[{"name":"public"', $html);
        self::assertStringContainsString('"load":"eager"', $html);
        self::assertStringContainsString('"cache_ttl":3600', $html);
        self::assertStringContainsString('"strict_mode":false', $html);
        self::assertStringContainsString('"url_prefix":null', $html);
        // 白名单展示值与守卫生效值同源
        self::assertStringContainsString('"manager_allowed_ips":["127.0.0.1","::1"]', $html);
    }

    /**
     * 宿主可控内容（层级 description）不得提前闭合脚本块。
     */
    public function testHostileLevelDescriptionCannotCloseScriptBlock(): void
    {
        $html = (string) $this->page([
            'levels' => [
                'xss' => [
                    'description' => '</script><script>alert(1)</script>',
                    'match'       => [],
                    'load'        => 'lazy',
                ],
            ],
        ])->getContent();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('\u003C/script\u003E', $html);
        // 整页只允许存在模板自身的那一个真实闭合标签
        self::assertSame(1, substr_count($html, '</script>'), '注入内容不得产生额外脚本闭合标签');
    }

    public function testSchemeVersionBadgeRendersAsInteger(): void
    {
        $html = (string) $this->page(['scheme_version' => 7])->getContent();

        self::assertStringContainsString('v7</span>', $html);
    }

    /**
     * 前端取数契约：一律走「当前页面路径 + /api/...」的相对形式，
     * 部署在子目录或改动 endpoint_prefix 时无需改模板；写死绝对路径即为回归。
     */
    public function testDataFetchesUsePrefixRelativePaths(): void
    {
        $html = (string) $this->page()->getContent();

        self::assertStringContainsString('window.location.pathname', $html);
        self::assertStringContainsString("'/api/routes'", $html);
        self::assertStringContainsString("'/api/config'", $html);
        self::assertStringNotContainsString('/_forge/manager/api', $html);
    }

    public function testPageForbiddenForForeignSource(): void
    {
        self::assertSame(403, $this->page(server: ['REMOTE_ADDR' => '10.0.0.5'])->getCode());
    }

    /**
     * 第一层防护：非 debug 环境页面路由根本不注册。
     */
    public function testPageNotRegisteredOutsideDebug(): void
    {
        // Accept: application/json 让 think 走 JSON 异常分支渲染 404，
        // 避开其 HTML 异常模板在 PHP 8.5 下的 htmlentities(null) 弃用告警
        $response = $this->page(
            server: self::LOCAL_SERVER + ['HTTP_ACCEPT' => 'application/json'],
            debug: false
        );

        self::assertSame(404, $response->getCode());
    }
}
