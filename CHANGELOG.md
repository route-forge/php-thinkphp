# Changelog

本项目所有重要变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

### Added

- **`route:forge:list` 表格中未分配层级的路由整行品红**（与 Laravel 版 `fg=magenta` 同色）：`unassigned` 是「该配 `->tier()` 却没配」最常见的信号，原先这些行与其他行完全同色，只能靠上方 `Tier counts:` 的计数提醒，几十行表格里定位具体是哪几条得逐行看 Level 列。现在整行染色后一眼可辨。
  - 着色优先级为 `unassigned` 品红 > 别名黄 > 默认，指向未分级路由的别名行也被品红覆盖——此时别名身份仍由 `Alias Of` 列的文字表达，不依赖颜色（与 Laravel 版取舍一致）。
  - think console 只有 `info` / `error` / `comment` / `question` / `highlight` / `warning` 六个具名样式，没有品红，故用 `Formatter` 支持的内联标签 `<fg=magenta>`（渲染为 `ESC[35m … ESC[39m`）。表格宽度计算本就按去标签后的可见文本，列对齐不受影响。
  - **零契约变更**：只作用于 table 形态，`--json` 与 `route:forge:types` 产物仍是纯文本、字节不变；命令选项与错误码集合无变化。
  - 回归测试：新增 `CommandTest::testListTableColorizesUnassignedRowsMagenta`（未分级行品红、别名黄通道未被抢走、`--json` 不含任何标签），本包 156 → 157 例全绿。`Buffer` 驱动不过 `Formatter`，故包内断言的是原始标签文本；真实 ANSI 由示例项目 `--ansi` 管道下核验（未分级行 4 段 `ESC[35m`，默认管道与 `--json --ansi` 恒为 0）。

## [1.2.1] - 2026-09-13

### Added

- **Windows 下的命令着色自愈（`route:forge:*` 观感与 Laravel 版对齐）**：包内五命令一直在用 think console 的 `<info>` / `<comment>` / `<error>` 标签（层级统计、别名黄行、撞车红行、失败提示），但在 Windows 上全部退化为纯文本。根因在框架而非本包：`think\console\output\driver\Console::hasColorSupport()` 的 Windows 分支要求系统版本号**精确等于** `10.0.10586`（Win10 1511 的首发版号，symfony 2.x 时代抄来的写法），且只认 `TERM` **严格等于** `xterm`，于是 Win11（如 `10.0.26200`）与 Git Bash（`TERM=xterm-256color`）恒判「不支持颜色」，标签被 `Formatter` 剥掉却不写 ANSI 码；Linux/macOS 走 `posix_isatty` 分支所以正常。Laravel 侧之所以有颜色，是 symfony/console 早已把这条判据换成 `>=` + `WT_SESSION` + 主动启用 VT 模式。
  - 新增 `src/Support/ConsoleColorDetector.php`：Windows 下改为「版本号 ≥ 10.0.10586 **且** PHP 成功开启控制台 VT 模式」，并识别 Windows Terminal（`WT_SESSION`）、mintty / Git Bash（`MSYSCON`）、ConEmu（`ConEmuANSI=ON`）、ansicon/cmder 与带后缀的 `TERM`。VT 启用成功还兼作「背后是真控制台」的第二证据（管道/重定向下该调用必然失败，不会因此误染色）。判定核心 `decide()` 是纯函数（环境、控制台、os 家族、版本号、VT 状态全部注入），故 Windows 分支在任意平台上都可单测。
  - 新增 `src/Console/Concerns/EnsuresAnsiOutput.php` 并接入五命令的 `execute()`：时序上 `Console::run()` 的 `configureIO()` 先跑、之后才到 `execute()`，所以覆盖 `setDecorated()` 安全且天然早于任何一次 `writeln()`。只调框架公开 API，不重绑、不反射任何框架对象，零侵入约定不变。
  - **取向偏保守**：`stdout` 不是终端（管道、重定向、CI）时一律不上色，`route:forge:list --json` 与 `route:forge:types` 的产物里不会混入 ANSI 转义码；遵守 `NO_COLOR` 与 `TERM=dumb`；个别终端下 PHP 认不出控制台时宁可不上色，也不把 `←[32m` 这类乱码写进终端。
  - **显式表态优先**：命令带 `--ansi` / `--no-ansi` 时本包完全不介入（框架的 `configureIO` 已处理），`--ansi` 同时是自动判定过保守时的逃生舱。think 的 `Buffer` / `Nothing` 输出驱动压根没有 `setDecorated`，此处静默降级，不牵动包内测试与非 console 输出场景。
  - **验证**：示例项目（`vendor/route-forge/thinkphp` 为符号链接，改动即时生效）在管道下实测 ANSI 转义序列计数——`list` / `types` 默认 `0`、`--ansi` `26`、`--no-ansi` `0`、`--json`（连 `--json --ansi` 也是）恒 `0` 且 `json_decode` 正常、`clear` / `publish --ansi` 各 `2`；包内 145 → 156 例全绿（检测器 8 例 + trait 契约 3 例）。「终端真出颜色」这层包内 `Buffer` 驱动验不到（它压根不过 `Formatter`），由维护者在**主终端 PowerShell 7.6.5（Windows Terminal 宿主）**与 Git Bash、git-cmd、cmd 四类终端确认真实交互下 `stream_isatty(STDOUT)` 与 `sapi_windows_vt100_support(STDOUT, true)` 均为 true——即 conhost 路径与第三方终端路径各自独立成立，自愈必然放行（着色取决于控制台宿主是否支持 VT，与 PowerShell 版本无直接关系）。
  - 命令选项、端点与摘要结构、错误码集合均无变化，JSON/TS 产物字节不变，故为补丁版本。文档同步于 README「终端着色」与「与 Laravel 版的差异」、`llms.txt`。

