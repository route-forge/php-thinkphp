<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Adapter;

use RouteForge\Common\Contract\CacheInterface;
use think\cache\Driver as ThinkCacheDriver;

/**
 * think\cache\Driver → common CacheInterface 桥接。
 *
 * TTL 语义映射（对齐 common CacheInterface 注释与 SPEC §3.1.5）：
 *   - $seconds === null：永久缓存 → think set($key, $value, 0)
 *     （think 缓存 0=永久；注意不能传 null——null 会落入驱动默认 expire）
 *   - 正整数：set($key, $value, $seconds)
 */
final class ThinkCacheAdapter implements CacheInterface
{
    public function __construct(private readonly ThinkCacheDriver $driver)
    {
    }

    public function get(string $key): mixed
    {
        return $this->driver->get($key);
    }

    public function put(string $key, mixed $value, ?int $seconds): void
    {
        $this->driver->set($key, $value, $seconds ?? 0);
    }

    public function forget(string $key): void
    {
        $this->driver->delete($key);
    }
}
