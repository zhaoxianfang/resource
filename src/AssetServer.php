<?php

/**
 * 将包内资源封装为 HTTP 响应。
 *
 * 职责：
 *  - 生成 Content-Type、Cache-Control、ETag 等响应头；
 *  - 提供 If-None-Match 判断与 304 响应头（供各框架适配器复用）；
 *  - 以 readfile() 流式输出，大文件不占内存；
 *  - 提供原生 PHP 直接发送响应的 send()。
 *
 * 性能设计：
 *  - stat() 一次调用同时拿到 size 与 mtime；
 *  - ETag 由 mtime+size 组成，不读文件内容；
 *  - 流式输出，内存占用 O(1)。
 */

declare(strict_types=1);

namespace zxf\res;

final class AssetServer
{
    /** 强缓存时长（秒），一年。 */
    private const MAX_AGE = 31536000;

    /**
     * 准备资源响应元数据。
     *
     * 返回结构：
     *  - path    : 资源绝对路径
     *  - size    : 文件字节数（HEAD 响应可据此设置 Content-Length）
     *  - etag    : ETag 字符串
     *  - headers : HTTP 响应头（不含 Content-Length，由调用方决定是否添加）
     *
     * @return array{path: string, size: int, etag: string, headers: array<string, string>}
     * @throws \InvalidArgumentException 扩展名不受支持时。
     * @throws \RuntimeException         资源无法读取时。
     */
    public static function prepare(string $group, string $relative): array
    {
        $path = AssetLocator::path($group, $relative);

        $ext  = \strtolower(\pathinfo($path, PATHINFO_EXTENSION));
        $mime = AssetType::mimeFor($ext);
        if ($mime === null) {
            throw new \InvalidArgumentException("不支持的资源类型：{$group}/{$relative}");
        }

        // 一次 stat 拿到 size 与 mtime
        $stat = \stat($path);
        if ($stat === false) {
            throw new \RuntimeException("无法读取文件状态：{$group}/{$relative}");
        }
        $size  = (int) $stat['size'];
        $mtime = (int) $stat['mtime'];

        // 基于 mtime + size 生成 ETag，无需读取文件内容
        $etag = \sprintf('"%x-%x"', $mtime, $size);

        $headers = [
            'Content-Type'           => $mime,
            'Cache-Control'          => 'public, max-age=' . self::MAX_AGE . ', immutable',
            'ETag'                   => $etag,
            'Last-Modified'          => \gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return ['path' => $path, 'size' => $size, 'etag' => $etag, 'headers' => $headers];
    }

    /**
     * 判断浏览器带回的 If-None-Match 是否与当前 ETag 匹配。
     * 支持：单值、多值（逗号分隔）、通配符 *、弱 ETag 前缀 W/。
     */
    public static function isNotModified(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '') {
            return false;
        }
        if ($ifNoneMatch === '*') {
            return true;
        }
        foreach (\explode(',', $ifNoneMatch) as $candidate) {
            $candidate = \trim($candidate);
            if (\str_starts_with($candidate, 'W/')) {
                $candidate = \substr($candidate, 2);
            }
            if ($candidate === $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * 返回 304 响应需要的头信息。
     * 304 只能带 ETag 与 Cache-Control，不能带 Content-Type / Content-Length。
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function notModifiedHeaders(array $headers): array
    {
        $result = [];
        if (isset($headers['ETag'])) {
            $result['ETag'] = $headers['ETag'];
        }
        if (isset($headers['Cache-Control'])) {
            $result['Cache-Control'] = $headers['Cache-Control'];
        }
        return $result;
    }

    /**
     * 直接发送 HTTP 响应，用于原生 PHP 或 ThinkPHP。
     *
     * 调用方在本函数返回后应立即 return / exit，
     * 避免框架继续处理响应导致 body 叠加。
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function send(string $group, string $relative): void
    {
        $prepared = self::prepare($group, $relative);

        // 协商缓存
        $ifNoneMatch = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if (self::isNotModified($ifNoneMatch, $prepared['etag'])) {
            if (!\headers_sent()) {
                \http_response_code(304);
                foreach (self::notModifiedHeaders($prepared['headers']) as $name => $value) {
                    \header("{$name}: {$value}");
                }
            }
            return;
        }

        $isHead = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';

        if (!\headers_sent()) {
            \http_response_code(200);
            foreach ($prepared['headers'] as $name => $value) {
                \header("{$name}: {$value}");
            }
            \header('Content-Length: ' . $prepared['size']);
        }

        if ($isHead) {
            return;
        }

        \readfile($prepared['path']);
    }
}
