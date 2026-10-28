<?php

/**
 * 静态资源定位器。
 *
 * 职责：
 *  - 在本包 assets/ 目录内，为其他 Composer 包提供资源的"路径解析、内容读取、句柄打开、遍历"能力；
 *  - 保证所有访问都经过安全校验，杜绝路径逃逸（../）和符号链接越界；
 *  - 保证跨平台一致性（Windows / Linux / macOS）。
 *
 * 目录约定：
 *  - assets/ 下的一级子目录名即"分组名"（如 fonts / css / js / images）；
 *  - 分组内支持任意深度的子目录，例如 js/lib/lodash.min.js；
 *  - 文件类型由扩展名识别，集中定义在 AssetType 中。
 *
 * 设计原则：
 *  - 只读：不复制、不发布、不创建符号链接、不在 vendor/ 内写任何文件；
 *  - 缓存：路径解析结果在进程内缓存，重复访问不重复走磁盘；
 *  - 流式：批量遍历使用 Generator，避免把上千文件一次性装入数组；
 *  - 快速失败：一切异常都显式抛出，不存在静默失败。
 */

declare(strict_types=1);

namespace zxf\res;

final class AssetLocator
{
    /**
     * 资源绝对路径缓存。
     * key 形如 "group\0relative"，使用 \0 作为分隔符，避免 group 和 relative 内容互相污染。
     *
     * @var array<string, string>
     */
    private static array $files = [];

    /**
     * 分组目录绝对路径缓存。
     * key 为分组名，value 为该分组经 realpath 规范化后的绝对路径。
     *
     * @var array<string, string>
     */
    private static array $dirs = [];

    /**
     * 分组名列表缓存。
     * 首次调用 groups() 时扫描 assets/ 目录得到，之后进程内复用，避免每次请求都扫盘。
     *
     * @var list<string>|null
     */
    private static ?array $groups = null;

    /**
     * assets/ 根目录经 realpath 规范化后的绝对路径缓存。
     *
     * @var string|null
     */
    private static ?string $root = null;

    /**
     * 返回 assets/ 目录的"逻辑路径"（由 __DIR__ 推导，未做 realpath）。
     *
     * 说明：使用 __DIR__ 推导，天然适配任意 vendor-dir 配置、任意安装位置，
     * 且不受调用方 chdir() 影响。
     */
    public static function root(): string
    {
        return \dirname(__DIR__) . '/assets';
    }

    /**
     * 返回所有分组名（已排序）。
     *
     * 规则：assets/ 下的每个一级子目录即为一个分组；非目录项（散落文件）会被忽略。
     *
     * @return list<string>
     * @throws \RuntimeException 当 assets/ 不存在或不可扫描时抛出。
     */
    public static function groups(): array
    {
        // 进程内缓存：一次请求只扫描一次目录
        if (self::$groups !== null) {
            return self::$groups;
        }

        $root    = self::realRoot();
        $entries = \scandir($root);
        if ($entries === false) {
            throw new \RuntimeException("无法扫描资源根目录：{$root}");
        }

        $groups = [];
        foreach ($entries as $entry) {
            // 跳过特殊目录项
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            // 只把目录当作分组；散落文件不视为分组
            if (\is_dir($root . '/' . $entry)) {
                $groups[] = $entry;
            }
        }

        // 排序保证遍历顺序稳定，便于测试和复现
        \sort($groups);
        return self::$groups = $groups;
    }

    /**
     * 解析某个分组下某个资源的绝对路径。
     *
     * 安全校验流程：
     *  1. 通过 groupDir() 确认分组合法且落在 assets/ 内；
     *  2. 用 realpath() 解析相对路径，得到规范化绝对路径；
     *  3. 用 within() 做规范化前缀校验，拒绝逃逸出分组目录的路径；
     *  4. 用 is_file() + is_readable() 确认是可读的普通文件。
     *
     * @param string $group    分组名（assets/ 下的一级目录名）。
     * @param string $relative 分组内的相对路径，使用正斜杠分隔，支持子目录。
     * @return string          资源文件的绝对路径。
     * @throws \InvalidArgumentException 分组不存在时抛出。
     * @throws \RuntimeException         资源不存在、逃逸、不可读时抛出。
     */
    public static function resolve(string $group, string $relative): string
    {
        // 命中缓存直接返回，避免重复走 realpath 等系统调用
        $key = $group . "\0" . $relative;
        if (isset(self::$files[$key])) {
            return self::$files[$key];
        }

        $base = self::groupDir($group);

        // 将相对路径中的反斜杠统一为正斜杠，ltrim 去掉可能的前导斜杠
        // 说明：realpath 在 Windows 上会自动把 / 转成 \，所以统一用 / 构造是安全的
        $candidate = $base . '/' . \ltrim(\str_replace('\\', '/', $relative), '/');

        $real = \realpath($candidate);
        if ($real === false) {
            // realpath 返回 false 表示路径不存在（文件缺失、路径含非法字符等）
            throw new \RuntimeException("资源不存在：{$group}/{$relative}");
        }

        // 前缀校验：防止 "../" 或符号链接把访问带出分组目录
        if (!self::within($real, $base)) {
            throw new \RuntimeException("资源路径越界：{$group}/{$relative}");
        }

        // 必须是可读的普通文件：排除目录、设备文件、不可读文件
        if (!\is_file($real) || !\is_readable($real)) {
            throw new \RuntimeException("资源不可读：{$group}/{$relative}");
        }

        return self::$files[$key] = $real;
    }

