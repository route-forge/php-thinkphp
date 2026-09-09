# Route Forge for ThinkPHP

**在 Vue / React / Inertia 单页应用（SPA）里直接使用 ThinkPHP 的命名路由——不必硬编码 URL，也不必把整张路由表打包给前端。**

Route Forge 通过一个轻量的 HTTP 元信息端点把 ThinkPHP 的命名路由暴露出去，支持**按层级（tier）拆分并按需懒加载**，并**生成 TypeScript 类型**，让前端的路由名与参数都具备类型安全。它零注解即可工作——直接读取 ThinkPHP 自己的路由规则树。

> 文档语言：简体中文（v1 暂不提供英文版）。机器可读概览见 [`llms.txt`](./llms.txt)。

**语言 / Language:** 简体中文 · English *(planned)*

> **面向 AI 助手 / 编码 Agent：** 本包为 [`route-forge/thinkphp`](https://packagist.org/packages/route-forge/thinkphp)。完整功能规格（框架无关部分）见 [`route-forge/php-laravel`](https://github.com/route-forge/php-laravel/tree/main/.docs) 的 SPEC 与 DESIGN 文档，ThinkPHP 差异见下方[「与 Laravel 版的差异」](#与-laravel-版的差异)。

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

然后把配置文件复制到应用配置目录（ThinkPHP 无 vendor:publish）：

```bash
cp vendor/route-forge/thinkphp/config/forge.php config/forge.php
```

服务经 composer `extra.think.services` 自动发现（think-installer 生成 `vendor/services.php`）。若你的项目未启用自动发现，在 `app/service.php` 手动追加：

```php
return [
    // ...
    \RouteForge\ThinkPHP\ForgeService::class,
];
```

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
```

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

## 配置参考

完整字段与 Laravel 版一致，见包内 `config/forge.php` 注释或 [php-laravel 文档 SPEC §5](https://github.com/route-forge/php-laravel/blob/main/.docs/SPEC.md)。核心项：

| 键                   | 类型           | 默认值             | 说明                                                                 |
|----------------------|----------------|--------------------|----------------------------------------------------------------------|
| `levels`             | `array`        | `[]`               | 层级定义表（description / match / load / endpoint_middleware）        |
| `endpoint_prefix`    | `string`       | `'/_forge/routes'` | 元信息对外端点前缀                                                   |
| `endpoint_middleware`| `string[]`     | `[]`               | 摘要端点中间件                                                       |
| `cache_ttl`          | `int\|null`    | `3600`             | 统一缓存 TTL（秒）；`null` 不缓存，`0` 永久缓存                       |
| `cache_driver`       | `string\|null` | `null`             | think 缓存驱动（`file` / `redis` 等）；`null` 用默认驱动             |
| `strict_mode`        | `bool`         | `false`            | 严格模式：未命中层级抛异常或归入 `unassigned`                        |
| `classifier`         | `callable\|null` | `null`           | 自定义分类回调 `fn(\think\route\RuleItem $r): ?string`               |
| `aliases`            | `array`        | `[]`               | 别名映射表（键=别名，值=真实路由名）                                 |

开发模式（`app_debug=true`，即 `.env` 的 `APP_DEBUG=1`）下自动跳过所有缓存读写，路由变更即时生效。

## 与 Laravel 版的差异

核心业务逻辑（层级解析、别名、仓库、类型生成、缓存）全部在框架无关的 [`route-forge/common`](https://github.com/route-forge/php-laravel) 中，两端行为一致。ThinkPHP 侧的适配差异如实说明：

| 能力 | Laravel 版 | ThinkPHP 版（本包） |
|------|------------|---------------------|
| `->tier()` 校验时机 | 定义期 fail-fast（宏） | **扫描期**（`TierResolver` 抛 `UnknownLevelException`） |
| 命名路由甄别 | `getName()` 即显式命名 | 默认标识=路由地址字符串，需显式 `->name(...)`；name === 地址 时视为未命名 |
| 资源路由 `->tier()` | 生效（写入每条资源路由 action） | 机制上生效，但资源路由无显式命名 → 不进元信息；需要元信息请手写逐条路由 |
| `url_lazy_route` | —（无此机制） | **不支持**：开启后端点扫描/命令 fail-fast 抛异常（延迟解析下规则树不完整） |
| `route:forge:clear` 联动 | 监听 `route:clear` 自动连带清除 | think 无 `route:clear` 命令，无联动 |
| 管理器页面 | `GET /_forge/manager` 可视化面板 | **v1 不含**（规划二期） |
| 命令警告输出 | stderr（`--out` 时 stdout 产物纯净） | think console 无独立 stderr 流，直写 `STDERR`，stdout 产物同样纯净 |
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
