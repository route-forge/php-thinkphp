# AGENTS.md — route-forge/thinkphp

ThinkPHP 8 适配包。框架无关业务逻辑全部在 `route-forge/common`（与 `route-forge/laravel` 共用），本包只做 ThinkPHP 路由原语 → common 契约的适配。**零侵入**：不继承、不重绑 think 的 `Route` / `RuleGroup` / `RuleItem`。

## 环境与命令

- 本机 PHP/composer 不在 PATH：`D:/software/bin/php.bat`、`D:/software/bin/composer.bat`（实为 `D:/software/php/composer.phar`）。
- 装依赖：`composer update`（本地 `composer.json` 含指向 `G:/web/php-common` 的 path repository，见下「本地关联」）。
- 跑测试：`vendor/bin/phpunit`（或 `D:/software/bin/php.bat vendor/phpunit/phpunit/phpunit --colors=never`）。
- 临时目录一律用 `F:/tmp`（测试工厂已默认，可用环境变量 `RF_TEST_TMP` 覆盖；CI 无 `F:/tmp` 自动回退系统临时目录）。

## 本地关联（composer.json 提交纪律）

- `composer.json` 的 `repositories`（path → `php-common` + `versions: route-forge/common 1.1.2`）是**本地开发专用**，**禁止提交**。`require` 下限同为 `^1.1.2`（1.0.0 的 `match.prefix` 单值字符串会 `TypeError` 崩、`JsSafeEncoder` 未去 unicode 转义会让中文 description 输出 `\uXXXX`；1.1.1 的 `ConfigFileGenerator` 缺生成侧单值归一，管理器保存 `"prefix": "admin"` 这种写法会 500），挂回 path repo 时版本覆盖须与之下限一致。
- `composer.json` 允许提交的必要字段仅限：`autoload.files`（`src/Support/functions.php`）与 `extra.think.services`（`ForgeService`）。
- 提交 `composer.json` 前先剥离 `repositories`，提交后再写回本地（保持工作树 dirty，符合「本地关联不入库」约定）。

## 架构地图

