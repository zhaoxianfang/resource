<?php

/**
 * 静态资源定位器。
 *
 * 职责：
 *  - 安全解析资源绝对路径（realpath + 前缀校验）；
 *  - 返回资源相对于分组目录的规范化路径；
 *  - 判断存在性、读取内容、打开句柄；
 *  - 流式遍历资源，避免大目录占用内存；
 *  - 支持注册外部资源目录，扩展可用资源范围。
 *
 * 约定：
 *  - 本包 assets/ 下一级子目录名即内置分组名；
 *  - 开发者可注册外部目录为「虚拟分组」，访问方式与内置分组完全一致；
 *  - 分组内支持任意深度子目录，如 css/demo/test/base.css；
 *  - 全程只读，不复制、不发布、不创建符号链接。
 *
 * 性能设计：
 *  - 绝对路径、分组目录、分组列表、规范化前缀均进程内缓存；
 *  - 前缀校验使用 str_starts_with（原生 C 实现）；
 *  - 遍历使用 Generator，内存占用与文件数无关。
 */

declare(strict_types=1);

namespace zxf\res;

final class AssetLocator
{
    /** @var array<string, array<string, string>> [分组][相对路径] = 绝对路径 */
    private static array $files = [];

    /** @var array<string, string> [分组] = 分组目录规范化绝对路径 */
    private static array $dirs = [];

    /** @var array<string, string> [分组] = 分组目录规范化前缀（以 / 结尾） */
    private static array $prefixes = [];

    /** @var array<string, string> [分组] = 外部注册的分组目录绝对路径 */
    private static array $external = [];

    /** @var list<string>|null 内置 + 外部分组名合并列表 */
    private static ?array $groups = null;

    /** @var string|null assets/ 根目录规范化绝对路径 */
    private static ?string $root = null;

    /** @var string|null assets/ 根目录规范化前缀（以 / 结尾） */
    private static ?string $rootPrefix = null;

    /**
     * assets/ 目录的逻辑路径。
     * 由 __DIR__ 推导，不受 chdir、vendor-dir 配置、安装位置影响。
     */
    public static function root(): string
    {
        return \dirname(__DIR__) . '/assets';
    }

    /**
     * 返回全部分组名（已排序），包含内置分组与外部注册的分组。
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }

        $groups = [];

        // 内置分组：扫描本包 assets/
        $root = \realpath(self::root());
        if ($root !== false && ($entries = \scandir($root)) !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..' && \is_dir($root . '/' . $entry)) {
                    $groups[] = $entry;
                }
            }
        }

        // 外部分组：追加注册的目录
        foreach (\array_keys(self::$external) as $group) {
            if (!\in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        \sort($groups);
        return self::$groups = $groups;
    }

    /**
     * 判断分组是否存在（内置或外部注册）。
     */
    public static function hasGroup(string $group): bool
    {
        return \in_array($group, self::groups(), true);
    }

    /**
     * 解析资源绝对路径。
     *
     * 校验流程：
     *  1. groupDir() 确认分组存在且合法；
     *  2. realpath() 解析相对路径（自动处理 //、./、../）；
     *  3. 规范化前缀校验，拒绝 ../ 与符号链接逃逸；
     *  4. is_file() + is_readable() 确认为可读普通文件。
     *
     * @param string $group    分组名。
     * @param string $relative 分组内相对路径，正斜杠分隔，支持任意深度子目录。
     * @return string          资源绝对路径。
     * @throws \InvalidArgumentException 分组非法时。
     * @throws \RuntimeException         资源不存在、越界或不可读时。
     */
    public static function path(string $group, string $relative): string
    {
        if (isset(self::$files[$group][$relative])) {
            return self::$files[$group][$relative];
        }

        $base = self::groupDir($group);
        $real = \realpath($base . '/' . \ltrim(\str_replace('\\', '/', $relative), '/'));

        if ($real === false) {
            throw new \RuntimeException("资源不存在：{$group}/{$relative}");
        }
        if (!\str_starts_with(\str_replace('\\', '/', $real), self::$prefixes[$group])) {
            throw new \RuntimeException("资源路径越界：{$group}/{$relative}");
        }
        if (!\is_file($real) || !\is_readable($real)) {
            throw new \RuntimeException("资源不可读：{$group}/{$relative}");
        }

        return self::$files[$group][$relative] = $real;
    }

