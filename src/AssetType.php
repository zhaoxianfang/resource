<?php

/**
 * 静态资源类型枚举。
 *
 * 职责：
 *  - 集中维护「扩展名 => 资源类型」映射；
 *  - 集中维护「扩展名 => MIME」映射，供 HTTP 响应用；
 *  - 新增格式时只修改本文件。
 *
 * 不依赖任何外部包，仅使用 PHP 内置函数。
 */

declare(strict_types=1);

namespace zxf\res;

enum AssetType: string
{
    /** 字体文件 */
    case Font  = 'font';

    /** 样式表 */
    case Css   = 'css';

    /** JavaScript 脚本 */
    case Js    = 'js';

    /** 图片 */
    case Image = 'image';

    /**
     * 扩展名 => MIME 类型映射。
     * 使用类常量，由 OPcache 静态缓存，零运行时开销。
     *
     * @var array<string, string>
     */
    private const MIME = [
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'sfnt'  => 'font/sfnt',
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'mjs'   => 'application/javascript; charset=utf-8',
        'cjs'   => 'application/javascript; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'bmp'   => 'image/bmp',
        'tif'   => 'image/tiff',
        'tiff'  => 'image/tiff',
    ];

    /**
     * 根据扩展名识别资源类型。
     *
     * @param string $ext 扩展名，可带或不带前导点，大小写不敏感。
     * @return self|null  识别成功返回对应枚举，未识别返回 null。
     */
    public static function fromExtension(string $ext): ?self
    {
        return match (\strtolower(\ltrim($ext, '.'))) {
            'woff', 'woff2', 'ttf', 'otf', 'eot', 'sfnt' => self::Font,
            'css'                                         => self::Css,
            'js', 'mjs', 'cjs'                            => self::Js,
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif',
            'svg', 'ico', 'bmp', 'tif', 'tiff'            => self::Image,
            default                                       => null,
        };
    }

    /**
     * 根据文件路径识别资源类型。
     * 只取最后一段扩展名，目录名中的点不会干扰识别。
     *
     * @param string $path 文件路径或文件名。
     * @return self|null
     */
    public static function fromPath(string $path): ?self
    {
        return self::fromExtension(\pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * 返回扩展名对应的 MIME 类型；未识别返回 null。
     *
     * @param string $ext 扩展名，可带或不带前导点，大小写不敏感。
     * @return string|null
     */
    public static function mimeFor(string $ext): ?string
    {
        return self::MIME[\strtolower(\ltrim($ext, '.'))] ?? null;
    }
}
