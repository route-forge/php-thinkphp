<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Http\Middleware\ManagerAllowedIps;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use RouteForge\ThinkPHP\Tests\Support\Http;
use think\App;

/**
 * 管理器来源 IP 白名单守卫（forge.manager_allowed_ips 语义矩阵）。
 *
 * 与 laravel 版 ManagerAllowedIpsTest 逐条对齐，另加本包特有口径：
 * 「键缺失」与「显式 null」必须给出相反答案（前者仅本机，后者不限制）。
 */
class ManagerAllowedIpsTest extends TestCase
{
    private const ENDPOINT = '/_forge/manager/api/config';

    /**
     * @param array<string,mixed> $forgeOverrides
     */
    private function request(array $forgeOverrides, ?string $ip): int
    {
        $app = $this->app($forgeOverrides);

        $server = $ip === null ? [] : ['REMOTE_ADDR' => $ip];

        return Http::get($app, self::ENDPOINT, $server)->getCode();
    }

    /**
     * @param array<string,mixed> $forgeOverrides
     */
    private function app(array $forgeOverrides = []): App
    {
        return AppFactory::create(debug: true, forgeOverrides: $forgeOverrides + [
            'levels' => ['public' => ['description' => '公共', 'match' => [], 'load' => 'eager']],
        ]);
    }

    public function testKeyAbsentFallsBackToLoopbackOnly(): void
    {
        // AppFactory 的内联默认不含该键，即「已发布旧配置的项目升级后」的真实形态
        self::assertSame(200, $this->request([], '127.0.0.1'));
        self::assertSame(200, $this->request([], '::1'));
        self::assertSame(403, $this->request([], '10.0.0.5'));
    }

    public function testExplicitListAllowsOnlyListedSources(): void
    {
        self::assertSame(200, $this->request(['manager_allowed_ips' => ['10.0.0.5']], '10.0.0.5'));
        self::assertSame(403, $this->request(['manager_allowed_ips' => ['10.0.0.5']], '192.168.1.1'));
    }

    public function testWildcardEntryAllowsAnySource(): void
    {
        self::assertSame(200, $this->request(['manager_allowed_ips' => ['*']], '203.0.113.9'));
    }

    /**
     * 归一化红线：单值字符串与只含它的数组同形。
     * 裸值 is_array() 守卫会把单值写法整段丢掉 → 白名单静默失效、页面对任意来源开放。
     */
    public function testSingleStringEqualsSingleElementArray(): void
    {
        self::assertSame(200, $this->request(['manager_allowed_ips' => '10.0.0.5'], '10.0.0.5'));
        self::assertSame(403, $this->request(['manager_allowed_ips' => '10.0.0.5'], '10.0.0.6'));
    }

    public function testNullExplicitlyDisablesIpRestriction(): void
    {
        // 与「键缺失」相反：显式 null = 开发者主动放开，不得被 think Config::get 的
        // isset() 判定误读成缺失而回落到仅本机
        self::assertSame(200, $this->request(['manager_allowed_ips' => null], '203.0.113.9'));
    }

    public function testEmptyArrayDisablesIpRestriction(): void
    {
        self::assertSame(200, $this->request(['manager_allowed_ips' => []], '203.0.113.9'));
    }

    public function testEntriesAreTrimmedBeforeMatching(): void
    {
        self::assertSame(200, $this->request(['manager_allowed_ips' => ['  10.0.0.5  ']], '10.0.0.5'));
    }

    public function testUnknownSourceIsDeniedWhenRemoteAddrMissing(): void
    {
        // think 的 Request::ip() 在无 REMOTE_ADDR 时返回空串，不匹配任何白名单条目
        self::assertSame(403, $this->request([], null));
    }

    public function testDefaultConstantMatchesLaravelSide(): void
    {
        self::assertSame(['127.0.0.1', '::1'], ManagerAllowedIps::DEFAULT_ALLOWED_IPS);
    }
}
