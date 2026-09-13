<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Console;

use RouteForge\ThinkPHP\Console\Concerns\EnsuresAnsiOutput;
use RouteForge\ThinkPHP\Support\ConfigPublisher;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 把包内默认 config/forge.php 复制到应用 config/forge.php
 * （ThinkPHP 无 vendor:publish，此命令替代「手动复制」）。
 *
 * 目标已存在时默认跳过；--force 覆盖前自动备份为 forge.php.bak-{时间}。
 */
class RouteForgePublishCommand extends Command
{
    use EnsuresAnsiOutput;

    protected function configure(): void
    {
        $this->setName('route:forge:publish')
            ->addOption('force', null, Option::VALUE_NONE, '目标已存在时强制覆盖（覆盖前自动备份原文件）')
            ->setDescription('复制 Route Forge 默认配置到应用 config/forge.php');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->ensureAnsiOutput($input, $output);

        return $this->app->invoke([$this, 'handle'], [$input, $output]);
    }

    public function handle(Input $input, Output $output, ConfigPublisher $publisher): int
    {
        $force = (bool) $input->getOption('force');

        try {
            $result = $publisher->publish($force);
        } catch (\Throwable $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return 1;
        }

        if ($result['status'] === 'skipped') {
            $output->writeln("<comment>{$result['message']}</comment>");

            return 0;
        }

        $output->writeln("<info>{$result['message']}</info>");
        if (!empty($result['backup'])) {
            $output->writeln("原配置备份于：{$result['backup']}");
        }
        $output->writeln('<comment>提示：开发模式（APP_DEBUG=1）下缓存自动旁路，改完配置即时生效；生产环境请清缓存。</comment>');

        return 0;
    }
}
