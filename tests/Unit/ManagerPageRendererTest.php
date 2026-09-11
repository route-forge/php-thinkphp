<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 管理器页面渲染器：占位符替换、残留 fail-fast 与脚本块逃逸防护。
 */
class ManagerPageRendererTest extends TestCase
{
    /**
     * @var string[] 本用例创建的临时模板，tearDown 清理
     */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tmpFiles = [];
    }

    public function testPackageTemplateExistsAndCarriesExactlyTwoPlaceholders(): void
    {
        $path = ManagerPageRenderer::packageTemplatePath();

        self::assertFileExists($path, '包内模板必须随包发布（无 files 白名单限制）');

        $html = (string) file_get_contents($path);

        // 占位符名与渲染器常量必须逐字一致：模板被改动导致契约错位时，
        // 宁可在此暴露，也不要在页面上交付半个 JS。
        self::assertSame(1, substr_count($html, '__FORGE_CONFIG_JSON__'));
        self::assertSame(1, substr_count($html, '__FORGE_SCHEME_VERSION__'));
        // blade 残留（复制模板时漏改）会原样吐进页面
        self::assertStringNotContainsString('@json', $html);
        self::assertStringNotContainsString('{{ ', $html);
    }

    public function testBothPlaceholdersAreReplaced(): void
    {
        $renderer = $this->rendererWith("<p>v__FORGE_SCHEME_VERSION__</p><script>const CFG = __FORGE_CONFIG_JSON__;</script>");

        $html = $renderer->render(['tiers' => [['name' => 'public']]], 3);

        self::assertStringContainsString('<p>v3</p>', $html);
        self::assertStringContainsString('const CFG = {"tiers":[{"name":"public"}]};', $html);
        self::assertStringNotContainsString('__FORGE_', $html);
    }

    public function testLeftoverPlaceholderFailsFast(): void
    {
        $renderer = $this->rendererWith('<script>const CFG = __FORGE_CONFIG_JSON__;</script>__FORGE_TYPO__');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('未替换的占位符');

        $renderer->render([], 1);
    }

    public function testMissingTemplateThrows(): void
    {
        $renderer = new ManagerPageRenderer($this->tmpDir() . '/forge-manager-missing-template-' . uniqid() . '.html');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('模板缺失或为空');

        $renderer->render([], 1);
    }

    public function testEmptyTemplateThrows(): void
    {
        $renderer = $this->rendererWith('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('模板缺失或为空');

        $renderer->render([], 1);
    }

    /**
     * 用户可控内容（层级 description）不得提前闭合脚本块。
     */
    public function testUserDataCannotEscapeScriptBlock(): void
    {
        $renderer = $this->rendererWith('<script>const CFG = __FORGE_CONFIG_JSON__;</script>');

        $payload = '</script><script>alert(1)</script>';
        $html    = $renderer->render(['tiers' => [['description' => $payload]]], 1);

        // 尖括号被 HEX_TAG 转义，模板自身那一个真实 </script> 仍是唯一闭合
        self::assertStringContainsString('\u003C/script\u003E', $html);
        self::assertSame(1, substr_count($html, '</script>'), '注入内容不得产生额外脚本闭合标签');
    }

    /**
     * 中文按原样输出（与 common JsSafeEncoder 的 UNESCAPED_UNICODE 口径一致），
     * 否则页面徽章与层级描述会显示成 \uXXXX。
     */
    public function testChineseIsNotUnicodeEscaped(): void
    {
        $renderer = $this->rendererWith('<script>const CFG = __FORGE_CONFIG_JSON__;</script>');

        $html = $renderer->render(['tiers' => [['description' => '公共接口（无需登录）']]], 1);

        self::assertStringContainsString('公共接口（无需登录）', $html);
        self::assertStringNotContainsString('\\u4e2d', $html);
    }

    private function rendererWith(string $content): ManagerPageRenderer
    {
        $path = tempnam($this->tmpDir(), 'forge-manager-tpl-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return new ManagerPageRenderer($path);
    }

    /**
     * 临时目录策略与本包测试工厂一致：RF_TEST_TMP > F:\tmp > 系统临时目录（CI 回退）。
     */
    private function tmpDir(): string
    {
        return getenv('RF_TEST_TMP') ?: (is_dir('F:/tmp') ? 'F:/tmp' : sys_get_temp_dir());
    }
}
