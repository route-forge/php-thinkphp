<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Support;

use RuntimeException;

/**
 * 把 common ConfigFileGenerator 的产物重排为 ThinkPHP 侧 config/forge.php 风格。
 *
 * common 的生成器是框架无关的「值 + 转义」层（全值 var_export，注入面已封），
 * 但它的外壳是 Laravel 风味：`/* |---| *\/` 桶形注释块，以及指向 laravel 仓
 * `.docs/SPEC.md` 的头部说明。本类只动外壳，不重新生成任何值：
 *   - 头部换成 think 侧说明（含「值已固化为字面值、不再经 Env::get」的告知）；
 *   - 桶形注释块压成单行 // 注释，并规范 `'key'` 与 `=>` 之间的填充空格。
 *
 * 为什么不做全文正则替换：层级 description 是用户可控文本，var_export 遇换行会输出
 * 真实换行，巧合或恶意的多行内容可能被当成结构行改写。故这里只匹配「注释块 + 紧随其后
 * 的白名单键行」这一 common 必定产出的形态，且调用方（ManagerConfigStore）在写盘前会把
 * 生成物回读并与输入逐值比对——任何值被改动就放弃写入，不做静默降级。
 */
final class ThinkConfigFileStyler
{
    /**
     * common 生成器的固定键序。出现表外键说明 common 新增了字段，必须同步本类：
     * 宁可报错，也不把未知键留在未转换的桶形注释里。
     */
    private const KNOWN_KEYS = [
        'levels',
        'endpoint_prefix',
        'url_prefix',
        'endpoint_middleware',
        'cache_ttl',
        'cache_driver',
        'strict_mode',
        'scheme_version',
        'classifier',
        'manager_allowed_ips',
        'aliases',
    ];

    /**
     * 替换后的文件头（`return [` 之前的一切由本常量决定，键段由重排产出）。
     */
    private const HEADER = "<?php\n"
        . "// +----------------------------------------------------------------------\n"
        . "// | Route Forge 配置（由管理器页面保存生成）\n"
        . "// +----------------------------------------------------------------------\n"
        . "// | 完整文档见 route-forge 文档站 https://route-forge.github.io/docs/\n"
        . "// |\n"
        . "// | 注意：本页生成的值一律是字面量，不再经 Env::get 读取 .env——\n"
        . "// | 管理器里改了就该立即生效，若仍被 .env 旧值遮蔽会造成「改了没生效」。\n"
        . "// | 需要 .env 驱动的项目，请手工把对应项改回 Env::get('forge.xxx', 默认值)\n"
        . "// | 并补回 use think\\facade\\Env; 声明。\n"
        . "// +----------------------------------------------------------------------\n"
        . "\n"
        . "return [\n";

    /**
     * 「桶形注释块 + 紧随其后的键行」。竖线行与收尾的星号斜杠行均为 4 空格缩进，
     * 与 common 生成器的 heredoc 缩进逐字一致。
     */
    private const BLOCK_PATTERN = '/^    \/\*\n((?:    \|[^\n]*\n)+)    \*\/\n    \'([a-z_]+)\' *=>/m';

    public function style(string $generated): string
    {
        $marker = "return [\n";
        $pos    = strpos($generated, $marker);

        if ($pos === false) {
            throw new RuntimeException('配置生成物缺少 `return [` 头，无法适配 ThinkPHP 风格。');
        }

        return self::HEADER . $this->rewriteBlocks(substr($generated, $pos + strlen($marker)));
    }

    private function rewriteBlocks(string $body): string
    {
        $result = preg_replace_callback(self::BLOCK_PATTERN, function (array $m): string {
            $key = $m[2];

            if (!in_array($key, self::KNOWN_KEYS, true)) {
                throw new RuntimeException(
                    '配置生成物出现未知键 [' . $key . ']，请同步 ThinkConfigFileStyler 的键白名单。'
                );
            }

            return "    // " . $this->title($m[1]) . "\n    '" . $key . "' =>";
        }, $body);

        if ($result === null) {
            throw new RuntimeException('配置生成物风格适配失败：' . preg_last_error_msg());
        }

        return $result;
    }

    /**
     * 取桶形注释块里的标题行（跳过 `|---...` 分隔线）。
     */
    private function title(string $block): string
    {
        foreach (explode("\n", rtrim($block, "\n")) as $line) {
            $text = ltrim(substr(ltrim($line), 1));

            if ($text !== '' && !preg_match('/^-+$/', $text)) {
                return $text;
            }
        }

        return '配置项';
    }
}
