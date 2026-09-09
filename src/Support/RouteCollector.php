<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Support;

use RuntimeException;
use think\route\Domain;
use think\route\Rule;
use think\route\RuleGroup;
use think\route\RuleItem;
use think\Route;

/**
 * 路由收集器：遍历 think 路由树（域名 → 分组 → 资源 → 规则），
 * 收集全部业务 RuleItem 供 common 层扫描。
 *
 * 为什么不用 RuleName::getRuleList()：它返回的是序列化数组（丢对象），
 * 且只覆盖已注册规则；直接遍历规则树可拿到原始 RuleItem
 * （classifier 回调经 RouteInfo::source 消费）。
 *
 * 排除项：
 *   - MISS 规则（框架 init 自动注册的 OPTIONS miss 等，非业务路由）；
 *   - __think_auto_route__（Route::auto() 注册的自动路由，框架内部机制）
 *     ——经 RouteNameFilter 前缀排除，不在本类处理；
 *   - forge 自身端点（forge.routes.*）——同样经 RouteNameFilter 排除。
 *
 * ⚠ url_lazy_route 不受支持（v1 决策）：延迟解析下分组闭包未执行、
 * 规则树不完整，扫描结果失真。检测到配置开启时 fail-fast。
 */
final class RouteCollector
{
    public function __construct(private readonly Route $route)
    {
    }

    /**
     * @return list<RuleItem>
     */
    public function collect(): array
    {
        if ($this->route->config('url_lazy_route')) {
            throw new RuntimeException(
                'route-forge/thinkphp does not support url_lazy_route=true: '
                . 'lazy route parsing leaves group closures unexecuted and the rule tree incomplete, '
                . 'so metadata scanning would be unreliable. '
                . 'Disable it in config/route.php to use Route Forge.',
            );
        }

        $items = [];
        $seen = [];

        foreach ($this->route->getDomains() as $domain) {
            if ($domain instanceof Domain) {
                $this->walk($domain, $items, $seen);
            }
        }

        return $items;
    }

    /**
     * 递归遍历分组树：RuleItem 直接收集（MISS 除外），子分组（含 Resource）递归。
     *
     * @param list<RuleItem> $items
     * @param array<int, true> $seen 对象去重索引（同一路由树中同一实例可能出现于多处引用）
     */
    private function walk(RuleGroup $group, array &$items, array &$seen): void
    {
        foreach ($group->getRules() as $rule) {
            if ($rule instanceof RuleItem) {
                if ($rule->isMiss()) {
                    continue;
                }

                $id = spl_object_id($rule);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $items[] = $rule;
            } elseif ($rule instanceof RuleGroup) {
                // 子分组 / Resource（资源路由也是 RuleGroup 子类）
                $this->walk($rule, $items, $seen);
            }
        }
    }
}
