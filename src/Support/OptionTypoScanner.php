<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Support;

use think\route\RuleItem;

/**
 * 扫描路由 option 里「疑似拼错」的 tier / forgeAlias 键。
 *
 * 背景：ThinkPHP 无宏系统，->tier() / ->forgeAlias() 依赖 Rule::__call 把未知
 * 链式方法转成 setOption('方法名', 值)。零侵入的代价是——写成 ->tiere() /
 * ->forgeAliases() 不会报错，只会安静落一个无消费方的 option，路由悄悄掉到
 * unassigned。本扫描在 route:forge:list / types 里把这些可疑键揪出来给 warning。
 *
 * 保守启发：只认「以 tier / forge 开头、又不等于合法键 tier / forgeAlias」的键，
 * 正常 think 选项（prefix/middleware/model/append/ext…）不会误报。
 */
final class OptionTypoScanner
{
    private const VALID_KEYS = ['tier', 'forgealias'];

    /**
     * @param iterable<mixed> $rules
     *
     * @return string[] 人类可读告警（每条可疑路由一行）
     */
    public static function scan(iterable $rules): array
    {
        $warnings = [];

        foreach ($rules as $rule) {
            if (!$rule instanceof RuleItem) {
                continue;
            }

            $option = $rule->getOption();
            if (!is_array($option)) {
                continue;
            }

            foreach (array_keys($option) as $key) {
                if (!is_string($key)) {
                    continue;
                }

                $lower = strtolower($key);
                if (in_array($lower, self::VALID_KEYS, true)) {
                    continue;
                }

                $suspect = null;
                if (str_starts_with($lower, 'tier')) {
                    $suspect = 'tier';
                } elseif (str_starts_with($lower, 'forge')) {
                    $suspect = 'forgeAlias';
                }

                if ($suspect !== null) {
                    $label = $rule->getName() !== '' ? $rule->getName() : ('(' . $rule->getRule() . ')');
                    $warnings[] = "路由 {$label} 带有疑似拼错的链式方法 ->{$key}()（未生效）；"
                        . "若意图是按层级归类请改用 ->{$suspect}(...)"
                        . ($suspect === 'tier' ? '，否则该路由会落入 unassigned。' : '。');
                }
            }
        }

        return $warnings;
    }
}
