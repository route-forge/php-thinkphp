# Changelog

本项目所有重要变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [未发布]

### Added

- **`route:forge:gen` 命令 + `AutoRouteScanner`**：把「当前可被 ThinkPHP 自动路由触达的端点」反向物化成**显式命名路由**，让习惯自动路由的项目低成本接入 route-forge。
  - 单应用增量写入 `route/forge.auto.php`；多应用写各 `app/{模块}/route/forge.auto.php`（URI 不带模块前缀，对齐 MultiApp 剥离行为）。
  - **只新增、绝不删除**：不动已写规则；删除是开发者的手；幂等可反复运行（已存在于实时路由表或生成文件里的名字跳过）。
  - **悬空只提醒**：生成文件里指向已消失控制器/方法的条目仅报告，不清理。
  - **不写 tier**：生成条目落 `unassigned`，留 `// ->tier('…') 待填` 注释。
  - **防误用**：自动判定单/多应用——单应用禁 `--module`、多应用必须显式 `--module`（或 `*`），杜绝「悄悄扫全部」；`app/controller` 与模块控制器目录并存的混合布局判为有歧义、直接停下要求 `--mode=single|multi`，不替你猜；提供 `--path` / `--namespace` 限定范围、`--dry-run` 预览。
  - **落盘可见**：目标不可写/被目录占位时显式报错并说明本轮已写入哪些文件，不会静默留下半个文件却返回成功；本命令幂等，修好后重跑同一命令即补齐。
  - 动作段按 ThinkPHP 自身的可达规则反推：think 用「URL 段 + `route.action_suffix`」命中方法，故可达 URL 是方法名剔掉后缀的短形式（`listView` 在 `action_suffix='View'` 下可达于 `user/list`）；方法名不以该后缀结尾的本就无可达 URL，只登记提示、不生成（生成即凭空新增端点）。invokable / 带路径参数仍交人写。camelCase 动作段照常生成，但给一条大小写风险提示（`url_case_sensitive=true` 时历史小写写法会 404）。

### Fixed

- **命令失败不再向用户倒框架堆栈**：`route:forge:list` / `types` 此前只捕获 `ForgeExceptionContract`，适配层自己的 fail-fast（`url_lazy_route=true`、非 `RuleItem` 规则）直接逃到 console 异常处理器。现在统一只输出可操作消息；且数据产物形态（`list --json` / `types` 的 d.ts/JSON）的失败信息改走 STDERR——此前连 `[RF_BE_008]` 错误文本都会混进产物。
- **`route:forge:types --out` 的写盘校验在生产运行时永不生效**：ThinkPHP 的错误初始化器把 `file_put_contents` 的 `E_WARNING` 抛成 `ErrorException`，`=== false` 分支轮不到执行（仅在测试里可达）。现以 `@` 抑制后正确判定并报错。
- **依赖下限 `route-forge/common` `^1.0` → `^1.1.1`**：`^1.0` 允许装上 1.0.0，而它有两处会真炸到使用者的缺口——`match.prefix` / `match.middleware` 传单值字符串（`'prefix' => 'manage'`）直接 `TypeError` 崩溃；`JsSafeEncoder` 缺 `JSON_UNESCAPED_UNICODE`，本包 `levels` 的中文 description 在摘要内嵌里被转成 `\uXXXX`，与「和 laravel 版逐位对齐」的口径不符。现在依赖口径与本地/CI 实测版本一致。

## [0.0.2] - 2026-09-09

### Added

- **`route:forge:publish` 命令**：ThinkPHP 无 `vendor:publish`，此命令把包内默认 `config/forge.php` 复制到应用 `config/forge.php`，替代「开发者手动复制」。目标已存在时默认跳过不覆盖；`--force` 覆盖前自动备份为 `forge.php.bak-{Ymd-His}`。
- **缺配置守卫**：`route:forge:list` / `types` / `clear` 启动时检测 `config/forge.php` 是否已发布；未发布则提示——人类可读输出（list 表格 / clear）在交互终端 `confirm` 询问是否立即复制，数据产物形态（`list --json` / `types` 的 d.ts/JSON）仅写 STDERR，绝不污染 `--json` / 重定向的 stdout 管道。CI / 非交互环境 `confirm` 返回默认值，不挂起、不读 STDIN。
- **dev-only IDE 提示桩 `_ide_helper.php`**：对 `think\route\Rule` 贴 `@method tier()/forgeAlias()`，补全经 `__call` 的魔术链式方法。不进 composer autoload，仅供 IDE 索引。

### Changed

- 文档「完整文档」入口由指向 `php-laravel/.docs` 统一改指 route-forge 文档站 <https://route-forge.github.io/docs/>（`config/forge.php` 头、README、llms.txt）；修正 README 中 `route-forge/common` 误链到 php-laravel 仓的问题。

### Fixed（易用性审查）

- `route:forge:types --out`：目标父目录不可创建或写入失败时**返回退出码 1 并报错**，不再假报「Written to」成功；成功时回显绝对路径。
- `forge_summary()`：未注册 `ForgeService` 时抛出可操作提示（「请先注册 ForgeService」），不再冒容器「无法解析参数」天书堆栈。
- `route:forge:list` / `types`：新增 **`->tier()`/`->forgeAlias()` 拼写告警**——扫描到 `->tiere()` / `->forgeAliases()` 这类被 `__call` 静默吞掉、不会生效的链式方法时给出 warning，提示正确写法（缓解零侵入 `__call` 设计的最大 DX 隐患）。
- `--force` 备份文件名同秒冲突时追加序号（`.bak-{Ymd-His}-2`），不再覆盖上一个备份。
- 缺配置守卫话术按命令产物区分（types 不再被笼统描述为「无数据」）。

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