- `src/ForgeService.php` — think `Service`：register 绑定 common 服务单例，boot 注册元信息端点 + 管理器路由 + 命令；`classifier` 经 `Closure::fromCallable` 包装并回填 `RouteInfo::source`。绑定顺序有硬约束：`ManagerConfigStore` 要拿**同一个** `RouteCache` 单例才能显式失效缓存，必须排在它后面。
- `src/Adapter/ThinkRouteNormalizer.php` — `RuleItem` → `RouteInfo`：命名甄别、URI 归一（`<id>`/`<id?>` → `{id}`/`{id?}`）、methods 归一（`*` 展开、GET 补 HEAD）、middleware 元组展平、`forgeAlias` 的 `__call` 多参形态归一。
- `src/Adapter/ThinkCacheAdapter.php` — think `cache\Driver` → `CacheInterface`；TTL：common `null` → think `set(..., 0)`（0=永久），**绝不对 think 传 null**（那是「默认 expire」）。
- `src/Support/RouteCollector.php` — 遍历域名→分组→资源→规则树收集业务 `RuleItem`（排除 MISS、`url_lazy_route=true` fail-fast）。
- `src/Support/RouteFileLoader.php` — **console 侧唯一的路由文件加载入口**：姿势照搬官方 `think\console\command\RouteList`（自己 include `route/*.php`，之后才 trigger `RouteLoaded`）。目录取 `$app->http->getRoutePath()`（尊重 `setRoutePath()` 覆写）、只加载扁平顶层文件、跳过非文件命中、`app.with_route=false` 时同步不加载；**进程内幂等**（二次 include 会注册出新 `RuleItem`，collector 的对象去重挡不住），故由 `ForgeService` 绑成单例。刻意不跟官方那两处破坏框架状态的动作——`Route::clear()` 与 `lazy(false)`；对规则树只增不减，且不重绑任何框架对象。`warnings()` 说清「哪些路由文件按运行时口径压根不会被加载」（子目录文件、`with_route=false`），供命令并入 warnings。
- `src/Support/ThinkRouteCollection.php` — `IteratorAggregate`，每次迭代重新收集（common 会多次扫描，禁用一次性 Generator）。
- `src/Support/functions.php` — 全局 `forge_summary()`（无命名空间：命名空间函数不可 autoload，且模板 `{:forge_summary()}` 需全局可见）；未注册 `ForgeService` 时抛可操作提示而非容器堆栈。
- `src/Support/OptionTypoScanner.php` — 扫描路由 option 里 `tier*`/`forge*` 形态的疑似拼错键（`->tiere()` 等被 `__call` 静默吞掉的产物），在 list/types 输出 warning；正常 think 选项不误报（保守前缀匹配）。
- `src/Support/ConfigPublisher.php` — forge 配置文件的写盘入口，替代 ThinkPHP 缺失的 vendor:publish：`publish(force)` 把包内 `config/forge.php` 复制到应用；`save(content)` 供管理器以新内容覆盖。**两者共用 `backupIfPresent()`**：覆盖前一律备份为 `.bak-{Ymd-His}`（同秒追加序号），写后校验非空。`targetPath()` 是管理器与命令共用的唯一定位点。
- `src/Http/ForgeManagerController.php` — 管理器四动作：`index`（页面 HTML）、`routes`/`config`（只读 JSON）、`updateConfig`（写盘）。`globalConfig()` 是页面与 config API 的**唯一**展示口径，其键集合必须与写盘侧 preserved 一致；写盘异常只记日志不回显（消息含服务器绝对路径，而白名单可被配成 `'*'`），响应只回 `backed_up` 布尔。
- `src/Http/Middleware/ManagerAllowedIps.php` — 来源 IP 白名单（第二层；第一层是非 debug 根本不注册路由，判定必须用 `App::isDebug()`）。`allowedIps()` 是**唯一**读取口，守卫、config API、写盘 preserved 三处共用，避免同一键的不同读取点对「缺失」给出相反答案；因 think `Config::get` 点号路径按 `isset()` 判定，这里整组取出后用 `array_key_exists` 区分「键缺失」（默认仅回环）与「显式 null」（不限制）。
- `src/Support/ManagerPageRenderer.php` + `resources/manager.html` — 页面零依赖直出：读包内自包含模板（内联 CSS/JS，由 laravel 版 blade 移植，仅两处占位符），`strtr` 注入 `JSON_HEX_TAG|UNESCAPED_UNICODE|UNESCAPED_SLASHES` 的数据与 int 强转的版本号，残留 `__FORGE_` 即抛错。模板与常量里的占位符名是隐式契约，改名必须两边同步（`ManagerPageRendererTest` 有契约用例锁住）。
- `src/Support/ManagerConfigStore.php` — 写盘编排：common `ConfigFileGenerator` 生成 → `ThinkConfigFileStyler` 换外壳 → **值不变性校验** → `ConfigPublisher::save()` 备份写入 → 清 `runtime/config.php` + `RouteCache::clear()`。校验基准取「适配前的生成物」而非页面提交值（common 会补 `match` 默认结构与 `load`，比提交值必然不等，会把正常保存全判成失败）。
- `src/Support/ThinkConfigFileStyler.php` — 只动外壳：Laravel 桶形注释块压成 `//`、头注释改指文档站、键后填充规范化。**不做全文正则扫描**：`var_export` 遇含换行的 description 会输出真实换行，只匹配「注释块 + 紧随其后的白名单键」这一形态，键不在 `KNOWN_KEYS` 里宁可抛错；common 新增字段时必须同步该白名单。生成物刻意不带 `Env::get`（页面改了就该生效），取舍写在生成文件头注释里。
- `src/Console/Concerns/WarnsMissingConfig.php` — 三命令启动守卫：缺 `config/forge.php` 时，数据产物形态（`list --json` / `types`）只写 STDERR 不污染 stdout；人类可读形态（`list` 表格 / `clear`）打印 warning 并在交互终端 `confirm` 询问立即复制（非交互 `confirm` 返回默认 false，CI 不挂起、不读 STDIN）。
- `src/Console/*` — `route:forge:list|types|clear|publish|gen`；`execute` 覆写为 `$this->app->invoke([$this,'handle'])` 吃容器方法注入；`list` / `types` / `gen` 动手前先经 `RouteFileLoader` **真实加载** `route/*.php`（见上条；旧写法「只 trigger `RouteLoaded`」根本不会加载应用路由，1.1.0 的命令侧缺陷就源于此），之后照常 trigger 以放行服务包注册的监听者；`gen` 早于加载拦下 1.1.0 写坏的旧产物（坏 `use` 行不是合法 PHP，include 即 ParseError）。
- `src/Console/Concerns/ReportsCommandFailure.php` — 命令失败统一出口：Forge 系异常打 `[错误码] 消息`、适配层自己的 fail-fast（`url_lazy_route` 的 `RuntimeException`、非 `RuleItem` 的 `InvalidArgumentException`）只打消息，两者都不倒框架堆栈；数据产物形态（`list --json` / `types`）失败信息写 STDERR 保 stdout 纯净。刻意不自造错误码——`RF_BE_0NN` 由 common 统一登记，加码头会凭空扩面前端契约。
- `src/Support/AutoRouteScanner.php` — `route:forge:gen` 的扫描器：`detectMode()` 判 `single|multi|ambiguous`（多应用已装但 `app/controller` 与模块控制器目录并存 = 有歧义，由命令层停下要求显式 `--mode`，不替用户猜）、枚举可自动路由触达的「控制器+public 自有方法」，按 dispatch 逆推 URI/命名/目标（控制器段 `Str::snake`、子目录成路径段；**动作段按 think 的可达规则反推**：think 用「URL 段 + `route.action_suffix`」命中方法，故可达 URL = 方法名剔掉 suffix 的短形式，不以 suffix 结尾的方法无可达 URL → 打 `unreachable` 只登记不生成，含大写的动作段打 `mixedCaseAction` 由命令层提示大小写风险）。只增不删、悬空只提醒的语义在命令层实现；**改动作段口径时必须同步 `RouteForgeGenCommand::collectStale()`**——它按 target 反查方法名，须同样允许「短形式或拼回 suffix」，否则每次运行都报假悬空。夹具 `tests/Fixtures/{MultiAppLayout,MixedAppLayout,SuffixApp}` 覆盖多应用/混合布局/带 action_suffix——此前 think-multi-app 未装使 multi 分支零测试，detectMode 的死分支才长期存活。

