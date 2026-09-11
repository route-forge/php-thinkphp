<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use RouteForge\Common\Config\ConfigFileGenerator;
use RouteForge\ThinkPHP\Support\ThinkConfigFileStyler;

/**
 * ThinkPHP 侧配置外壳适配：只动外壳、不动值，且对未知键与坏产物 fail-fast。
 */
class ThinkConfigFileStylerTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->tmpFiles = [];
    }

    public function testReplacesLaravelHeaderAndBarrelComments(): void
    {
        $generated = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/**\n * Route Forge 配置文件（由管理器页面生成）\n *\n"
            . " * @see .docs/SPEC.md §3.1.2, §5\n */\n"
            . "return [\n\n"
            . "    /*\n    |----------\n    | 层级定义表\n    |----------\n    */\n"
            . "    'levels'            => [\n        'public' => [\n            'description' => '公共接口',\n        ],\n    ],\n\n"
            . "    /*\n    |----------\n    | 缓存 TTL（秒）\n    |----------\n    */\n"
            . "    'cache_ttl'         => 3600,\n];\n";

        $styled = (new ThinkConfigFileStyler())->style($generated);

        // Laravel 桶形注释与指向 laravel 仓文档的头注释都不该留在 think 项目里
        self::assertStringNotContainsString('|---', $styled);
        self::assertStringNotContainsString('.docs/SPEC.md', $styled);
        self::assertStringNotContainsString('declare(strict_types=1);', $styled);

        self::assertStringContainsString('// | Route Forge 配置（由管理器页面保存生成）', $styled);
        self::assertStringContainsString("    // 层级定义表\n    'levels' => [\n", $styled);
        self::assertStringContainsString("    // 缓存 TTL（秒）\n    'cache_ttl' => 3600,", $styled);
        self::assertSame($this->evaluate($generated), $this->evaluate($styled), '外壳适配不得改动任何值');
    }

    /**
     * 真走一遍 common 生成器：11 个键全部转换、值逐位不变。
     */
    public function testRealGeneratorOutputIsFullyConverted(): void
    {
        $generated = (new ConfigFileGenerator())->generate(
            [
                'public' => [
                    'description'         => '公共接口（无需登录）',
                    'match'               => ['prefix' => ['auth'], 'middleware' => []],
                    'load'                => 'eager',
                    'endpoint_middleware' => ['sign'],
                ],
            ],
            [
                'endpoint_prefix' => '/_forge/routes',
                'url_prefix'      => 'https://api.example.com',
                'cache_ttl'       => 60,
                'cache_driver'    => 'redis',
                'strict_mode'     => true,
                'scheme_version'  => 2,
            ],
            [
                'endpoint_middleware'   => ['sign'],
                'manager_allowed_ips'   => ['10.0.0.5'],
                'aliases'               => ['legacy.login' => 'auth.login'],
            ]
        );

        $styled = (new ThinkConfigFileStyler())->style($generated);

        self::assertStringNotContainsString('/*', $styled, 'common 的每个桶形注释块都必须被转换');
        self::assertStringNotContainsString('|---', $styled);
        self::assertStringContainsString('// 层级定义表', $styled);
        self::assertStringContainsString('// 管理器页面允许访问的 IP 列表', $styled);
        self::assertStringContainsString("// 路由别名映射表（aliases）\n    'aliases' =>", $styled);

        $loaded = $this->evaluate($styled);

        self::assertSame($this->evaluate($generated), $loaded, '值不变性');
        self::assertSame('公共接口（无需登录）', $loaded['levels']['public']['description']);
        self::assertSame(['10.0.0.5'], $loaded['manager_allowed_ips']);
        self::assertSame(['legacy.login' => 'auth.login'], $loaded['aliases']);
        self::assertNull($loaded['classifier'], 'classifier 必须由 common 强制为 null（闭包无法序列化）');
    }

    public function testUnknownKeyInGeneratedOutputFailsFast(): void
    {
        // common 新增字段而本包白名单未同步时，宁可报错也不留下未转换的桶形注释
        $generated = "<?php\nreturn [\n\n"
            . "    /*\n    |----------\n    | 新字段\n    |----------\n    */\n"
            . "    'brand_new' => 1,\n];\n";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('未知键');

        (new ThinkConfigFileStyler())->style($generated);
    }

    public function testOutputWithoutReturnHeaderFailsFast(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('缺少');

        (new ThinkConfigFileStyler())->style('<?php return $notAnArrayLiteral;');
    }

    /**
     * 把配置文件源码写成临时探针并回读为数组。
     *
     * @return array<string,mixed>
     */
    private function evaluate(string $content): array
    {
        $base = getenv('RF_TEST_TMP') ?: (is_dir('F:/tmp') ? 'F:/tmp' : sys_get_temp_dir());
        $path = tempnam($base, 'forge-style-probe-');
        self::assertNotFalse($path);

        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        $loaded = include $path;

        self::assertIsArray($loaded, '生成物必须能作为 PHP 配置回读');

        return $loaded;
    }
}
