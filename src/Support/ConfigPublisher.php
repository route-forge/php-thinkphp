<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Support;

use RuntimeException;
use think\App;

/**
 * config/forge.php 发布器：ThinkPHP 无 vendor:publish，本类把包内默认配置
 * 复制到应用 config/forge.php，替代「开发者手动复制」。
 *
 * 幂等与安全：目标已存在时默认跳过（不覆盖用户改动）；仅显式 force 才覆盖，
 * 且覆盖前先把原文件备份为 forge.php.bak-{Ymd-His}（可回退，不丢改动）。
 */
final class ConfigPublisher
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * 包内默认配置文件绝对路径（src/Support → 包根 config/forge.php）。
     */
    public function packagePath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'forge.php';
    }

    /**
     * 应用侧目标路径（config/forge.php）。
     */
    public function targetPath(): string
    {
        return $this->app->getConfigPath() . 'forge.php';
    }

    public function isPublished(): bool
    {
        return is_file($this->targetPath());
    }

    /**
     * 复制包默认配置到应用。
     *
     * @return array{status:'copied'|'skipped'|'overwritten', message:string, target:string, backup?:string}
     */
    public function publish(bool $force = false): array
    {
        $source = $this->packagePath();
        $target = $this->targetPath();

        if (!is_file($source)) {
            throw new RuntimeException('Route Forge 包内默认配置缺失：' . $source);
        }

        if (is_file($target) && !$force) {
            return [
                'status'  => 'skipped',
                'message' => 'config/forge.php 已存在，未覆盖（如需覆盖请加 --force，会先备份原文件）。',
                'target'  => $target,
            ];
        }

        $backup = $this->backupIfPresent($target);

        if (!copy($source, $target)) {
            throw new RuntimeException('复制默认配置失败：' . $target);
        }

        if (!is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('复制后校验失败（目标为空/不存在）：' . $target);
        }

        return [
            'status'  => $backup !== null ? 'overwritten' : 'copied',
            'message' => $backup !== null
                ? '已备份原配置并覆盖为包默认：' . $target
                : '已复制包默认配置到：' . $target,
            'target'  => $target,
            'backup'  => $backup,
        ];
    }

    /**
     * 以给定内容覆盖应用 config/forge.php（管理器「保存配置」的落盘入口）。
     *
     * 与 publish(force) 共用同一套备份语义：覆盖前必先备份，绝不裸写用户配置。
     *
     * @return string|null 备份文件路径（目标原本不存在时为 null）
     */
    public function save(string $content): ?string
    {
        $target = $this->targetPath();
        $backup = $this->backupIfPresent($target);

        // think 的错误初始化器会把 file_put_contents 的 E_WARNING 抛成 ErrorException，
        // 故先 @ 抑制再判 false（同 route:forge:types --out 的教训）。
        if (@file_put_contents($target, $content) === false) {
            throw new RuntimeException('写入 config/forge.php 失败：' . $target);
        }

        if (!is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('写入后校验失败（目标为空/不存在）：' . $target);
        }

        return $backup;
    }

    /**
     * 目标已存在则备份为 forge.php.bak-{Ymd-His}，返回备份路径；不存在返回 null。
     * 同一秒内重复覆盖时追加序号，避免后一次备份抹掉前一次。
     */
    private function backupIfPresent(string $target): ?string
    {
        if (!is_file($target)) {
            return null;
        }

        $backup = $target . '.bak-' . date('Ymd-His');
        for ($i = 2; is_file($backup); $i++) {
            $backup = $target . '.bak-' . date('Ymd-His') . '-' . $i;
        }

        if (!copy($target, $backup)) {
            throw new RuntimeException('备份现有 config/forge.php 失败：' . $target);
        }

        return $backup;
    }
}