## ThinkPHP 关键机制（易踩坑）

- **无宏系统**：`Rule::__call` 把未知链式方法转成 `setOption(方法名, 值)`。`->tier('x')` / `->forgeAlias(...)` / 分组链式 `->tier()` 全部白嫖此机制落到 option。副作用：定义期不校验层级合法性（扫描期由 `TierResolver` 抛 `UnknownLevelException`）；多次 `->forgeAlias()` 是**覆盖**非合并。
- **零侵入的两处兜底**：拼错的链式方法（`->tiere()` 等）被 `__call` 静默吞掉 → `OptionTypoScanner` 在 list/types 扫 `tier*`/`forge*` 疑似拼错键给 warning；IDE 无补全 → 包根 `_ide_helper.php`（dev-only，**不得进 autoload/require**，否则与真实 `think\route\Rule` 冲突）贴 `@method`。改了 `->tier()/->forgeAlias()` 的签名/语义时，`_ide_helper.php`、`llms.txt`、README 的 IDE 小节须一并同步。
- **分组选项动态合并**：`Rule::getOption()` 读时把父组 option `array_merge` 进来、子覆盖父 → 「内层覆盖外层 / 显式覆盖分组」天然成立。`forgeAlias`/`tier` 不在 `mergeOptions` 白名单（只有 `model/append/middleware` 深合并），故为整体覆盖语义。
- **命名路由甄别**：`RuleGroup::addRule` 里 `$name = is_string($route) ? $route : null` → 未 `->name()` 的路由 `getName()` 返回路由地址字符串。判定「显式命名」= name 非空 **且 ≠ 路由地址字符串**。
- **HTTP 测试**：`$app->http->run($request)` 返回 `Response`（不 echo）；`Request` 用 `setPathinfo()`（**不带前导 `/`**，与生产 pathinfo 解析一致）+ `setMethod()`。
- **console 测试**：`$app->console->find($name)->run(new Input([$name, ...$args]), new Output('buffer'))->fetch()`。`Console::call` 不返回退出码，需要断言退出码时直连 `find()->run()`。
- **`think\View` 只是 Manager 壳**：模板驱动在 `\think\view\driver\` 下，需另装 `topthink/think-view` 才有实现，未装时 `View::fetch()` 直接炸。包内页面（管理器）因此走 `ManagerPageRenderer` 直出，**不要**为它引入视图引擎依赖；但 `forge_summary()` 的模板 `{:forge_summary()}` 场景仍属宿主自己装 think-view。
- **`Config::get('forge.x', $default)` 按 `isset()` 判命中**：显式写成 `null` 的配置值会被当成「键缺失」而返回默认值。凡「缺失」与「显式 null」语义相反的键（目前只有 `manager_allowed_ips`：缺失=仅本机，null=不限制），必须 `Config::get('forge')` 整组取回后用 `array_key_exists` 判别——入口只有 `ManagerAllowedIps::allowedIps()` 一个。
- **`runtime/config.php` 会整体覆盖配置**：`App::load` 命中该文件就 `set(include)`（`optimize:config` 的产物），改了 `config/forge.php` 不删它就是「保存成功但没生效」。管理器写盘已连带清除，且同时 `RouteCache::clear()`（levels 变了层级解析全废）。
- **测试里造 JSON 请求体**：`new Request()` 绕过 `__make`，而 `contentType()` 读的是 header 集合（正常由 `__make` 从 `$_SERVER['CONTENT_TYPE']` 派生成**连字符**键），`withHeader()` 只小写化不转连字符。必须 `withHeader(['content-type' => 'application/json'])` 且早于 `withInput()`，否则 raw body 不按 JSON 解析、`$request->put` 停在 null 并在 `input()` 的类型声明上抛 TypeError（`Http::putJson` 已封装）。
- **404 断言会触发框架模板弃用**：非 debug 且未配 `app.http_exception_template` 时 think 渲染 HTML 异常模板，PHP 8.5 下报 `htmlentities(null)` 弃用（测试输出里那个 `D`）。断 404 的用例统一带 `HTTP_ACCEPT: application/json` 走 JSON 分支，别把这个噪音当成本包 bug。
- **think 只认 `APP_DEBUG=0/1`**：`.env` 里 `app_debug=false` 字符串对 think env 解析是真值。
- **`.env` 由 `parse_ini_file` 解析**：注释行里出现引号会让**整份文件**读取失败（返回 `false`），只有一条 Warning、不抛异常，于是 `APP_DEBUG` 静默回落到默认值——「以为关了其实是开的」。写 `.env` 注释只用中文与句读，别放引号（示例项目实测）。
- **`RouteLoaded` 事件不加载路由文件**：TP8 里 `include route/*.php` 的是 `Http::loadRoutes()`，它在 `dispatchToRoute()` 的闭包内先 include、后 trigger；`RouteLoaded` 本身是空事件类，监听者只来自 `think\Service::loadRoutesFrom()`（即服务包自带路由）。框架自带 `route:list` 同理——它自己 `scanRoute()` include 完才 trigger 事件。所以 **console 场景光 trigger 一条应用路由都收不到**，必须先经 `src/Support/RouteFileLoader.php` 真实加载（`list` / `types` / `gen` 已接入）。两处与 HTTP 严格同口径的取舍：`route/` **子目录**里的路由文件运行时压根不会被 include（官方 route:list 按 `route_auto_group` 递归它们只是展示福利），以及 `app.with_route=false` 时连顶层文件都不加载——两者都只给 warning、不悄悄多报。教训（1.1.0 缺陷的根源）：**测试里的业务路由必须至少有一组来自真实路由文件**，全由测试代码 `Route::get()` 注册会形成结构性盲区，129 例全绿也遮不住；命令侧行为不得拿「包内测试绿」背书，要在 `G:/web/route-forge-thinkphp-example` 里跑（把本包 `src/` 覆盖进 `vendor/route-forge/thinkphp/src` 做 A/B，验完用备份还原——还原后旧症状复现即证修复有效）。
- **写盘的 PHP 产物必须被真实 include 过一次才算验过**：`route:forge:gen` 的 `HEADER` 曾因双引号串里多写一层转义，落盘成 `use think\\facade\\Route;`（两个连续反斜杠）——产物直接 ParseError，而 `route/*.php` 在 HTTP 与 console 都会被 include，等于跑一次 gen 就把应用打挂；旧用例只 `assertStringContainsString` 过条目文本、从没加载过产物，所以缺陷长期隐身。写这类字符串一律用**单引号**（单引号里 `\f` 就是字面反斜杠 + f；双引号里 `\f` 是换页符、要落一个反斜杠得写 `\\`），并配套补「产物可被 `RouteFileLoader` 加载」的回归。另存顺序依赖：gen 里旧坏产物的检测必须**早于** `load()`，否则 include 先炸。

## 提交纪律

- 提交信息：`type(thinkphp): 中文描述`（feat/fix/test/docs/refactor/chore）。
- 任何提交前跑全量测试，全绿才提；按功能块提交（源码与其测试同块）。
- 不自行 `git push`，等用户指示。
