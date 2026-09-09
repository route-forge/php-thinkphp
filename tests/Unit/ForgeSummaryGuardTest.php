<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use think\App;

/**
 * 未注册 ForgeService 时，模板 helper forge_summary() 必须给可操作提示，
 * 而不是容器「无法解析参数」这类天书堆栈。
 */
class ForgeSummaryGuardTest extends TestCase
{
    public function testThrowsFriendlyErrorWhenServiceNotRegistered(): void
    {
        $base = getenv('RF_TEST_TMP') ?: (is_dir('F:/tmp') ? 'F:/tmp' : sys_get_temp_dir());
        $root = $base . '/rf-summary-guard-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0777, true);
        mkdir($root . '/runtime', 0777, true);

        // 仅构造 App（不注册 ForgeService、不 boot）→ RouteRepository 未绑定
        $app = new App($root);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('ForgeService');
            forge_summary();
        } finally {
            // 复位静态实例引用，避免污染后续测试
            App::setInstance(null);
        }
    }
}
