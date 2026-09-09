<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Support\ConfigPublisher;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use think\App;

/**
 * config/forge.php 发布器：复制 / 幂等跳过 / force 备份覆盖。
 */
class ConfigPublisherTest extends TestCase
{
    /**
     * 造一个「尚未复制配置」的应用（config/forge.php 不存在）。
     */
    private function unpublishedApp(): App
    {
        return AppFactory::create(debug: true, writeForgeConfig: false);
    }

    public function testPathsAndInitialState(): void
    {
        $app = $this->unpublishedApp();
        $pub = new ConfigPublisher($app);

        self::assertTrue(is_file($pub->packagePath()), '包内默认配置应存在');
        self::assertStringEndsWith('config' . DIRECTORY_SEPARATOR . 'forge.php', $pub->targetPath());
        self::assertFalse($pub->isPublished());
    }

    public function testPublishCopiesPackageDefault(): void
    {
        $app = $this->unpublishedApp();
        $pub = new ConfigPublisher($app);

        $result = $pub->publish();

        self::assertSame('copied', $result['status']);
        self::assertTrue($pub->isPublished());
        self::assertStringContainsString('复制', (string) file_get_contents($pub->targetPath()));
        self::assertSame(
            file_get_contents($pub->packagePath()),
            file_get_contents($pub->targetPath()),
            '复制内容应与包默认配置逐字节一致',
        );
    }

    public function testPublishSkipsWhenExists(): void
    {
        $app = $this->unpublishedApp();
        $pub = new ConfigPublisher($app);
        $pub->publish();

        // 用户改动
        file_put_contents($pub->targetPath(), "<?php return ['levels' => ['mine' => []]];");

        $result = $pub->publish();

        self::assertSame('skipped', $result['status']);
        self::assertStringContainsString('mine', (string) file_get_contents($pub->targetPath()), '默认不得覆盖用户改动');
    }

    public function testForceOverwriteBackupsOriginal(): void
    {
        $app = $this->unpublishedApp();
        $pub = new ConfigPublisher($app);
        $pub->publish();
        file_put_contents($pub->targetPath(), "<?php return ['levels' => ['mine' => []]];");

        $result = $pub->publish(force: true);

        self::assertSame('overwritten', $result['status']);
        self::assertNotEmpty($result['backup']);
        self::assertTrue(is_file($result['backup']), '备份文件应存在');
        self::assertStringContainsString('mine', (string) file_get_contents($result['backup']), '备份应保留原用户改动');
        self::assertSame(
            file_get_contents($pub->packagePath()),
            file_get_contents($pub->targetPath()),
            'force 后目标应为包默认',
        );
    }
}
