# zxf/res

一个随 Composer 包分发的静态资源库，用于在多个 Composer 包之间共享**字体、CSS、JS、图片**文件。

**核心特性：**

- 资源随包安装到 `vendor/zxf/res/assets/`，**不发布、不复制到 vendor 之外、不创建符号链接**；
- 全程**只读**，兼容 `--no-dev`、只读挂载、Windows 无管理员权限等场景；
- **跨平台**：Windows / Linux / macOS 行为一致，无路径分隔符和权限差异；
- **安全**：`realpath` + 规范化前缀校验，杜绝 `../` 与符号链接逃逸；
- **高性能**：所有路径解析结果进程内缓存，批量遍历用 `Generator`，不构建中间数组；
- **极简**：仅 2 个源文件，无清单、无构建脚本、无 Composer 插件依赖。

---

## 环境要求

- PHP >= 8.2
- Composer 2

---

## 安装

```bash
composer require zxf/res
```

安装后，资源位于：

```text
vendor/zxf/res/assets/
```

无需执行任何发布、复制或软链命令。

---

## 目录约定

```text
vendor/zxf/res/
├── assets/
│   ├── fonts/              ← 分组：fonts
│   │   ├── regular.woff2
│   │   └── bold.woff2
│   ├── css/                ← 分组：css
│   │   └── base.css
│   ├── js/                 ← 分组：js
│   │   ├── core.js
│   │   └── lib/
│   │       └── lodash.min.js
│   └── images/             ← 分组：images
│       └── logo.png
└── src/
    ├── AssetType.php
    └── AssetLocator.php
```

**约定规则：**

1. `assets/` 下**一级子目录名**即“分组名”（`fonts`、`css`、`js`、`images` 或你自定义的任意名字）；
2. 分组内**支持任意深度的子目录**，例如 `js/lib/lodash.min.js`；
3. 分组名和相对路径中的所有分隔符统一使用正斜杠 `/`；
4. 文件类型由**扩展名**自动识别，未识别的扩展名在遍历时会被自动跳过。

---

## 支持的资源类型

由 `zxf\res\AssetType` 枚举集中管理：

| 类型 | `AssetType` 值               | 支持的扩展名                                                          |
|------|------------------------------|-----------------------------------------------------------------------|
| 字体 | `AssetType::Font`  → `font`  | `woff` `woff2` `ttf` `otf` `eot` `sfnt`                               |
| 样式 | `AssetType::Css`   → `css`   | `css`                                                                 |
| 脚本 | `AssetType::Js`    → `js`    | `js` `mjs` `cjs`                                                      |
| 图片 | `AssetType::Image` → `image` | `png` `jpg` `jpeg` `gif` `webp` `avif` `svg` `ico` `bmp` `tif` `tiff` |

扩展新格式只需修改 `src/AssetType.php` 中的 `match` 分支。

---

## API 一览

命名空间全小写：`zxf\res`。

### `AssetLocator`

| 方法                                                          | 返回值                      | 用途                              |
|---------------------------------------------------------------|-----------------------------|-----------------------------------|
| `root(): string`                                              | `assets/` 逻辑路径          | 获取资源根目录                    |
| `groups(): array`                                             | `list<string>`              | 获取所有分组名（已排序）          |
| `resolve(string $group, string $relative): string`            | 绝对路径                    | 解析单个资源，结果缓存            |
| `read(string $group, string $relative): string`               | 文件内容                    | 读取小文件（css / js / svg）      |
| `stream(string $group, string $relative)`                     | `resource`                  | 打开大文件句柄，由调用方 `fclose` |
| `iterate(string $group, ?AssetType $type = null): \Generator` | `相对路径 => 绝对路径`      | 遍历单个分组，可按类型过滤        |
| `iterateAll(?AssetType $type = null): \Generator`             | `"组/相对路径" => 绝对路径` | 跨组遍历                          |

### `AssetType`

| 方法 / 成员                                    | 说明                                  |
|------------------------------------------------|---------------------------------------|
| `AssetType::Font` / `Css` / `Js` / `Image`     | 四种类型枚举                          |
| `AssetType::fromExtension(string $ext): ?self` | 按扩展名识别类型，未识别返回 `null`   |
| `AssetType::fromPath(string $path): ?self`     | 按文件路径识别类型，未识别返回 `null` |

**异常约定：**

- 分组或资源不存在、路径逃逸 → `InvalidArgumentException` / `RuntimeException`；
- 文件不可读、读取失败 → `RuntimeException`；
- **不存在静默失败**，调用方可以放心捕获异常。

---

## 使用示例

### 1. 解析单个资源路径

```php
use zxf\res\AssetLocator;

$fontPath = AssetLocator::resolve('fonts', 'lishu.ttf');
// => /path/to/vendor/zxf/res/assets/fonts/lishu.ttf

// 直接用于需要文件路径的库，例如 Dompdf、TCPDF
$pdf->setFont($fontPath, 'normal', 12);
```

### 2. 读取小文件内容

```php
use zxf\res\AssetLocator;

$css = AssetLocator::read('css', 'base.css');
$js  = AssetLocator::read('js', 'core.js');

file_put_contents('/tmp/bundle.css', $css . "\n" . $js);
```

### 3. 处理大字体 / 大图片

```php
use zxf\res\AssetLocator;

$handle = AssetLocator::stream('fonts', 'lishu.ttf');
try {
    // 流式读取，不会把整个字体读入内存
    while (!feof($handle)) {
        fwrite($target, fread($handle, 8192));
    }
} finally {
    fclose($handle);
}
```

