<?php

/**
 * Laravel 11+ 服务提供者：自动注册资源代理路由。
 *
 * 路由：GET|HEAD /zxf/res/{group}/{path}
 * path 允许包含斜杠，支持分组内任意深度子目录与外部注册分组。
 *
 * 使用静态方法而非闭包，兼容 route:cache。
 */

declare(strict_types=1);

namespace zxf\res;

use Composer\InstalledVersions;
use Illuminate\Http\Request;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LaravelServiceProvider extends ServiceProvider
{
    /** 路由前缀，与 helpers.php 中 xf_res_route_prefix() 保持一致。 */
    public const ROUTE_PREFIX = '/zxf/res';

    /**
     * 注册路由。
     *
     * - [self::class, 'serve'] 静态调用，route:cache 可序列化；
     * - 显式声明 GET + HEAD，避免 HEAD 请求被 405；
     * - where('path', '.+') 至少一个字符，支持子目录。
     */
    public function boot(): void
    {
        Route::match(['GET', 'HEAD'], self::ROUTE_PREFIX . '/{group}/{path}', [self::class, 'serve'])
            ->where('path', '.+')
            ->name('zxf-res.asset');

        // 把 zxf/res 添加到 about 命令中
        AboutCommand::add('Extend', [
            'zxf/res' => fn () => InstalledVersions::isInstalled('zxf/res')
                ? (InstalledVersions::getPrettyVersion('zxf/res') ?? 'unknown')
                : 'unknown',
        ]);
    }

    /**
     * 处理资源请求。
     *
     * - If-None-Match 匹配：返回 304（仅带 ETag + Cache-Control）；
     * - HEAD 请求：返回普通响应，空 body，显式补 Content-Length；
     * - GET 请求：返回 StreamedResponse，readfile 流式输出。
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function serve(Request $request, string $group, string $path): SymfonyResponse
    {
        $prepared = AssetServer::prepare($group, $path);

        // 归一化 If-None-Match：header() 可能返回 string|array|null
        $ifNoneMatch = $request->header('If-None-Match', '');
        if (\is_array($ifNoneMatch)) {
            $ifNoneMatch = \implode(',', $ifNoneMatch);
        }
        $ifNoneMatch = (string) $ifNoneMatch;

        // 协商缓存
        if (AssetServer::isNotModified($ifNoneMatch, $prepared['etag'])) {
            return new SymfonyResponse('', 304, AssetServer::notModifiedHeaders($prepared['headers']));
        }

        // HEAD 请求：空 body，保留 Content-Length
        if ($request->isMethod('HEAD')) {
            $headers = $prepared['headers'];
            $headers['Content-Length'] = (string) $prepared['size'];
            return new SymfonyResponse('', 200, $headers);
        }

        // GET 请求：流式响应，内存占用 O(1)
        return new StreamedResponse(
            static function () use ($prepared): void {
                \readfile($prepared['path']);
            },
            200,
            $prepared['headers']
        );
    }
}
