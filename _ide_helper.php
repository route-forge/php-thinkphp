<?php

declare(strict_types=1);

/*
 * Route Forge for ThinkPHP —— IDE 智能提示桩（dev-only，勿进运行时）
 * -------------------------------------------------------------------------
 * 本包通过 think\route\Rule::__call 提供链式 ->tier() / ->forgeAlias()。
 * 它们是「魔术方法」——运行时被 __call 拦截转成路由 option，类里并无真实声明，
 * 所以 IDE 默认无法对 ->tier() 给出补全/跳转。本文件用 @method 把这些提示补上。
 *
 * ⚠ 仅供 IDE 索引，切勿 require / composer autoload 进运行时：
 *   - 本文件不在 composer.json 的 autoload 路径内，正常永远不会被 PHP 执行；
 *   - 这里以「重新声明同名类 + @method」的方式提供提示，若被运行时加载会与
 *     真实类冲突，因此绝对不要把它纳入自动加载或手动 require。
 *
 * 生效方式：PHPStorm 等 IDE 会索引包根下的本文件并合并其 @method。若你的 IDE
 *   未识别，请把本文件所在目录加入 Settings → PHP 的 Include Path / 索引范围。
 *
 * 维护：ThinkPHP 未来若原生新增同名方法或改动签名，请同步更新或删除本桩。
 */

namespace think\route;

/**
 * @method $this tier(string $tier) 按层级归类该路由；值须为 config/forge.php 的 levels 中已定义的层级名。链式返回自身。
 * @method $this forgeAlias(string ...$aliases) 声明路由别名（旧路由名）；多个别名必须在同一次调用传入（重复调用是覆盖而非合并）。
 */
class Rule
{
}
