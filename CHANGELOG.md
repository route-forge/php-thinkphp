# Changelog

本项目所有重要变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [0.0.1] - 2026-09-09

### Added

- ThinkPHP 8 适配层（`route-forge/thinkphp`，基于框架无关的 `route-forge/common`）：
  - 层级分配三通道：路由链式 `->tier()`、分组链式 `->tier()` 透传（嵌套内层覆盖外层）、
    配置 match 批量分配（prefix / middleware / `middleware_match` any-all-DNF）
  - 五级优先级与 `unassigned` 兜底、`strict_mode` 严格模式（RF_BE_001）
  - `classifier` 自定义分类回调（按 `\think\route\RuleItem` 书写）
  - 元信息端点 `GET /{endpoint_prefix}/{level}` 与摘要端点 `GET /{endpoint_prefix}`，
    各层级独立 `endpoint_middleware` 保护，未知层级 404（RF_BE_002）
  - 路由别名：`->forgeAlias()` 链式声明（单/多参）与 config `aliases`，宏优先、
    撞车忽略告警、悬空 fail-fast（RF_BE_008）；摘要 `route_count` 计入别名
  - 统一缓存：think Cache 驱动桥接（`0`=永久映射），`app_debug` 旁路，
    `route-forge:{level}` / `route-forge:summary` 键 + `_keys` 索引
  - think console 命令：`route:forge:list`（table/JSON 契约、--level/--unassigned/--aliases、
    撞车红行/别名黄行/被依赖绿名）、`route:forge:types`（d.ts / --json / --out）、
    `route:forge:clear`（全量/--level 且同步失效摘要）
  - 内嵌摘要 helper `forge_summary()`（模板 `{:forge_summary()}`，Laravel `@forgeSummary`
    的等价物，复用 `SummaryRenderer` 与摘要同一 producer）
  - ThinkPHP 特有适配：命名路由甄别（默认标识=路由地址 → 视为未命名）、
    URI 语法归一（`<id>`/`<id?>` → `{id}`/`{id?}`）、methods 归一（`*` 展开全集、
    GET 补 HEAD）、`__think_auto_route__` 内部路由排除、`url_lazy_route` 不支持 fail-fast
  - 服务接入：composer `extra.think.services` 自动发现（或 `app/service.php` 手动注册）
