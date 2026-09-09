<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Console;

use RouteForge\Common\Cache\RouteCache;
use RouteForge\Common\Repository\RouteRepository;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 清除 Route Forge 路由元信息缓存。
 *
 * 对齐 Laravel 版 route:forge:clear（SPEC §3.2）：全量清除或按层级清除。
 */
class RouteForgeClearCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('route:forge:clear')
            ->addOption('level', null, Option::VALUE_REQUIRED, '仅清除指定层级的缓存；不传则清除全部（含摘要端点）')
            ->setDescription('清除 Route Forge 路由元信息缓存');
    }

    protected function execute(Input $input, Output $output)
    {
        return $this->app->invoke([$this, 'handle'], [$input, $output]);
    }

    public function handle(Input $input, Output $output, RouteCache $cache): int
    {
        $level = $input->getOption('level');

        if ($level !== null && $level !== '') {
            // 已定义层级 + unassigned 特殊层级（其端点缓存独立存储）
            $levels   = array_keys((array) $this->app->config->get('forge.levels', []));
            $levels[] = RouteRepository::UNASSIGNED_LEVEL;
            if (!in_array($level, $levels, true)) {
                $output->writeln("<error>Unknown level: {$level}</error>");
                $output->writeln('Available levels: ' . (empty($levels) ? '(none)' : implode(', ', $levels)));

                return 1;
            }

            // forgetLevel 封装不变量：层级失效必须同步失效摘要缓存
            $cache->forgetLevel($level);
            $output->writeln("<info>Route Forge cache cleared for level: {$level} (summary cache invalidated as well)</info>");
        } else {
            $cache->clear();
            $output->writeln('<info>Route Forge cache cleared successfully.</info>');
        }

        return 0;
    }
}
