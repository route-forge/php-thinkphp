<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Adapter;

use InvalidArgumentException;
use RouteForge\Common\Contract\RouteNormalizerInterface;
use RouteForge\Common\Dto\RouteInfo;
use think\route\RuleItem;

/**
 * ThinkPHP 路由规则 → 统一 RouteInfo 的适配器。
 *
 * 零侵入策略（与 Laravel 版宏写入 action 不同）：
 *   - tier / forgeAlias 经 think Rule::__call 直接落到路由 option
 *     （->tier('x') → option['tier']='x'），本适配器读时取出；
 *   - 分组 tier（Route::group(...)->tier('x')）经 RuleGroup 选项树
 *     动态合并（getOption() 读时合并、子覆盖父），「内层覆盖外层 /
 *     显式覆盖分组」语义由 think 原生合并规则免费成立。
 *
 * ThinkPHP 与 Laravel 的契约差异（此处归一，前端契约不变）：
 *   - 命名路由甄别：think 默认把「路由地址字符串」当作路由标识
 *     （RuleGroup::addRule 中 $name = $route），getName() 非空不代表
 *     用户显式命名——name 等于路由地址时视为未命名；
 *   - URI 语法：<id> / <id?> → {id} / {id?}（前端 core 契约）；
 *   - methods：think 为单个小写字符串（'*' 或 'get|post'），'*' 展开
 *     为标准方法全集，含 GET 时补 HEAD（对齐 Laravel methods() 契约）；
 *   - forgeAlias 经 __call 的形态随参数个数变化（单参=string，
 *     多参=[全参数数组, ...重复尾参]），此处统一展平为 string[]。
 */
final class ThinkRouteNormalizer implements RouteNormalizerInterface
{
    /**
     * method='*'（Route::any）展开的方法全集，对齐 Laravel Route::any 注册集。
     */
    private const ANY_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public function normalize(mixed $route): RouteInfo
    {
        if (!$route instanceof RuleItem) {
            throw new InvalidArgumentException(
                'Expected think\\route\\RuleItem, got ' . get_debug_type($route),
            );
        }

        $rule = (string) $route->getRule();

        return new RouteInfo(
            name: $this->resolveName($route),
            uri: $this->normalizeUri($rule),
            methods: $this->normalizeMethods($route->getMethod()),
            parameters: $this->extractParameters($rule),
            parameterDefaults: (array) ($route->getOption('default') ?? []),
            middleware: $this->normalizeMiddleware($route->getOption('middleware')),
            tier: $this->normalizeTier($route->getOption('tier')),
            forgeAliases: $this->normalizeAliases($route->getOption('forgeAlias')),
            source: $route,
        );
    }

    /**
     * 命名路由甄别：think 的路由标识默认 = 路由地址字符串（非用户显式命名），
     * 仅当 name 非空且不等于路由地址时视为显式命名的命名路由。
     */
    private function resolveName(RuleItem $route): ?string
    {
        $name = $route->getName();
        if ($name === '' || $name === null) {
            return null;
        }

        $routeTarget = $route->getRoute();
        if (is_string($routeTarget) && $name === $routeTarget) {
            return null; // 默认标识（= 路由地址），非显式 ->name(...)
        }

        return $name;
    }

    /**
     * <id> / <id?> → {id} / {id?}，对齐前端 core 的 URL 模板契约。
     */
    private function normalizeUri(string $rule): string
    {
        return (string) preg_replace('/<(\w+\??)>/', '{$1}', $rule);
    }

    /**
     * 'get|post' → ['GET','POST']；'*' → 全集；含 GET 时补 HEAD。
     *
     * @return string[]
     */
    private function normalizeMethods(string $method): array
    {
        if ($method === '*' || $method === '') {
            return self::ANY_METHODS;
        }

        $methods = array_values(array_unique(array_map(
            static fn (string $m): string => strtoupper(trim($m)),
            array_filter(explode('|', $method), static fn (string $m): bool => $m !== ''),
        )));

        // 对齐 Laravel 契约：GET 路由的 methods 含 HEAD（紧跟 GET 之后）
        if (in_array('GET', $methods, true)) {
            array_splice($methods, (int) array_search('GET', $methods, true) + 1, 0, 'HEAD');
        }

        return $methods;
    }

    /**
     * 从路由规则提取路径参数名（含可选参数，与 Laravel parameterNames() 口径一致）。
     *
     * @return string[]
     */
    private function extractParameters(string $rule): array
    {
        preg_match_all('/<(\w+)\??>/', $rule, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * 中间件集合展平：think 的 option['middleware'] 混有
     * string（'auth' / 类名）与 [类名, 参数] 元组（Rule::middleware 带参形态），
     * 元组取首个元素参与层级 match 匹配。
     *
     * @return string[]
     */
    private function normalizeMiddleware(mixed $middleware): array
    {
        if (!is_array($middleware)) {
            return [];
        }

        $flat = [];
        foreach ($middleware as $item) {
            if (is_string($item)) {
                $flat[] = $item;
            } elseif (is_array($item) && isset($item[0]) && is_string($item[0])) {
                $flat[] = $item[0];
            }
        }

        return array_values(array_unique($flat));
    }

    private function normalizeTier(mixed $tier): ?string
    {
        return is_string($tier) && $tier !== '' ? $tier : null;
    }

    /**
     * forgeAlias 归一（__call 形态差异见类注释）：
     *   - 'a'（单参）→ ['a']
     *   - [['a','b'], 'b']（双参）→ 取首个元素（全参数数组）→ ['a','b']
     */
    private function normalizeAliases(mixed $aliases): array
    {
        if (is_string($aliases) && $aliases !== '') {
            return [$aliases];
        }

        if (!is_array($aliases)) {
            return [];
        }

        // 多参调用形态：首个元素是全参数数组，其后是重复的尾参（取首元素即可）
        $first = $aliases[0] ?? null;

        if (is_array($first)) {
            $names = $first;
        } elseif (is_string($first)) {
            $names = $aliases; // 防御：直接是字符串数组
        } else {
            return [];
        }

        return array_values(array_filter($names, 'is_string'));
    }
}