    /**
     * 返回资源相对于分组目录的规范化路径（含分组名）。
     *
     * 内置分组与外部注册分组规则一致：
     *  - relative('fonts',    'regular.woff2') => "fonts/regular.woff2"
     *  - relative('myicons',  'star.svg')      => "myicons/star.svg"
     *
     * 结果基于 realpath 反推，自动归一化 //、./、../。
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function relative(string $group, string $relative): string
    {
        $absolute = self::path($group, $relative);
        $base     = \rtrim(\str_replace('\\', '/', self::groupDir($group)), '/') . '/';
        $rel      = \substr(\str_replace('\\', '/', $absolute), \strlen($base));

        return $group . '/' . $rel;
    }

    /**
     * 判断资源是否存在且可读。不抛异常，便于可选加载。
     */
    public static function exists(string $group, string $relative): bool
    {
        try {
            self::path($group, $relative);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 读取资源全部内容。适合 css / js / svg 等小文件；
     * 大字体、大图片请使用 stream()。
     *
     * @throws \RuntimeException 读取失败时。
     */
    public static function read(string $group, string $relative): string
    {
        $content = \file_get_contents(self::path($group, $relative));
        if ($content === false) {
            throw new \RuntimeException("读取资源失败：{$group}/{$relative}");
        }
        return $content;
    }

    /**
     * 以只读二进制句柄打开资源。调用方必须自行 fclose。
     *
     * @return resource
     * @throws \RuntimeException 打开失败时。
     */
    public static function stream(string $group, string $relative)
    {
        $handle = \fopen(self::path($group, $relative), 'rb');
        if ($handle === false) {
            throw new \RuntimeException("打开资源失败：{$group}/{$relative}");
        }
        return $handle;
    }

    /**
     * 遍历某个分组内的资源。
     *
     * 若某个符号链接指向分组外部，path() 会拒绝；
     * 此处通过 try-catch 跳过单个文件，不中断整个遍历。
     *
     * @param string         $group 分组名（内置或外部注册）。
     * @param AssetType|null $type  类型过滤；null 表示不过滤。
     * @return \Generator<string, string> 相对路径 => 绝对路径
     * @throws \InvalidArgumentException 分组不存在时。
     */
    public static function iterate(string $group, ?AssetType $type = null): \Generator
    {
        $base      = self::groupDir($group);
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        $prefixLen = \strlen($base) + 1;

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $relative = \str_replace('\\', '/', \substr($file->getPathname(), $prefixLen));
            $fileType = AssetType::fromPath($relative);

            if ($fileType === null || ($type !== null && $fileType !== $type)) {
                continue;
            }

            try {
                $absolute = self::path($group, $relative);
            } catch (\RuntimeException) {
                continue;
            }

            yield (string) $relative => $absolute;
        }
    }

    /**
     * 跨全部分组遍历资源（含外部注册）。
     *
     * @param AssetType|null $type 类型过滤；null 表示不过滤。
     * @return \Generator<string, string> "组/相对路径" => 绝对路径
     */
    public static function iterateAll(?AssetType $type = null): \Generator
    {
        foreach (self::groups() as $group) {
            foreach (self::iterate($group, $type) as $relative => $absolute) {
                yield (string) "{$group}/{$relative}" => $absolute;
            }
        }
    }

    /**
     * 注册一个外部资源目录为虚拟分组。
     *
     * 分组名与内置分组冲突时抛异常；重复注册同一分组会覆盖旧路径。
     * 注册后可通过 path() / relative() / iterate() / xf_res_url() 等
     * 与内置分组完全相同的方式访问。
     *
     * @param string $group 分组名，只允许字母、数字、下划线、连字符，且以字母或下划线开头。
     * @param string $path  外部资源目录绝对路径或相对当前工作目录的路径。
     * @throws \InvalidArgumentException 分组名非法、路径非法、与内置分组冲突时。
     */
    public static function registerGroup(string $group, string $path): void
    {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $group)) {
            throw new \InvalidArgumentException(
                "非法分组名：{$group}（只允许字母、数字、下划线、连字符，且以字母或下划线开头）"
            );
        }

        $real = \realpath($path);
        if ($real === false || !\is_dir($real)) {
            throw new \InvalidArgumentException("外部资源路径不存在或不是目录：{$path}");
        }
        if (!\is_readable($real)) {
            throw new \InvalidArgumentException("外部资源路径不可读：{$path}");
        }

        // 内置分组冲突检测
        $root = \realpath(self::root());
        if ($root !== false && \is_dir($root . '/' . $group)) {
            throw new \InvalidArgumentException("分组名与内置资源冲突：{$group}");
        }