## [1.2.0] - 2026-09-13

### Fixed

- **命令场景收集不到 `route/*.php` 注册的路由（`route:forge:list` / `route:forge:types` 在真实项目里不可用）**：三命令原先只 `event->trigger(RouteLoaded::class)`，但 ThinkPHP 8 里 include 路由文件的是 `Http::loadRoutes()`（只在 HTTP 派发路径上执行），`RouteLoaded` 只是「加载完毕」的通知、监听者仅来自服务包的 `Service::loadRoutesFrom()`。于是 console 里只收得到 forge 自身的端点与管理器路由，应用业务路由一条都不进收集结果——配了 `aliases` 时直接抛 `[RF_BE_008]` 悬空别名退出。框架自带 `route:list` 之所以正常，是因为它自己 `scanRoute()` include 完才 trigger 事件。
  - 修复：`list` / `types` / `gen` 统一先经新增的 `RouteFileLoader` 真实加载路由文件。加载姿势参照官方 `route:list`（目录取 `Http::getRoutePath()`、include 之后才 trigger 事件），但**刻意不跟**它两处破坏框架状态的动作（`Route::clear()`、`lazy(false)`）：对规则树只增不减、不重绑任何框架对象，加载结果只存在于当前进程。同一次扫描会被迭代多遍，故加载器由 `ForgeService` 绑成容器单例做进程内幂等（二次 include 会注册出**新的** `RuleItem` 对象，对象去重挡不住）。
  - 连带修好：`route:forge:gen` 的幂等基准读的是实时名称表 `Route::getName(null)`，路由文件没进规则树时 `route/app.php` 里已有的名字查不到，存在重复生成风险——现在这条承诺才真正成立。
  - 与 HTTP 严格同口径的两处取舍：`route/` **子目录**里的路由文件（框架按 `route_auto_group` 递归它们是 `route:list` 的展示福利）与 `app.with_route=false` 时连顶层文件都不加载，两者都**只给 warning、不悄悄多报**，守住「forge 看到的 == 运行时真在服务的」。warning 复用既有通道（STDERR 与 `list --json` 的 `warnings` 字段同现），只是数组元素新增，不改任何键与结构。
  - 回归测试：新增 16 例，其中 6 例走「业务路由只来自真实路由文件」这条此前完全空白的路径（含别名目标写在路由文件里能解析、同进程连跑两条命令不产生重复条目）。本包 129 例全绿却漏掉该缺陷的根源，正是测试里的路由全部由测试代码直接 `Route::get()` 注册，已在 `AGENTS.md` 记为铁律。另在真实骨架应用 `route-forge-thinkphp-example` 做 A/B：修复后 `list` / `types` 得 `public 5 / client 4 / manage 2 / unassigned 1` + 2 条别名、`manage/logs`（有 tier 无 name）warning 仍在，HTTP 端点与管理器 API 数字一致；还原成 1.1.0 后旧症状复现。
- **`route:forge:gen` 的产物不是合法 PHP**：`HEADER` 写在双引号串里多了一层转义，落盘成 `use think\\facade\\Route;`（两个连续反斜杠），生成的 `route/forge.auto.php` 直接 `ParseError`。而 `route/*.php` 在 HTTP 与 console 都会被 include，等于跑一次 gen 就把整个应用打挂；旧用例只对条目文本做字符串断言、从没加载过产物，所以缺陷长期隐身。现改用单引号串，并补「产物能被 `RouteFileLoader` 真实加载」的回归。
  - **升级提示**：1.1.0 上已经跑过 gen 的项目，磁盘上那个坏文件不会被自动修正（本命令只增不删）。gen 现在会自动检出坏头部，并**早于加载**停下报错、给出可照抄的正确写法——按提示手工改那一行，或删掉该文件后重跑（命令幂等，条目会重新生成）。
