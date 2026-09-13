# Route Forge for ThinkPHP

**在 Vue / React / Inertia 单页应用（SPA）里直接使用 ThinkPHP 的命名路由——不必硬编码 URL，也不必把整张路由表打包给前端。**

Route Forge 通过一个轻量的 HTTP 元信息端点把 ThinkPHP 的命名路由暴露出去，支持**按层级（tier）拆分并按需懒加载**，并**生成 TypeScript 类型**，让前端的路由名与参数都具备类型安全。它零注解即可工作——直接读取 ThinkPHP 自己的路由规则树。

[![Latest Version on Packagist](https://img.shields.io/packagist/v/route-forge/thinkphp.svg?style=flat-square)](https://packagist.org/packages/route-forge/thinkphp)
[![Total Downloads](https://img.shields.io/packagist/dt/route-forge/thinkphp.svg?style=flat-square)](https://packagist.org/packages/route-forge/thinkphp)
[![PHP](https://img.shields.io/packagist/dependency-v/route-forge/thinkphp/php.svg?style=flat-square)](#环境要求)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-8-orange.svg?style=flat-square)](#环境要求)
[![Tests](https://img.shields.io/github/actions/workflow/status/route-forge/php-thinkphp/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/route-forge/php-thinkphp/actions)
[![License](https://img.shields.io/github/license/route-forge/php-thinkphp.svg?style=flat-square)](./LICENSE)

> 文档语言：简体中文（ThinkPHP 的使用者基本在国内，本包不做英文版）。机器可读概览见 [`llms.txt`](./llms.txt)。

**语言 / Language:** 简体中文

> **面向 AI 助手 / 编码 Agent：** 本包为 [`route-forge/thinkphp`](https://packagist.org/packages/route-forge/thinkphp)。完整功能规格（框架无关部分）见 [route-forge 文档站](https://route-forge.github.io/docs/)，ThinkPHP 差异见下方[「与 Laravel 版的差异」](#与-laravel-版的差异)。

## 解决什么问题

当 ThinkPHP 后端由一个 SPA（Vue / React / 独立部署的移动端 Web）承接时，前端需要拼接指向后端接口的 URL。常见做法各有各的痛：

- **在前端硬编码 URL**：与后端的路由知识重复、容易脱节、且易写错。
- **一次性注入整张路由表**：体积随应用增长，还会下发当前用户根本访问不到的路由。
- **每个接口手写 API 客户端**：每个项目重复造轮子，且没有类型安全。

Route Forge 让后端路由表成为**单一事实来源（single source of truth）**：前端在运行时按层级、按需获取它需要的东西，并从权威路由注册表直接生成 TypeScript 类型。

## 环境要求

- PHP `^8.2`
- ThinkPHP `^8.0`（topthink/framework 8.x）
- 前端搭配 [`@route-forge/core`](https://www.npmjs.com/package/@route-forge/core)（及 `@route-forge/vue` / `@route-forge/react`）获得完整类型推断与懒加载体验

## 安装

```bash
composer require route-forge/thinkphp
```

服务经 composer `extra.think.services` 自动发现（think-installer 生成 `vendor/services.php`）。若你的项目未启用自动发现，在 `app/service.php` 手动追加：

```php
return [
    // ...
    \RouteForge\ThinkPHP\ForgeService::class,
];
```

然后用命令把默认配置发布到应用配置目录（ThinkPHP 无 vendor:publish）：

```bash
php think route:forge:publish
```

该命令把包内 `config/forge.php` 复制为应用 `config/forge.php`；目标已存在时默认跳过、不覆盖你的改动（加 `--force` 覆盖，会先备份原文件）。若不便运行命令，手动复制等价：

```bash
cp vendor/route-forge/thinkphp/config/forge.php config/forge.php
```

未复制配置就运行 `route:forge:list` 等命令时，会给出 warning 并（交互终端下）询问是否立即复制，避免「忘了复制导致端点无数据」。

## 从自动路由起步

ThinkPHP 的长期习惯是**自动路由**（`/{控制器}/{操作}` 直接可达、不写 `Route::` 规则）。但 route-forge 的价值（分层、懒加载、TS 类型、按层级保护）**必须建立在可枚举的命名路由上**——自动路由产不出这些。为此提供 `route:forge:gen`：把「当前能被自动路由触达的端点」**反向物化成显式命名路由**，你在生成的文件上改即可，不必对着空白页从零写。

```bash
php think route:forge:gen              # 单应用：增量生成到 route/forge.auto.php
php think route:forge:gen --dry-run    # 先看会新增/提醒什么，不落盘
php think route:forge:gen --module=admin,api   # 多应用：只生成指定模块到 app/{模块}/route/forge.auto.php
php think route:forge:gen --module=*           # 多应用：显式扫全部模块
php think route:forge:gen --mode=single        # 布局有歧义时（app/controller 与模块目录并存）显式表态
```

语义刻意保守：

- **只新增、绝不删除**——命令永远不动你已写的规则；删规则是你自己的事。
- **幂等**——已在实时路由表、或已在生成文件里的名字自动跳过，可反复运行。
- **悬空只提醒**——生成文件里某条的控制器/方法已不存在时，仅报告「可自行清理」，不改动。
- **不写 tier**——生成条目先落 `unassigned`，留 `// ->tier('…') 待填` 注释，你按需分层。
- **防误用**——自动判定单/多应用：单应用禁 `--module`；多应用必须显式给 `--module`（或 `*`），不会「悄悄扫全部」。若 `app/controller` 与模块级控制器目录**并存**（从单应用迁多应用的常见残留），判定为有歧义、直接停下，要求你用 `--mode=single|multi` 表态，不替你猜。

边界（v1 如实说明）：命令按 think 自己的可达规则反推 URL——控制器段 `snake`、动作段是「方法名剔掉 `route.action_suffix`」的短形式（think 用「URL 段 + suffix」命中方法，故 `listView` 在 `action_suffix='View'` 下可达于 `user/list`）。方法名不以该后缀结尾的**本来就没有可达 URL**，命令只登记不生成（生成等于凭空新增端点）。invokable 控制器、带路径参数的端点同样只登记提示，交你手写。camelCase 方法（如 `batchImport`）会照常生成，但会给一条大小写风险提示：默认 `url_case_sensitive=false` 时新旧写法都能命中，若你设成 `true`，历史自动路由靠大小写不敏感命中的小写写法物化后会 404。切 `url_route_must=true`（强制路由）前，先 `route:forge:list` 核对覆盖，避免漏生成导致 404。

## 快速上手

### 定义路由层级

三种互相兼容的分配方式，任选或组合（层级名完全由你定义，包不预设固定层级）：

```php
use think\facade\Route;

// 1. 定义路由时显式标记（零侵入：走 think Rule::__call 落 option）
Route::get('auth/login', 'Auth@login')
    ->name('auth.login')
    ->tier('public');

// 2. 分组透传：整组继承层级，嵌套 group 内层覆盖外层
Route::group('manage', function () {
    Route::get('users', 'ManageUser@index')->name('manage.users.index');
})->tier('manage');

// 3. 配置驱动的批量分配：config/forge.php 按 URI 前缀 / 中间件（any / all / DNF）归类
```

**优先级**（高 → 低）：显式 `->tier()` > 分组透传 > `classifier` 回调 > 配置 match > `unassigned` 兜底。

> **ThinkPHP 差异**：think 无宏机制，`->tier()` 的层级合法性校验发生在**扫描期**
> （首次访问端点 / 运行命令时抛 `UnknownLevelException`），而非 Laravel 版的定义期
> fail-fast。命名路由必须**显式** `->name(...)`——think 会把「路由地址字符串」当作
> 默认路由标识，本包将其甄别为未命名路由，不会混入元信息。

### 端点

```text
GET /_forge/routes/{level}   # 该层级下所有命名路由的元信息（名称 + URI + method + 参数）
GET /_forge/routes           # 摘要端点：层级概览 + 全局配置，供前端自动发现
```

层级响应示例：

```json
{
  "level": "manage",
  "routes": {
    "manage.users.show": {
      "uri": "manage/users/{id}",
      "methods": ["GET", "HEAD"],
      "parameters": ["id"],
      "parameter_defaults": {}
    }
  }
}
```

URI 模板统一转换为 `{param}` / `{param?}` 语法（think 定义中的 `<id>` / `[:page]` / `{id}` 语法均自动归一），与前端 `@route-forge/core` 契约一致。

### Artisan 风格命令（think console）

```bash
# 查看所有路由的层级分配（--level=manage / --json / --unassigned / --aliases）
php think route:forge:list

# 生成 TS 类型声明（--level / --json / --out=../frontend/src/types/forge-routes.d.ts）
php think route:forge:types

# 清除路由元信息缓存（--level=manage 清除单层级并同步失效摘要）
php think route:forge:clear

# 发布默认配置到应用 config/forge.php（目标已存在默认跳过；--force 覆盖并自动备份）
php think route:forge:publish

# 从自动路由增量生成显式命名路由（详见「从自动路由起步」）
php think route:forge:gen
```

#### 终端着色

五条命令的提示语沿用 think console 的 `<info>` / `<comment>` / `<error>` 标签（层级统计、别名黄行、撞车红行同理）。think 自带的着色检测在 Windows 上有一条陈旧判据：它要求系统版本号**精确等于** `10.0.10586`（Win10 1511 的首发版号），且只认 `TERM` 严格等于 `xterm`——于是 Win11 与 Git Bash（`TERM=xterm-256color`）统统被判成「不支持颜色」，标签被剥成纯文本。本包在命令层重做这道判定（`ConsoleColorDetector`），使观感与 Laravel 版一致：

- Windows 下改为「版本号 ≥ 10.0.10586 **且** PHP 成功开启控制台 VT 模式」，并识别 Windows Terminal（`WT_SESSION`）、mintty / Git Bash（`MSYSCON`）、ConEmu、cmder 以及带后缀的 `TERM`；
- `stdout` 不是终端（管道、重定向、CI）时一律不上色——`route:forge:list --json` 与 `route:forge:types` 的产物里永远不会混入 ANSI 转义码；
- 遵守 `NO_COLOR` 与 `TERM=dumb`；
- 显式表态优先：命令带上 `--ansi` 或 `--no-ansi` 时本包完全不介入，判定交回框架。`--ansi` 同时是自动判定偏保守时的逃生舱——个别终端下 PHP 认不出控制台（判不出就宁可不上色，免得把 `←[32m` 这类乱码写进终端），加上它即可。

### 路由别名（改名迁移 / 长期稳定对外名）

```php
// 通道一：路由链式声明（可一次声明多个旧名）
Route::get('manage/members', 'Member@index')
    ->name('admin.members.index')
    ->tier('manage')
    ->forgeAlias('admin.users.index', 'admin.users.old');

// 通道二：config/forge.php 集中声明
// 'aliases' => ['admin.users.index' => 'admin.members.index']
```

别名条目出现在目标路由所在层级的元信息中，与目标路由完全一致——前端零改动。悬空别名抛 `AliasTargetException`（RF_BE_008）。

### 内嵌摘要（服务端渲染场景）

ThinkPHP 模板无 Blade 指令机制，等价物是全局 helper（包安装后自动可用）：

```html
<head>
    {:forge_summary()}
    <script src="/js/app.js"></script>
</head>
```

输出一段 `<script>`，以一次性、消费即自删、不可枚举的 `window.__ROUTE_FORGE__` 访问器暴露摘要，`@route-forge/core` 读取后跳过首屏的摘要 HTTP 往返。XSS 安全编码，`</script>` 无法截断脚本块。

### 管理器（仅开发环境）

浏览器访问 `/_forge/manager`：看层级分布、按名称/URI/中间件搜路由、看单条路由详情、编辑层级与全局配置。形态与 Laravel 版一致——包内**单文件自包含** HTML（内联 CSS/JS，零 CDN、零构建产物、零 npm 依赖），取数走同前缀下的相对路径，部署在子目录也不受影响。

```text
GET  /_forge/manager                # 页面
GET  /_forge/manager/api/routes     # 全部命名路由 + 层级归属（别名条目带 alias_of）
GET  /_forge/manager/api/config     # 当前 levels 与全局设置
PUT  /_forge/manager/api/config     # 重新生成 config/forge.php
```

两层访问控制叠加，缺一不可：

1. **非 `app_debug` 环境根本不注册这些路由**，生产连探测面都不存在。判定走 `App::isDebug()`：ThinkPHP 只认 `APP_DEBUG=0/1`，`.env` 里 `app_debug=false` 字符串对 think 的 env 解析是真值，直接读原始 env 会把生产当开发。
2. **来源 IP 白名单** `manager_allowed_ips`：默认 `['127.0.0.1', '::1']`（仅本机；`::1` 是防浏览器把 localhost 解析成 IPv6 回环）；列表含 `'*'` 放行任意来源；`null` 或空数组表示不做 IP 限制（局域网暴露自担风险）；数组或单个字符串都接受。

ThinkPHP 侧的落地差异：

- 页面不依赖视图引擎：`think\View` 只是 Manager 壳，模板驱动位于 `\think\view\driver\`（要另装 `topthink/think-view` 才有实现），为管理器强加这个依赖不划算，故由 `ManagerPageRenderer` 读包内模板直出 HTML；注入数据带 `JSON_HEX_TAG` 转义，层级 description 里的 `</script>` 无法截断脚本块。
- 保存前先备份为 `config/forge.php.bak-{Ymd-His}`，与 `route:forge:publish --force` 同一套纪律。
- 保存后清两级缓存：删 `runtime/config.php`（think 的编译配置缓存，命中即整体覆盖配置），并整体失效路由元信息缓存，改完下一个请求即生效。
- 生成的 `config/forge.php` 里值是**字面量**，不再经 `Env::get` 读 `.env`——在页面上改了就该立即生效，否则会被 `.env` 旧值遮蔽成「改了没生效」；需要 `.env` 驱动的手工改回，生成文件头部也写明了这点。
- `classifier` 是闭包、无法序列化进配置文件：配置了它时保存直接拒（422），而不是静默抹平用户的分类逻辑。
- 写盘失败只在响应里给通用提示、细节进应用日志（异常消息含服务器绝对路径，而白名单可以被显式配成 `'*'`）。

`route:forge:publish` 之前发布过配置的老项目，其 `config/forge.php` 里没有 `manager_allowed_ips` 键，会落到安全默认（仅本机可访问）。

### IDE 智能提示

`->tier()` / `->forgeAlias()` 经 ThinkPHP 的 `__call` 魔术方法落到路由 option，类里没有真实声明，IDE 默认不会对它们补全。包根附带一份 **dev-only** 提示桩 `_ide_helper.php`（对 `think\route\Rule` 贴 `@method`）：

- PHPStorm 等会自动索引它，从而让 `->tier('...')` 有补全/跳转；
- 该文件**不在 composer autoload 内**，切勿 `require` 或加入自动加载（否则与真实类冲突）；
- 若 IDE 未识别，把包根目录加入 Settings → PHP 的 Include Path；
- 属可选便利，ThinkPHP 若原生新增同名方法请自行忽略或删除该桩。

运行时对拼错的链式方法（如 `->tiere()`）另有兜底：`route:forge:list` / `types` 会给出拼写告警。

## 配置参考

完整字段与 Laravel 版一致，见包内 `config/forge.php` 注释或 [route-forge 文档站](https://route-forge.github.io/docs/)。核心项：

| 键                   | 类型           | 默认值             | 说明                                                                 |
|----------------------|----------------|--------------------|----------------------------------------------------------------------|
| `levels`             | `array`        | `[]`               | 层级定义表（description / match / load / endpoint_middleware）        |
| `endpoint_prefix`    | `string`       | `'/_forge/routes'` | 元信息对外端点前缀                                                   |
| `url_prefix`         | `string\|null` | `null`             | 应用路由前缀（完整 URL 或路径前缀），经摘要 `config.url_prefix` 下发；`null` 不下发 |
| `endpoint_middleware`| `string\|string[]` | `[]`       | 摘要端点中间件；数组或单个字符串都接受（`levels.*.endpoint_middleware` 同形），空数组 / `null` 不限制 |
| `cache_ttl`          | `int\|null`    | `3600`             | 统一缓存 TTL（秒）；`null` 不缓存，`0` 永久缓存                       |
| `cache_driver`       | `string\|null` | `null`             | think 缓存驱动（`file` / `redis` 等）；`null` 用默认驱动             |
| `strict_mode`        | `bool`         | `false`            | 严格模式：未命中层级抛异常或归入 `unassigned`                        |
| `scheme_version`     | `int`          | `1`                | 摘要端点 `schemeVersion`（格式版本，破坏性变更时递增）               |
| `classifier`         | `callable\|null` | `null`           | 自定义分类回调 `fn(\think\route\RuleItem $r): ?string`               |
| `aliases`            | `array`        | `[]`               | 别名映射表（键=别名，值=真实路由名）                                 |
| `manager_allowed_ips`| `string\|string[]` | `['127.0.0.1', '::1']` | 管理器来源 IP 白名单（仅 `app_debug=true` 时生效）；`'*'` 放行任意，`null` / 空数组不限制 |

开发模式（`app_debug=true`，即 `.env` 的 `APP_DEBUG=1`）下自动跳过所有缓存读写，路由变更即时生效。

## 与 Laravel 版的差异

核心业务逻辑（层级解析、别名、仓库、类型生成、缓存）全部在框架无关的 [`route-forge/common`](https://github.com/route-forge/php-common) 中，两端行为一致。ThinkPHP 侧的适配差异如实说明：

| 能力 | Laravel 版 | ThinkPHP 版（本包） |
|------|------------|---------------------|
| `->tier()` 校验时机 | 定义期 fail-fast（宏） | **扫描期**（`TierResolver` 抛 `UnknownLevelException`） |
| 命名路由甄别 | `getName()` 即显式命名 | 默认标识=路由地址字符串，需显式 `->name(...)`；name === 地址 时视为未命名 |
| 资源路由 `->tier()` | 生效（写入每条资源路由 action） | 机制上生效，但资源路由无显式命名 → 不进元信息；需要元信息请手写逐条路由 |
| `url_lazy_route` | —（无此机制） | **不支持**：开启后端点扫描/命令 fail-fast 抛异常（延迟解析下规则树不完整） |
| `route:forge:clear` 联动 | 监听 `route:clear` 自动连带清除 | think 无 `route:clear` 命令，无联动 |
| 配置发布 | `vendor:publish`（Laravel 原生） | `php think route:forge:publish` 命令复制默认配置；未复制时运行其他命令会 warning + 交互式提示复制 |
| 管理器页面 | Blade 模板 `view('forge::manager')`；保存裸写 `config/forge.php`，随后删 `bootstrap/cache/config.php` | 包内自包含 HTML 直出（不依赖 `topthink/think-view`）；保存前自动备份，写后清 `runtime/config.php` + 路由元信息缓存 |
| 命令警告输出 | stderr（`--out` 时 stdout 产物纯净） | think console 无独立 stderr 流，直写 `STDERR`，stdout 产物同样纯净 |
| Windows 终端着色 | symfony/console 的检测已跟进（Win10 1511+ / Windows Terminal 自动生效） | think 的检测要求版本号**精确等于** `10.0.10586` 且 `TERM` 严格等于 `xterm`，Win11 与 Git Bash 恒判「无色」；本包在命令层重做该判定对齐观感，框架原生 `--ansi` / `--no-ansi` 仍优先（见「终端着色」） |
| `@forgeSummary` 指令 | Blade 指令 | 全局 helper `forge_summary()`（模板 `{:forge_summary()}`） |
| 连续多次 `->forgeAlias()` | 合并（宏内部 merge） | **覆盖**（`setOption` 语义）：所有别名须在一次调用中声明 |

## 设计说明

- **零侵入**：不继承、不重绑 think 路由管理器。`->tier()` / `->forgeAlias()` 经 think `Rule::__call` 落到路由 option；分组透传由 think `getOption()` 读时合并（子覆盖父）天然成立。代价是定义期校验缺失，换来的是对 think 大版本升级的最小跟进成本。
- **可重复扫描**：路由集合视图每次扫描重新遍历 think 规则树（域名 → 分组 → 资源 → 规则），不依赖序列化快照。
- **缓存键**：`route-forge:{level}` / `route-forge:summary`，经 `_keys` 索引支持全量清除。

## 测试

```bash
composer install
vendor/bin/phpunit
```

测试基于最小 think 应用 fixture（无骨架依赖），覆盖端点契约、层级分配、别名、缓存与命令。临时文件默认写入 `F:\tmp`（可用环境变量 `RF_TEST_TMP` 覆盖，CI 回退系统临时目录）。

## License

[MIT](./LICENSE)
