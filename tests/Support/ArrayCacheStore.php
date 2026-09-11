<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Support;

use RouteForge\Common\Contract\CacheInterface;

/**
 * 内存版 CacheInterface。
 *
 * 用于观测「缓存是否被显式失效」：debug 模式下真实的 think 缓存会被 RouteCache
 * 整体跳过读写（app_debug=1 时立即生效优先），所以断言失效动作必须换一双眼睛看。
 */
final class ArrayCacheStore implements CacheInterface
{
    /**
     * @var array<string,mixed>
     */
    public array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function put(string $key, mixed $value, ?int $seconds): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }
}