- **`route/` 下名为 `*.php` 的目录会让命令崩**：`glob('*.php')` 连目录一起匹配，`include` 目录只抛 `E_WARNING`，而 think 的 `Error` 初始化器把 warning 转成 `ErrorException`（例如 gen 写失败留下的同名占位目录）。加载器按官方 `route:list` 的判据（`DirectoryIterator` 且 `getType() === 'file'`）跳过非文件命中；不可读文件仍不静默跳过。

### Added

- **`src/Support/RouteFileLoader.php`**（内部支撑类）：console 侧唯一的路由文件加载入口，附 `warnings()` 输出「按运行时口径压根不会被加载」的结构性提示。公共契约未扩面——命令选项、端点与摘要结构、错误码集合均无变化。

## [1.1.0] - 2026-09-12

### Added

- **管理器 `/_forge/manager`（仅 `app_debug=true`）**：参考 laravel 版实现的可视化面板——层级总览、路由搜索与详情、levels 与全局设置的编辑落盘。
  - **两层访问控制**：非 debug 环境根本不注册管理器路由（判定走 `App::isDebug()`——think 只认 `APP_DEBUG=0/1`，读原始 env 会把生产当开发），再叠加 `manager_allowed_ips` 来源 IP 白名单。白名单读取处 `(array)` 归一，并区分「键缺失」（默认仅回环）与「显式 null」（不限制）——think 的 `Config::get` 点号路径按 `isset()` 判定，显式 null 会被误读成缺失。
  - **只读 API**：`GET api/routes`（含别名条目、剔 HEAD）、`GET api/config`（展示值与守卫生效值同源）。路由名统一带 `forge.manager.` 前缀，由 common 的排除规则兜住，不进任何元信息输出。
  - **页面零依赖**：包内自包含模板 + `ManagerPageRenderer` 直出，不依赖 `topthink/think-view`（`think\View` 只是 Manager 壳）。注入数据带 `JSON_HEX_TAG`，层级 description 里的 `</script>` 无法截断脚本块；占位符未替换完即抛错，不交付半个页面。
  - **写盘比 laravel 版更严三处**：复用 common 生成器后由 `ThinkConfigFileStyler` 只重排外壳为 think 风格，写前做**值不变性校验**（分别回读适配前后产物，严格相等才落盘）；覆盖前按本包纪律备份 `.bak-{Ymd-His}`；成功后清 `runtime/config.php` 与路由元信息缓存两级，改完下一个请求即生效。`classifier` 是闭包无法序列化，配置了它时拒存 422 而非静默抹平。
  - 生成物的值是**字面量**，不再包 `Env::get`：页面上改了就该立即生效，包回去会被 `.env` 旧值遮蔽成「改了没生效」；该取舍写进生成文件头部注释，需要 `.env` 驱动的手工改回。
  - 新增配置项 `manager_allowed_ips`（默认 `['127.0.0.1', '::1']`）。此前已发布过配置的项目没有该键，落到仅本机默认，不会因升级而放开访问。

### Fixed

- **层级 `endpoint_middleware` 传单值字符串时被静默忽略（端点裸奔）**：`ForgeService` 注册层级元信息端点时用裸值 `is_array()` 守卫，配置写 `'endpoint_middleware' => 'auth'`（与 think 的 `->middleware()` 同形的合法写法）会判 `false` 直接跳过注册——不报错、不崩溃，该层级元信息端点连中间件都没挂，开发者却以为它受保护，属危险方向的静默失效。现与摘要端点侧、laravel 版同口径在入口 `(array)` 归一（`null` → `[]`，保持「不限制」语义）。该配置项不经 `route-forge/common` 任何读取路径（common 1.1.1 的归一化只覆盖 `match.prefix` / `match.middleware` / `middleware_match`），故归一只能落在适配层。
  - 回归测试：层级 / 摘要端点各补一条「单值字符串写法仍被拦截」用例；反向验证过还原旧写法时层级端点返回 200 且正常吐出数据。
  - 文档：README 配置表与 `config/forge.php` 注释把 `endpoint_middleware` 写明为「数组或单个字符串都接受」。
- **依赖下限 `route-forge/common` `^1.1.1` → `^1.1.2`**：管理器的配置保存路径需要 1.1.2 的生成侧单值归一。页面里把 `match.prefix` / `match.middleware` 写成单值字符串（与 think 的 `->middleware()` 同形的直觉写法）时，1.1.1 会在 `exportInlineArray(array)` 的类型声明上 `TypeError`，表现为保存返回 500「配置写入失败」，真因只出现在应用日志里。已补端到端回归用例锁住该写法能保存成功并落盘为单元素数组。

## [1.0.0] - 2026-09-11

0.0.x 是脚手架期；自本版起承诺公共 API、`/_forge/routes` 端点契约与命令输出形态稳定，破坏性变更一律走 major。

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
