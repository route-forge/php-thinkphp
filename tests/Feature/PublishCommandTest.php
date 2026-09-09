<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Support\ConfigPublisher;
use RouteForge\ThinkPHP\Tests\Support\AppFactory;
use think\App;
use think\console\Input;
use think\console\Output;

/**
 * route:forge:publish 命令 + 三命令缺配置守卫（替代手动复制配置文件）。
 */
class PublishCommandTest extends TestCase
{
    /**
     * @return array{0:int,1:string}
     */
    private function runCommand(App $app, string $name, array $args = [], bool $interactive = false): array
    {
        $input = new Input(array_merge([$name], $args));
        $input->setInteractive($interactive);
        $output = new Output('buffer');

        $exit = $app->console->find($name)->run($input, $output);

        return [$exit, $output->fetch()];
    }

    private function unpublishedApp(): App
    {
        // 模拟「composer 装了包但还没复制配置」：不写 config/forge.php
        return AppFactory::create(debug: true, writeForgeConfig: false);
    }

    public function testPublishCreatesConfigFile(): void
    {
        $app = $this->unpublishedApp();
        $pub = new ConfigPublisher($app);
        self::assertFalse($pub->isPublished());

        [$exit, $out] = $this->runCommand($app, 'route:forge:publish');

        self::assertSame(0, $exit);
        self::assertStringContainsString('已复制', $out);
        self::assertTrue($pub->isPublished(), '命令应把默认配置写入应用 config/');
    }

    public function testPublishSkipsExistingWithoutForce(): void
    {
        $app = $this->unpublishedApp();
        $pub = new ConfigPublisher($app);

        $this->runCommand($app, 'route:forge:publish');
        file_put_contents($pub->targetPath(), "<?php return ['levels' => ['mine' => []]];");

        [$exit, $out] = $this->runCommand($app, 'route:forge:publish');

        self::assertSame(0, $exit);
        self::assertStringContainsString('已存在', $out);
        self::assertStringContainsString('mine', (string) file_get_contents($pub->targetPath()), '不加 --force 不得覆盖');
    }

    public function testPublishForceBacksUpAndOverwrites(): void
    {
        $app = $this->unpublishedApp();
        $pub = new ConfigPublisher($app);

        $this->runCommand($app, 'route:forge:publish');
        file_put_contents($pub->targetPath(), "<?php return ['levels' => ['mine' => []]];");

        [$exit, $out] = $this->runCommand($app, 'route:forge:publish', ['--force']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('备份', $out);
        $backups = glob($pub->targetPath() . '.bak-*');
        self::assertNotEmpty($backups, 'force 覆盖前应生成 .bak 备份');
        self::assertStringContainsString('mine', (string) file_get_contents($backups[0]), '备份应保留原用户改动');
        self::assertStringNotContainsString('mine', (string) file_get_contents($pub->targetPath()), '--force 后目标被包默认覆盖');
    }

    public function testListWarnsAndPointsToPublishWhenMissingNonInteractive(): void
    {
        $app = $this->unpublishedApp();
        $pub = new ConfigPublisher($app);

        [$exit, $out] = $this->runCommand($app, 'route:forge:list');

        self::assertSame(0, $exit);
        self::assertStringContainsString('route:forge:publish', $out, '缺配置时表格模式应指路 publish 命令');
        self::assertFalse($pub->isPublished(), '非交互不得自动复制');
    }

    public function testListJsonStaysCleanWhenConfigMissing(): void
    {
        $app = $this->unpublishedApp();
        $pub = new ConfigPublisher($app);

        [$exit, $out] = $this->runCommand($app, 'route:forge:list', ['--json']);

        self::assertSame(0, $exit);
        // stdout 仍是纯 JSON（缺配置提示只走 STDERR，不污染管道产物）
        $decoded = json_decode(trim($out), true);
        self::assertIsArray($decoded, 'json stdout 必须可解析，未被提示污染');
        self::assertFalse($pub->isPublished());
    }

    public function testTypesStdoutCleanWhenConfigMissing(): void
    {
        $app = $this->unpublishedApp();
        $pub = new ConfigPublisher($app);

        [$exit, $out] = $this->runCommand($app, 'route:forge:types', ['--json']);

        self::assertSame(0, $exit);
        self::assertIsArray(json_decode(trim($out), true), 'types stdout 产物不被缺配置提示污染');
        self::assertFalse($pub->isPublished());
    }
}
