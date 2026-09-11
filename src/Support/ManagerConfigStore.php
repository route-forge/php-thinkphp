<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Support;

use RuntimeException;
use RouteForge\Common\Cache\RouteCache;
use RouteForge\Common\Config\ConfigFileGenerator;
use RouteForge\ThinkPHP\Http\Middleware\ManagerAllowedIps;
use think\App;
use think\facade\Config;
use Throwable;

/**
 * 管理器配置落盘编排：common 生成 → ThinkPHP 外壳适配 → 回读校验 → 备份写入 → 缓存失效。
 *
 * 与 laravel 版的三处实质差异，都朝「更正确」的方向：
 *  1. 覆盖前先备份（laravel 是裸 file_put_contents），沿用本包 ConfigPublisher 的纪律；
 *  2. 写盘前把生成物回读并与提交值比对，风格适配若碰坏任何取值即放弃写入；
 *  3. think 的编译配置缓存在 runtime/config.php（App::load 命中即整体覆盖配置），
 *     不清掉就会出现「页面保存成功、运行时仍读旧值」；同时必须清本包的 RouteCache——
 *     levels 一变，层级解析结果与摘要缓存全部作废，laravel 可靠下次请求自然重建，
 *     本包有独立缓存层，不显式失效就是脏数据。
 */
final class ManagerConfigStore
{
    public function __construct(
        private readonly App $app,
        private readonly ConfigPublisher $publisher,
        private readonly RouteCache $cache,
    ) {
    }

    /**
     * @param array<string,mixed> $levels 层级配置（页面 JSON 编辑器提交）
     * @param array<string,mixed> $global 全局设置
     *
     * @return array{backup: string|null, path: string}
     */
    public function save(array $levels, array $global): array
    {
        $generated = (new ConfigFileGenerator())->generate($levels, $global, $this->preserved());

        $content = (new ThinkConfigFileStyler())->style($generated);

        // 先校验再写：宁可保存失败，也不落一个被外壳适配改动过值的配置
        $this->assertStylePreservesValues($generated, $content);

        $backup = $this->publisher->save($content);

        $this->invalidateCaches();

        return ['backup' => $backup, 'path' => $this->publisher->targetPath()];
    }

    /**
     * 不在表单中编辑、保存时原样透传的配置项——漏一项就等于一次保存抹平一项。
     *
     * @return array<string,mixed>
     */
    private function preserved(): array
    {
        return [
            'endpoint_middleware' => Config::get('forge.endpoint_middleware', []),
            // 与守卫同源：写回的就是当前真正生效的那份白名单（单值字符串会被固化为数组）
            'manager_allowed_ips' => ManagerAllowedIps::allowedIps(),
            'aliases'             => Config::get('forge.aliases', []),
        ];
    }

    /**
     * 值不变性校验：分别回读 common 原始产物与 ThinkPHP 外壳适配后的产物，要求严格相等。
     *
     * 为什么 include 是安全的：值全部出自 var_export（标量与数组字面量），classifier
     * 由 common 生成器强制为 null，生成物里没有可执行代码。
     * 为什么必须校验：层级 description 含换行时 var_export 会输出真实换行，理论上可与
     * 注释块的匹配形态撞车——静默交付一个被改值的配置，比保存失败严重得多。
     * 比对基准取「原始生成物」而非页面提交值：common 生成器本就会补 match 的默认结构与
     * load 默认值，拿提交值比会把每一次正常保存都判成失败。
     */
    private function assertStylePreservesValues(string $generated, string $styled): void
    {
        $before = $this->includeProbe($generated, 'before');
        $after  = $this->includeProbe($styled, 'after');

        if ($before !== $after) {
            throw new RuntimeException('ThinkPHP 外壳适配改动了配置值，已放弃写入。');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function includeProbe(string $content, string $stage): array
    {
        $probe = $this->app->getRuntimePath()
            . 'forge-config-probe-' . $stage . '-' . bin2hex(random_bytes(6)) . '.php';

        try {
            if (@file_put_contents($probe, $content) === false) {
                throw new RuntimeException('值不变性校验的临时文件写入失败：' . $probe);
            }

            // 生成物若意外产生输出，会污染当前响应（管理器 API 返回 JSON）
            ob_start();

            try {
                // 探针文件由本类刚写出，include 是刻意的回读手段
                $loaded = include $probe;
            } catch (Throwable $e) {
                throw new RuntimeException(
                    '生成物（' . $stage . '）无法加载：' . $e->getMessage(),
                    0,
                    $e
                );
            } finally {
                ob_end_clean();
            }
        } finally {
            if (is_file($probe)) {
                @unlink($probe);
            }
        }

        if (!is_array($loaded)) {
            throw new RuntimeException('生成物（' . $stage . '）未能回读为数组。');
        }

        return $loaded;
    }

    /**
     * 让新配置立即生效（见类注释第 3 点）。
     */
    private function invalidateCaches(): void
    {
        $compiled = $this->app->getRuntimePath() . 'config.php';
        if (is_file($compiled)) {
            @unlink($compiled);
        }

        $this->cache->clear();
    }
}