        self::$external[$group] = $real;

        // 失效该分组的缓存与汇总缓存
        self::$groups = null;
        unset(self::$dirs[$group], self::$prefixes[$group], self::$files[$group]);
    }

    /**
     * 批量注册外部资源目录为虚拟分组。
     *
     * @param array<string, string> $groups [分组名 => 目录路径]
     * @throws \InvalidArgumentException 任一分组或路径非法时。
     */
    public static function registerGroups(array $groups): void
    {
        foreach ($groups as $group => $path) {
            self::registerGroup((string) $group, (string) $path);
        }
    }

    /**
     * 注册一个外部 assets 根目录，自动把其中的一级子目录注册为分组。
     *
     * @param string $assetsPath 外部 assets 根目录。
     * @param string $prefix     可选，给所有自动识别的分组名加前缀，避免与内置分组冲突。
     *                           例如 prefix='ext' 时，子目录 icons 会注册为 'ext_icons'。
     * @throws \InvalidArgumentException 根目录非法时。
     * @throws \RuntimeException         无法扫描根目录时。
     */
    public static function registerRoot(string $assetsPath, string $prefix = ''): void
    {
        $real = \realpath($assetsPath);
        if ($real === false || !\is_dir($real)) {
            throw new \InvalidArgumentException("外部资源根目录不存在或不是目录：{$assetsPath}");
        }

        $entries = \scandir($real);
        if ($entries === false) {
            throw new \RuntimeException("无法扫描外部资源根目录：{$assetsPath}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $sub = $real . '/' . $entry;
            if (!\is_dir($sub)) {
                continue;
            }
            $groupName = $prefix === '' ? $entry : $prefix . '_' . $entry;
            self::registerGroup($groupName, $sub);
        }
    }

    /**
     * 注销一个外部注册的分组。
     * 若该分组是内置分组则不做任何处理。
     */
    public static function unregisterGroup(string $group): void
    {
        if (!isset(self::$external[$group])) {
            return;
        }
        unset(
            self::$external[$group],
            self::$dirs[$group],
            self::$prefixes[$group],
            self::$files[$group]
        );
        self::$groups = null;
    }

    /**
     * 清空全部外部注册的分组。
     */
    public static function clearRegisteredGroups(): void
    {
        foreach (\array_keys(self::$external) as $group) {
            unset(self::$dirs[$group], self::$prefixes[$group], self::$files[$group]);
        }
        self::$external = [];
        self::$groups   = null;
    }

    /**
     * 返回 assets/ 根目录规范化绝对路径。
     *
     * @throws \RuntimeException assets/ 不存在时。
     */
    private static function realRoot(): string
    {
        return self::$root ??= (
        \realpath(self::root())
            ?: throw new \RuntimeException('资源根目录不存在：' . self::root())
        );
    }

    /**
     * 返回 assets/ 根目录规范化前缀（以 / 结尾）。
     */
    private static function rootPrefix(): string
    {
        return self::$rootPrefix ??= \rtrim(\str_replace('\\', '/', self::realRoot()), '/') . '/';
    }

    /**
     * 返回分组目录规范化绝对路径，并缓存其规范化前缀。
     *
     * 优先级：外部注册 > 内置。注册时已拒绝与内置冲突，
     * 因此正常情况下两者不会重叠；此处外部优先仅为防御性编码。
     *
     * @throws \InvalidArgumentException 分组非法时。
     */
    private static function groupDir(string $group): string
    {
        if (isset(self::$dirs[$group])) {
            return self::$dirs[$group];
        }

        // 1) 外部注册分组
        if (isset(self::$external[$group])) {
            $dir = self::$external[$group];
            self::$prefixes[$group] = \rtrim(\str_replace('\\', '/', $dir), '/') . '/';
            return self::$dirs[$group] = $dir;
        }

        // 2) 内置分组
        $root = self::realRoot();
        $dir  = \realpath($root . '/' . $group);
        if ($dir === false || !\is_dir($dir)) {
            throw new \InvalidArgumentException("未知资源分组：{$group}");
        }

        $dirPrefix = \rtrim(\str_replace('\\', '/', $dir), '/') . '/';
        if (!\str_starts_with($dirPrefix, self::rootPrefix())) {
            throw new \InvalidArgumentException("未知资源分组：{$group}");
        }

        self::$prefixes[$group] = $dirPrefix;
        return self::$dirs[$group] = $dir;
    }
}
