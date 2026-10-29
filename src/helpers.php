<?php

/**
 * zxf/res 全局助手函数。
 *
 * 本文件不使用命名空间，全部函数位于全局命名空间，
 * 统一使用 xf_res_ 前缀，避免与框架或其他包的助手函数冲突。
 *
 * 加载方式：composer.json 的 autoload.files 机制，
 * Composer 保证单次请求内只加载一次，无需手动 require。
 *
 * 三种加载方式：
 *  - 绝对路径：xf_res_path()
 *  - 相对路径：xf_res_relative()
 *  - 浏览器 URL：xf_res_url()
 *
 * 外部资源注册：
 *  - xf_res_register_group() / xf_res_register_groups() / xf_res_register_root()
 */

declare(strict_types=1);

use zxf\res\AssetLocator;
use zxf\res\AssetServer;
use zxf\res\AssetType;

/**
 * 获取资源的绝对路径。
 *
 * @example xf_res_path('fonts', 'regular.woff2')
 *          => "/path/to/vendor/zxf/res/assets/fonts/regular.woff2"
 *
 * @throws \InvalidArgumentException|\RuntimeException
 */
function xf_res_path(string $group, string $relative): string
{
    return AssetLocator::path($group, $relative);
}

/**
 * 获取资源相对于分组目录的规范化路径（含分组名）。
 * 支持任意深度子目录与外部注册分组。
 *
 * @example xf_res_relative('css', 'demo/test/base.css') => "css/demo/test/base.css"
 * @example xf_res_relative('myicons', 'star.svg')       => "myicons/star.svg"
 *
 * @throws \InvalidArgumentException|\RuntimeException
 */
function xf_res_relative(string $group, string $relative): string
{
    return AssetLocator::relative($group, $relative);
}

/**
 * 判断资源是否存在且可读。不抛异常，适合可选加载。
 */
function xf_res_exists(string $group, string $relative): bool
{
    return AssetLocator::exists($group, $relative);
}

/**
 * 读取资源全部内容。适合 css / js / svg 等小文件。
 *
 * @throws \RuntimeException
 */
function xf_res_read(string $group, string $relative): string
{
    return AssetLocator::read($group, $relative);
}

/**
 * 获取代理路由统一前缀。
 *
 * @example xf_res_route_prefix() => "/zxf/res"
 */
function xf_res_route_prefix(): string
{
    return '/zxf/res';
}

/**
 * 生成资源在浏览器中使用的路径（不含域名）。
 *
 * 基于 AssetLocator::relative() 的规范化结果生成，
 * 天然支持任意深度子目录与外部注册分组。
 *
 * @example xf_res_browser_path('css', 'demo/test/base.css')
 *          => "/zxf/res/css/demo/test/base.css"
 *
 * @throws \InvalidArgumentException|\RuntimeException
 */
function xf_res_browser_path(string $group, string $relative): string
{
    $normalized = AssetLocator::relative($group, $relative);

    return xf_res_route_prefix() . '/' . \implode(
            '/',
            \array_map('rawurlencode', \explode('/', $normalized))
        );
}

/**
 * 生成资源在浏览器中可直接使用的完整 URL。
 *
 * - 传入 $baseUrl 时使用它拼接（Laravel 可传 url('/')）；
 * - CLI 环境（无 HTTP_HOST）返回相对路径；
 * - Web 环境返回完整 URL。
 *
 * @example xf_res_url('css', 'demo/test/base.css')
 *          => "https://example.com/zxf/res/css/demo/test/base.css"
 *
 * @throws \InvalidArgumentException|\RuntimeException
 */
function xf_res_url(string $group, string $relative, string $baseUrl = ''): string
{
    $path = xf_res_browser_path($group, $relative);

    if ($baseUrl !== '') {
        return \rtrim($baseUrl, '/') . $path;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return $path;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . $path;
}

/**
 * 准备资源响应元数据，供框架自建响应对象时使用（如 ThinkPHP 8）。
 *
 * @return array{path: string, size: int, etag: string, headers: array<string, string>}
 * @throws \InvalidArgumentException|\RuntimeException
 */
function xf_res_prepare(string $group, string $relative): array
{
    return AssetServer::prepare($group, $relative);
}

/**
 * 判断 If-None-Match 是否与给定 ETag 匹配。
 * 用于框架自行处理 304 响应的场景。
 */
function xf_res_etag_matches(string $ifNoneMatch, string $etag): bool
{
    return AssetServer::isNotModified($ifNoneMatch, $etag);
}

/**
 * 直接发送资源 HTTP 响应。用于原生 PHP 或 ThinkPHP 闭包路由。
 *
 * 调用方在本函数返回后应立即 return / exit，
 * 避免框架继续输出导致 body 叠加。
 *
 * @throws \InvalidArgumentException|\RuntimeException
 */
function xf_res_serve(string $group, string $relative): void
{
    AssetServer::send($group, $relative);
}

/**
 * 注册外部资源目录为虚拟分组。
 *
 * @example xf_res_register_group('myicons', '/path/to/another/package/assets/icons')
 * @example xf_res_register_group('third_js', base_path('node_modules/some-lib/dist'))
 *
 * @throws \InvalidArgumentException 分组名非法、路径非法、与内置分组冲突时。
 */
function xf_res_register_group(string $group, string $path): void
{
    AssetLocator::registerGroup($group, $path);
}

/**
 * 批量注册外部资源目录为虚拟分组。
 *
 * @param array<string, string> $groups [分组名 => 目录路径]
 * @throws \InvalidArgumentException
 */
function xf_res_register_groups(array $groups): void
{
    AssetLocator::registerGroups($groups);
}

/**
 * 注册一个外部 assets 根目录，自动把一级子目录注册为分组。
 *
 * @param string $assetsPath 外部 assets 根目录。
 * @param string $prefix     可选，给自动识别的分组名加前缀，避免冲突。
 * @throws \InvalidArgumentException|\RuntimeException
 */
function xf_res_register_root(string $assetsPath, string $prefix = ''): void
{
    AssetLocator::registerRoot($assetsPath, $prefix);
}

/**
 * 注销一个外部注册的分组（内置分组不受影响）。
 */
function xf_res_unregister_group(string $group): void
{
    AssetLocator::unregisterGroup($group);
}

/**
 * 清空全部外部注册的分组（内置分组不受影响）。
 */
function xf_res_clear_groups(): void
{
    AssetLocator::clearRegisteredGroups();
}

/**
 * 判断分组是否存在（内置或外部注册）。
 */
function xf_res_has_group(string $group): bool
{
    return AssetLocator::hasGroup($group);
}

/**
 * 返回全部分组名（内置 + 外部注册，已排序）。
 *
 * @return list<string>
 */
function xf_res_groups(): array
{
    return AssetLocator::groups();
}

/**
 * 按类型跨组收集全部资源。
 *
 * @return array<string, string> "组/相对路径" => 绝对路径
 */
function xf_res_all(?AssetType $type = null): array
{
    return \iterator_to_array(AssetLocator::iterateAll($type), true);
}

/** @return array<string, string> 全部字体文件 */
function xf_res_fonts(): array
{
    return xf_res_all(AssetType::Font);
}

/** @return array<string, string> 全部 CSS 文件 */
function xf_res_css(): array
{
    return xf_res_all(AssetType::Css);
}

/** @return array<string, string> 全部 JS 文件 */
function xf_res_js(): array
{
    return xf_res_all(AssetType::Js);
}

/** @return array<string, string> 全部图片文件 */
function xf_res_images(): array
{
    return xf_res_all(AssetType::Image);
}