    /**
     * 读取资源文件的全部内容。
     *
     * 适用场景：css、js、svg 等小文件。
     * 对于大字体、大图片，请使用 stream() 以避免一次性占用大量内存。
     *
     * @param string $group    分组名。
     * @param string $relative 分组内相对路径。
     * @return string          文件内容。
     * @throws \RuntimeException 读取失败时抛出。
     */
    public static function read(string $group, string $relative): string
    {
        $content = \file_get_contents(self::resolve($group, $relative));
        if ($content === false) {
            throw new \RuntimeException("读取资源失败：{$group}/{$relative}");
        }
        return $content;
    }

    /**
     * 打开资源文件的只读句柄。
     *
     * 适用场景：大字体、大图片、需要流式复制或分段处理的文件。
     * 调用方必须自行 fclose() 关闭句柄，避免句柄泄漏。
     *
     * @param string $group    分组名。
     * @param string $relative 分组内相对路径。
     * @return resource        以 "rb" 模式打开的只读句柄。
     * @throws \RuntimeException 打开失败时抛出。
     */
    public static function stream(string $group, string $relative)
    {
        // 使用 "rb" 二进制模式，避免 Windows 文本模式把 \r\n 或 \x1A 当作特殊字符处理
        $handle = \fopen(self::resolve($group, $relative), 'rb');
        if ($handle === false) {
            throw new \RuntimeException("打开资源失败：{$group}/{$relative}");
        }
        return $handle;
    }

    /**
     * 遍历某个分组内的资源。
     *
     * 使用 RecursiveIteratorIterator 流式遍历，不会把文件列表一次性读入数组，
     * 即使分组内有数千个文件，内存占用也保持恒定。
     *
     * @param string          $group 分组名。
     * @param AssetType|null  $type  类型过滤；为 null 时不按类型过滤。
     * @return \Generator<string, string> 相对路径 => 绝对路径。
     * @throws \InvalidArgumentException 分组不存在时抛出。
     */
    public static function iterate(string $group, ?AssetType $type = null): \Generator
    {
        $base = self::groupDir($group);

        // SKIP_DOTS 会跳过 "." 和 ".."，避免把它们当作文件处理
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        // 计算子串起点：base 长度 + 1（去掉分隔符）
        $prefixLen = \strlen($base) + 1;

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */

            // 只处理普通文件；目录项由 RecursiveIteratorIterator 自身处理
            if (!$file->isFile()) {
                continue;
            }

            // 从完整路径裁出相对于分组目录的部分，并统一为正斜杠
            // 注意：Windows 下 getPathname() 返回的反斜杠需要归一化，否则 key 不一致
            $relative = \str_replace('\\', '/', \substr($file->getPathname(), $prefixLen));

            // 按扩展名识别类型；未识别扩展名跳过
            $fileType = AssetType::fromPath($relative);
            if ($fileType === null) {
                continue;
            }

            // 类型过滤
            if ($type !== null && $fileType !== $type) {
                continue;
            }

            // 交给 resolve() 做安全校验并返回绝对路径（首次访问会走磁盘，之后走缓存）
            yield $relative => self::resolve($group, $relative);
        }
    }

    /**
     * 跨全部分组遍历资源。
     *
     * @param AssetType|null $type 类型过滤；为 null 时不过滤。
     * @return \Generator<string, string> "组/相对路径" => 绝对路径。
     */
    public static function iterateAll(?AssetType $type = null): \Generator
    {
        foreach (self::groups() as $group) {
            foreach (self::iterate($group, $type) as $relative => $absolute) {
                // 以 "组/相对路径" 作为 key，避免不同分组的同名文件冲突
                yield "{$group}/{$relative}" => $absolute;
            }
        }
    }

    /**
     * 返回 assets/ 根目录的规范化绝对路径。
     *
     * @throws \RuntimeException assets/ 目录不存在时抛出。
     */
    private static function realRoot(): string
    {
        return self::$root ??= (
        \realpath(self::root())
            ?: throw new \RuntimeException('资源根目录不存在：' . self::root())
        );
    }

    /**
     * 返回某个分组目录的规范化绝对路径，同时完成合法性校验。
     *
     * 校验点：
     *  - realpath 必须成功（目录存在）；
     *  - 必须是目录；
     *  - 必须在 assets/ 根目录之内（防止 group 参数本身就是 "../xxx"）。
     *
     * @throws \InvalidArgumentException 分组非法时抛出。
     */
    private static function groupDir(string $group): string
    {
        if (isset(self::$dirs[$group])) {
            return self::$dirs[$group];
        }

        $root = self::realRoot();
        $dir  = \realpath($root . '/' . $group);

        // 三重校验：存在 + 是目录 + 在根目录内
        if ($dir === false || !\is_dir($dir) || !self::within($dir, $root)) {
            throw new \InvalidArgumentException("未知资源分组：{$group}");
        }

        return self::$dirs[$group] = $dir;
    }

    /**
     * 判断 $path 是否落在 $base 目录之内（含子目录）。
     *
     * 实现要点：
     *  - 先把两边的反斜杠统一为正斜杠，保证 Windows / Linux / macOS 比较一致；
     *  - 对 $base 去掉尾部斜杠后再补一个 "/"，防止 "/base-evil" 被误判为 "/base" 内；
     *  - 使用 str_starts_with 做前缀比较（PHP 8 原生，性能优于 strncmp）。
     *
     * 注意：调用本方法前，$path 和 $base 必须已经是 realpath 规范化后的绝对路径，
     * 否则 ".." 和符号链接未被解析，前缀比较结果不可靠。
     */
    private static function within(string $path, string $base): bool
    {
        return \str_starts_with(
            \str_replace('\\', '/', $path),
            \rtrim(\str_replace('\\', '/', $base), '/') . '/'
        );
    }
}
