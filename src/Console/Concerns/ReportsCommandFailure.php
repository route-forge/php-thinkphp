<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Console\Concerns;

use think\console\Output;

/**
 * 命令级失败的统一落地：只输出可操作消息，不抛裸堆栈；并守住「stdout 是数据产物」的纯度。
 *
 * 与 WarnsMissingConfig 同一套规则：
 *   - stdout 是数据产物（types 恒为产物；list 带 --json）→ 失败信息写 STDERR，
 *     保证 `route:forge:list --json | jq` 拿到的永远是合法 JSON（或干净的失败）；
 *   - stdout 是人类可读 → <error> 正常打印。
 *
 * 刻意不自造错误码：Forge 系异常的错误码由 route-forge/common 统一登记（RF_BE_0NN），
 * 适配层自己的 fail-fast 消息本身已含指路文本，加码头会凭空扩面前端契约。
 */
trait ReportsCommandFailure
{
    /**
     * @return int 退出码 1，便于调用处 `return $this->fail(...)`
     */
    protected function fail(Output $output, string $message, bool $stdoutIsProduct = false): int
    {
        if ($stdoutIsProduct) {
            fwrite(STDERR, $message . PHP_EOL);
        } else {
            $output->writeln("<error>{$message}</error>");
        }

        return 1;
    }
}
