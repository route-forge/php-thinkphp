<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Support;

use RuntimeException;

/**
 * 管理器页面渲染器：零依赖的「读模板 + 占位符替换」。
 *
 * 为什么不走 think 视图引擎：think\View 是 Manager 壳，模板驱动在
 * `\think\view\driver\` 命名空间下，需另装 topthink/think-view 才有实现。
 * 而管理器页面本身是完全自包含的单文件 HTML（内联 CSS/JS、零 CDN、零构建产物），
 * 为它给所有使用者强加一个视图引擎依赖不划算——故本包自带模板直出，
 * 与 laravel 版「blade 单文件自包含」的事实等价。
 *
 * 安全：注入数据经 JSON_HEX_TAG 转义（`<` 与 `>` 双向），用户可控内容
 * （层级 description 等）既无法用 `</script>` 提前闭合脚本块，也无法用 `<!--`
 * 伪造脚本内注释吞掉后续代码；版本号走 int 强转，不做字符串替换。
 */
final class ManagerPageRenderer
{
    /**
     * CFG 数据占位符（模板中出现于 `<script>` 顶部）。
     */
    private const PLACEHOLDER_DATA = '__FORGE_CONFIG_JSON__';

    /**
     * scheme_version 占位符（页面右上角徽章）。
     */
    private const PLACEHOLDER_VERSION = '__FORGE_SCHEME_VERSION__';

    private ?string $template = null;

    public function __construct(private readonly string $templatePath)
    {
    }

    /**
     * 包内默认模板绝对路径（src/Support → 包根 resources/manager.html）。
     */
    public static function packageTemplatePath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'manager.html';
    }

    /**
     * 渲染完整页面 HTML。
     *
     * @param array<string,mixed> $data          注入页面的 CFG（tiers / levelsConfig / globalConfig）
     * @param int                 $scheme_version 摘要格式版本号，按整数写入徽章
     */
    public function render(array $data, int $scheme_version): string
    {
        $html = strtr($this->template(), [
            self::PLACEHOLDER_DATA    => $this->encode($data),
            self::PLACEHOLDER_VERSION => (string) $scheme_version,
        ]);

        // fail-fast：仍有残留占位符说明模板被改坏（占位符名不一致、复制丢半截），
        // 宁可报错也不交付一个 JS 里挂着裸标识符的半个页面。
        if (str_contains($html, '__FORGE_')) {
            throw new RuntimeException('管理器页面模板存在未替换的占位符，请检查 resources/manager.html。');
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function encode(array $data): string
    {
        // 只需 HEX_TAG：它同时转义 < 与 >，既封掉 `</script>` 提前闭合，也封掉 `<!--`
        // 伪造脚本内注释吞掉后续代码。不加 HEX_QUOT/APOS/AMP——数据只落在 <script> 文本
        // 节点而非 HTML 属性，多转义只会把页面里的 CFG 变成一串 \u0022。
        // UNESCAPED_SLASHES 不降低安全性（逃逸点是 < 与 >，仍被 HEX_TAG 封住），
        // 否则页面里的 endpoint_prefix 会显示成 \/\_forge\/routes 这种不可读形态。
        $json = json_encode(
            $data,
            JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException('管理器页面数据序列化失败：' . json_last_error_msg());
        }

        return $json;
    }

    private function template(): string
    {
        if ($this->template === null) {
            $content = is_file($this->templatePath) ? @file_get_contents($this->templatePath) : false;

            if ($content === false || $content === '') {
                throw new RuntimeException('管理器页面模板缺失或为空：' . $this->templatePath);
            }

            $this->template = $content;
        }

        return $this->template;
    }
}
