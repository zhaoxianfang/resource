<?php

/**
 * 静态资源类型枚举。
 *
 * 职责：
 *  - 集中定义本包支持的资源类型（字体 / 样式 / 脚本 / 图片）；
 *  - 集中维护"扩展名 → 类型"的映射关系，新增格式只需修改本文件；
 *  - 为 AssetLocator 的类型过滤与遍历跳过逻辑提供统一依据。
 *
 * 设计说明：
 *  - 使用 backed enum（string），便于序列化、日志输出和比较；
 *  - 扩展名一律小写，识别前先 strtolower，避免大小写导致漏识别；
 *  - 未识别的扩展名返回 null，由调用方决定是跳过还是报错。
 */

declare(strict_types=1);

namespace zxf\res;

enum AssetType: string
{
    /** 字体文件（Web 字体与桌面字体） */
    case Font  = 'font';

    /** 样式表 */
    case Css   = 'css';

    /** JavaScript 脚本 */
    case Js    = 'js';

    /** 图片 */
    case Image = 'image';

    /**
     * 根据文件扩展名识别资源类型。
     *
     * @param string $ext 扩展名，可带前导点（如 ".woff2"），也可不带（如 "woff2"）；
     *                    大小写不敏感。
     * @return self|null  识别成功返回对应枚举，未识别返回 null。
     */
    public static function fromExtension(string $ext): ?self
    {
        // 统一去掉前导点并转小写，兼容 ".WOFF2"、".woff2"、"woff2" 等各种写法
        $ext = \strtolower(\ltrim($ext, '.'));

        return match ($ext) {
            // 字体：Web 字体（woff/woff2/eot）+ 桌面字体（ttf/otf/sfnt）
            'woff', 'woff2', 'ttf', 'otf', 'eot', 'sfnt' => self::Font,

            // 样式表
            'css' => self::Css,

            // 脚本：标准 js，加上模块化常用的 mjs（ES Module）和 cjs（CommonJS）
            'js', 'mjs', 'cjs' => self::Js,

            // 图片：覆盖 Web 常用格式 + 图标 + 位图/矢量图常见格式
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif',
            'svg', 'ico', 'bmp', 'tif', 'tiff' => self::Image,

            // 未识别的扩展名
            default => null,
        };
    }

    /**
     * 根据文件路径识别资源类型。
     *
     * 说明：只取路径的最后一段扩展名，目录名中的点不会干扰识别。
     * 例如 "assets/js/jquery.min.js" 取到的是 "js"，而不是 "min.js"。
     *
     * @param string $path 文件路径或文件名。
     * @return self|null   识别成功返回对应枚举，未识别返回 null。
     */
    public static function fromPath(string $path): ?self
    {
        return self::fromExtension(\pathinfo($path, PATHINFO_EXTENSION));
    }
}
