# AGENTS.md — route-forge/thinkphp

ThinkPHP 8 适配包。框架无关业务逻辑全部在 `route-forge/common`（与 `route-forge/laravel` 共用），本包只做 ThinkPHP 路由原语 → common 契约的适配。**零侵入**：不继承、不重绑 think 的 `Route` / `RuleGroup` / `RuleItem`。

## 环境与命令

- 本机 PHP/composer 不在 PATH：`D:/software/bin/php.bat`、`D:/software/bin/composer.bat`（实为 `D:/software/php/composer.phar`）。
- 装依赖：`composer update`（本地 `composer.json` 含指向 `G:/web/php-common` 的 path repository，见下「本地关联」）。
- 跑测试：`vendor/bin/phpunit`（或 `D:/software/bin/php.bat vendor/phpunit/phpunit/phpunit --colors=never`）。
- 临时目录一律用 `F:/tmp`（测试工厂已默认，可用环境变量 `RF_TEST_TMP` 覆盖；CI 无 `F:/tmp` 自动回退系统临时目录）。

## 本地关联（composer.json 提交纪律）

- `composer.json` 的 `repositories`（path → `php-common` + `versions: route-forge/common 1.0.0`）是**本地开发专用**，**禁止提交**。
- `composer.json` 允许提交的必要字段仅限：`autoload.files`（`src/Support/functions.php`）与 `extra.think.services`（`ForgeService`）。
- 提交 `composer.json` 前先剥离 `repositories`，提交后再写回本地（保持工作树 dirty，符合「本地关联不入库」约定）。

## 架构地图

- `src/ForgeService.php` — think `Service`：register 绑定 common 服务单例，boot 注册端点 + 命令；`classifier` 经 `Closure::fromCallable` 包装并回填 `RouteInfo::source`。
- `src/Adapter/ThinkRouteNormalizer.php` — `RuleItem` → `RouteInfo`：命名甄别、URI 归一（`<id>`/`<id?>` → `{id}`/`{id?}`）、methods 归一（`*` 展开、GET 补 HEAD）、middleware 元组展平、`forgeAlias` 的 `__call` 多参形态归一。
- `src/Adapter/ThinkCacheAdapter.php` — think `cache\Driver` → `CacheInterface`；TTL：common `null` → think `set(..., 0)`（0=永久），**绝不对 think 传 null**（那是「默认 expire」）。
- `src/Support/RouteCollector.php` — 遍历域名→分组→资源→规则树收集业务 `RuleItem`（排除 MISS、`url_lazy_route=true` fail-fast）。
- `src/Support/ThinkRouteCollection.php` — `IteratorAggregate`，每次迭代重新收集（common 会多次扫描，禁用一次性 Generator）。
- `src/Support/functions.php` — 全局 `forge_summary()`（无命名空间：命名空间函数不可 autoload，且模板 `{:forge_summary()}` 需全局可见）。
- `src/Support/ConfigPublisher.php` — 把包内 `config/forge.php` 复制到应用 `config/forge.php`（`isPublished`/`publish(force)`；force 覆盖前备份 `.bak-{Ymd-His}`），替代 ThinkPHP 缺失的 vendor:publish。
- `src/Console/Concerns/WarnsMissingConfig.php` — 三命令启动守卫：缺 `config/forge.php` 时，数据产物形态（`list --json` / `types`）只写 STDERR 不污染 stdout；人类可读形态（`list` 表格 / `clear`）打印 warning 并在交互终端 `confirm` 询问立即复制（非交互 `confirm` 返回默认 false，CI 不挂起、不读 STDIN）。
- `src/Console/*` — `route:forge:list|types|clear|publish`；`execute` 覆写为 `$this->app->invoke([$this,'handle'])` 吃容器方法注入；命令内先 `event->trigger(RouteLoaded::class)` 加载路由文件（同 think 自带 `route:list`）。

## ThinkPHP 关键机制（易踩坑）

- **无宏系统**：`Rule::__call` 把未知链式方法转成 `setOption(方法名, 值)`。`->tier('x')` / `->forgeAlias(...)` / 分组链式 `->tier()` 全部白嫖此机制落到 option。副作用：定义期不校验层级合法性（扫描期由 `TierResolver` 抛 `UnknownLevelException`）；多次 `->forgeAlias()` 是**覆盖**非合并。
- **分组选项动态合并**：`Rule::getOption()` 读时把父组 option `array_merge` 进来、子覆盖父 → 「内层覆盖外层 / 显式覆盖分组」天然成立。`forgeAlias`/`tier` 不在 `mergeOptions` 白名单（只有 `model/append/middleware` 深合并），故为整体覆盖语义。
- **命名路由甄别**：`RuleGroup::addRule` 里 `$name = is_string($route) ? $route : null` → 未 `->name()` 的路由 `getName()` 返回路由地址字符串。判定「显式命名」= name 非空 **且 ≠ 路由地址字符串**。
- **HTTP 测试**：`$app->http->run($request)` 返回 `Response`（不 echo）；`Request` 用 `setPathinfo()`（**不带前导 `/`**，与生产 pathinfo 解析一致）+ `setMethod()`。
- **console 测试**：`$app->console->find($name)->run(new Input([$name, ...$args]), new Output('buffer'))->fetch()`。`Console::call` 不返回退出码，需要断言退出码时直连 `find()->run()`。
- **think 只认 `APP_DEBUG=0/1`**：`.env` 里 `app_debug=false` 字符串对 think env 解析是真值。

## 提交纪律

- 提交信息：`type(thinkphp): 中文描述`（feat/fix/test/docs/refactor/chore）。
- 任何提交前跑全量测试，全绿才提；按功能块提交（源码与其测试同块）。
- 不自行 `git push`，等用户指示。
