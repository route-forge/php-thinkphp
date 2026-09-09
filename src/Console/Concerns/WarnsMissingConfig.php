<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Console\Concerns;

use RouteForge\ThinkPHP\Support\ConfigPublisher;
use think\console\Input;
use think\console\Output;

/**
 * 现有 route:forge:* 命令启动时的「配置未发布」守卫，替代手动复制配置文件的提醒。
 *
 * 规则：
 *   - 已发布（config/forge.php 存在）→ 静默放行；
 *   - stdout 是「数据产物」（types 恒为产物；list 带 --json）→ 只写 STDERR，
 *     绝不污染管道/重定向产物，也不弹交互；
 *   - stdout 是人类可读（list 表格、clear）→ 打印 warning，并在交互终端下
 *     询问「是否现在复制」，确认后自动复制。
 *
 * 非交互环境（CI / `--no-interaction`）下 confirm 返回默认 false，不复制、仅提示。
 */
trait WarnsMissingConfig
{
    protected function guardConfigPublished(Input $input, Output $output, bool $stdoutIsProduct, string $artifact = '层级元信息'): void
    {
        $publisher = new ConfigPublisher($this->app);
        if ($publisher->isPublished()) {
            return;
        }

        $notice = "未检测到 config/forge.php：Route Forge 尚未配置，{$artifact}将为空。";
        $howto  = '复制默认配置：php think route:forge:publish';

        if ($stdoutIsProduct) {
            fwrite(STDERR, $notice . PHP_EOL . $howto . PHP_EOL);

            return;
        }

        $output->writeln("<comment>{$notice}</comment>");

        if ($input->isInteractive()
            && $output->confirm($input, '是否现在把包默认配置复制到 config/forge.php？', false)) {
            $result = $publisher->publish(false);
            $output->writeln("<info>{$result['message']}</info>");
            $output->writeln('<comment>配置已就位，重新运行本命令即可生效。</comment>');
        } else {
            $output->writeln($howto);
        }
    }
}
