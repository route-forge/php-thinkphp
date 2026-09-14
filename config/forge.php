<?php
// +----------------------------------------------------------------------
// | Route Forge 配置
// +----------------------------------------------------------------------
// | ThinkPHP 无 vendor:publish。安装后运行 `php think route:forge:publish`
// | 即可把本文件复制到应用 config/forge.php（默认不覆盖已有文件；--force 覆盖并自动备份），
// | 再按项目实际层级划分调整。字段语义与 route-forge/laravel 版一致，
// | 完整文档见 route-forge 文档站 https://route-forge.github.io/docs/
// +----------------------------------------------------------------------

use think\facade\Env;

return [
    // 层级定义表：键为层级名（自定义），值为匹配规则与加载策略。
    // 注意：'unassigned' 为保留层级名（未命中任何层级的兜底），勿用作自定义层级。
    'levels' => [
        'public' => [
            'description' => '公共接口（无需登录）',
            'match'       => [
                'prefix'     => ['auth', 'public'],
                'middleware' => [],
            ],
            'load'        => 'eager',
        ],
        'client' => [
            'description' => '客户端用户接口',
            'match'       => [
                'prefix'     => ['client'],
                'middleware' => [],
            ],
            'load'        => 'lazy',
        ],
        'manage' => [
            'description' => '运营管理接口',
            'match'       => [
                'prefix'     => ['manage'],
                'middleware' => [],
                // 'middleware_match' => 'any',  // 'any'（默认，OR）/ 'all'（AND）/ DNF 数组
            ],
            'load'        => 'lazy',
            // 'endpoint_middleware' => ['auth'],  // 访问该层级元信息端点的中间件；数组或单个字符串（'auth'）都接受
        ],
    ],

    // 路由元信息对外端点前缀（层级端点 GET /{prefix}/{level} 与摘要端点 GET /{prefix}）
    'endpoint_prefix' => Env::get('forge.endpoint_prefix', '/_forge/routes'),

    // 应用的路由前缀，经摘要端点 config.url_prefix 下发；完整 URL 或路径前缀，null 不下发
    'url_prefix' => null,

    // 摘要端点中间件；数组或单个字符串都接受，空数组 / null 不限制
    'endpoint_middleware' => [],

    // 统一缓存 TTL（秒）；null 不缓存，0 永久缓存，负值视为 null
    'cache_ttl' => Env::get('forge.cache_ttl', 3600),

    // 缓存驱动；null 用默认驱动，可指定 'file' / 'redis' 等
    'cache_driver' => null,

    // 严格模式：true 时把「命名路由未归级 / 有层级归属却无路由名」一次性聚合报告
    //（HTTP 端点 500 + RF_BE_009；route:forge:list / types 退出码 1、只报问题、types 不再产出 d.ts）；
    // false 时未归级的命名路由归入 unassigned 特殊层级，命令与产物不受影响。
    'strict_mode' => false,

    // 摘要端点响应格式版本号（schemeVersion 字段）
    'scheme_version' => 1,

    // 自定义分类回调，签名 fn(\think\route\RuleItem $r): ?string；优先级介于
    // 显式 ->tier()（含 group 透传）与配置 match 之间
    'classifier' => null,

    // 管理器页面（/_forge/manager）允许访问的来源 IP。管理器路由仅在 app_debug=true 时
    // 注册，本项是第二层防护：键缺失按 ['127.0.0.1', '::1'] 处理（仅本机；localhost
    // 可能解析为 IPv6 的 ::1，故一并放行）；列表含 '*' 放行任意来源；null 或空数组 =
    // 不做 IP 限制（局域网暴露需自担风险）。单个字符串等价于只含它的数组。
    'manager_allowed_ips' => ['127.0.0.1', '::1'],

    // 路由别名映射表：键=别名（旧路由名），值=真实路由名。
    // 与路由链式 ->forgeAlias('旧名') 并用时宏优先；悬空别名抛 AliasTargetException。
    'aliases' => [],
];