### 4. 遍历某个分组

```php
use zxf\res\AssetLocator;

foreach (AssetLocator::iterate('js') as $relative => $absolute) {
    echo "JS: {$relative} => {$absolute}\n";
}
// 输出：
// JS: core.js => /path/to/assets/js/core.js
// JS: lib/lodash.min.js => /path/to/assets/js/lib/lodash.min.js
```

### 5. 按类型过滤

```php
use zxf\res\AssetLocator;
use zxf\res\AssetType;

// 只遍历 images 分组下的图片
foreach (AssetLocator::iterate('images', AssetType::Image) as $relative => $absolute) {
    // ...
}

// 跨所有分组，只收集字体
$fonts = [];
foreach (AssetLocator::iterateAll(AssetType::Font) as $key => $absolute) {
    $fonts[$key] = $absolute;
}
// $fonts = ['fonts/regular.woff2' => '...', 'fonts/bold.woff2' => '...']
```

### 6. 生成 CSS `@font-face` 片段

```php
use zxf\res\AssetLocator;
use zxf\res\AssetType;

$rules = '';
foreach (AssetLocator::iterateAll(AssetType::Font) as $key => $absolute) {
    $name = pathinfo($key, PATHINFO_FILENAME);
    $url  = 'file://' . $absolute;
    $rules .= "@font-face { font-family: '{$name}'; src: url('{$url}'); }\n";
}
```

### 7. 复制到指定目录（调用方自己控制，本包不代劳）

```php
use zxf\res\AssetLocator;

$dest = __DIR__ . '/public/assets';

foreach (AssetLocator::iterateAll() as $key => $absolute) {
    $target = $dest . '/' . $key;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0755, true);
    }
    copy($absolute, $target);
}
```

> 本包只负责**定位与读取**，是否复制、复制到哪里、如何暴露给浏览器，完全由调用方决定。

---

## 不依赖本包 API 的使用方式

如果只想拿一两个固定文件、不想引入 `AssetLocator`，可以直接用 Composer 2 内置的 `InstalledVersions`：

```php
use Composer\InstalledVersions;

$root = InstalledVersions::getInstallPath('zxf/res');
$path = $root . '/assets/fonts/regular.woff2';
```

> **注意**：这种方式**绕过了安全校验**，只能用于**代码中写死的固定路径**，**绝不要**把外部输入拼接进去。

---

## 安全性

所有资源访问都经过以下校验：

1. **分组校验**：`group` 参数通过 `realpath` 解析后必须落在 `assets/` 内；
2. **路径校验**：资源路径通过 `realpath` 解析后必须落在分组目录内；
3. **前缀校验**：使用规范化后的字符串前缀比较（带 `/` 结尾），防止 `/base-evil` 绕过 `/base`；
4. **文件校验**：必须是 `is_file()` 且 `is_readable()` 的普通可读文件。

因此以下输入会直接抛异常：

```php
AssetLocator::resolve('fonts', '../../../etc/passwd');   // 逃逸 → RuntimeException
AssetLocator::resolve('fonts', '/etc/passwd');           // 绝对路径被 ltrim 后仍逃逸 → RuntimeException
AssetLocator::resolve('../../etc', 'passwd');            // 分组逃逸 → InvalidArgumentException
```

---

## 跨平台说明

| 风险点                 | 处理方式                                                            |
|------------------------|---------------------------------------------------------------------|
| 路径分隔符             | 统一使用正斜杠 `/`；`realpath` 结果为平台原生格式，比较前统一规范化 |
| Windows 无符号链接权限 | 本包**不使用** `symlink` / `junction` / `copy`，天然无权限问题      |
| `vendor/` 只读挂载     | 全程只读，无任何写操作                                              |
| OPcache                | 静态文件、无运行时写缓存，进程内缓存只在单次请求生命周期内存在      |
| `chdir()` 影响         | 所有路径基于 `__DIR__` 推导，不受当前工作目录影响                   |
| 自定义 `vendor-dir`    | 包内使用 `__DIR__`，自动适配任意 `vendor-dir` 配置                  |

---

## 常见问题

**Q：为什么不把资源发布到 `public/`？**

A：因为发布通常需要 `symlink`（Windows 权限问题）、`copy`（重复存储、更新不同步）或专用插件（额外依赖）。本包的设计目标是**让 PHP 代码在运行时能定位到资源**，至于是否复制到 `public/`、如何让浏览器访问，应由调用方按自己的部署策略决定（见「使用示例 7」）。

**Q：为什么没有 `asset-manifest.json`？**

A：清单的价值在于"白名单"和"构建期报错"。如果你的诉求只是"共享 resources 目录"，`RecursiveDirectoryIterator` 本身就是惰性流式迭代，性能与清单持平，且无需维护同步问题。只有在需要**精确控制哪些文件对外可见**时才值得引入清单。

**Q：可以新增自定义分组吗？**

A：可以。`assets/` 下任意一级子目录都是合法分组，无需注册、无需改代码。例如新增 `assets/pdf/`，直接调用 `AssetLocator::resolve('pdf', 'template.html')` 即可。

**Q：可以新增自定义扩展名吗？**

A：可以。在 `src/AssetType.php` 的 `fromExtension()` 的 `match` 分支中追加扩展名即可。未识别的扩展名在 `iterate()` 中会被跳过，但 `resolve()` / `read()` / `stream()` 仍可直接访问。

---

## 许可证

MIT
