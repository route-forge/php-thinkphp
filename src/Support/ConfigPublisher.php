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

        $backup = null;
        if (is_file($target)) {
            // force 覆盖前备份，保留用户既有改动可回退；同秒重复 force 时追加序号避免覆盖备份
            $backup = $target . '.bak-' . date('Ymd-His');
            for ($i = 2; is_file($backup); $i++) {
                $backup = $target . '.bak-' . date('Ymd-His') . '-' . $i;
            }
            if (!copy($target, $backup)) {
                throw new RuntimeException('备份现有 config/forge.php 失败：' . $target);
            }
        }

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
}
