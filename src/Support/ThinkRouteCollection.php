<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Support;

use IteratorAggregate;
use Traversable;

/**
 * 可重复迭代的路由集合视图。
 *
 * common RouteRepository 会多次迭代注入的路由集合（层级端点、摘要、
 * 命令各一次），Generator 只能消费一次——本类每次 getIterator() 重新
 * 收集，保证每次扫描都拿到 think 路由树的当前状态（含闭包内后注册的路由）。
 *
 * @implements IteratorAggregate<int, \think\route\RuleItem>
 */
final class ThinkRouteCollection implements IteratorAggregate
{
    public function __construct(private readonly RouteCollector $collector)
    {
    }

    public function getIterator(): Traversable
    {
        yield from $this->collector->collect();
    }
}
