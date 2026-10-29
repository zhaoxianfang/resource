# zxf/res

随 Composer 包分发的静态资源库，支持在**绝对路径**、**相对路径**、**浏览器 URL** 三种维度下加载字体、CSS、JS、图片，并支持注册外部资源包目录。

- 资源始终位于 `vendor/zxf/res/assets/`，**不发布、不复制、不软链**；
- 全程只读，兼容 `--no-dev`、只读挂载、Windows 无管理员权限；
- 支持分组内任意深度子目录：`xf_res_url('css', 'demo/test/base.css')`；
- 支持开发者注册外部资源目录为虚拟分组；
- 兼容 Laravel 11+ / ThinkPHP 8+ / 原生 PHP 8.2+；
- 路由前缀统一为 `/zxf/res`；助手函数统一使用 `xf_res_` 前缀。

---

## 环境要求

- PHP >= 8.2
- Laravel >= 11 或 ThinkPHP >= 8 或原生 PHP 8.2+

## 安装

```bash
composer require zxf/res
```

安装后资源位于 `vendor/zxf/res/assets/`，无需任何发布或复制命令。

## 目录约定

```text
vendor/zxf/res/assets/
├── fonts/regular.woff2
├── css/
│   ├── base.css
│   └── demo/test/base.css
├── js/
│   ├── core.js
│   └── lib/lodash.min.js
└── images/logo.png
```

- `assets/` 下**一级子目录名即分组名**；
- 分组内**支持任意深度子目录**；
- 扩展名决定类型：`woff/woff2/ttf/otf/eot/sfnt`（字体）、`css`、`js/mjs/cjs`、`png/jpg/jpeg/gif/webp/avif/svg/ico/bmp/tif/tiff`（图片）。

---

## 三种加载方式

| 方式 | 助手函数 | 返回值示例 | 适用场景 |
|---|---|---|---|
| 绝对路径 | `xf_res_path('fonts', 'regular.woff2')` | `/path/to/vendor/zxf/res/assets/fonts/regular.woff2` | PDF 引擎、图片处理 |
| 相对路径 | `xf_res_relative('css', 'demo/test/base.css')` | `css/demo/test/base.css` | 日志、调试 |
| 浏览器 URL | `xf_res_url('css', 'demo/test/base.css')` | `https://host/zxf/res/css/demo/test/base.css` | `<link>`、`<script>`、`<img>` |

---

## 外部资源注册

支持把**其他 Composer 包**或**任意目录**中的资源注册为本包的虚拟分组，
之后可像访问内置资源一样访问它们（路径、URL、HTTP 代理完全一致）。

### 注册单个外部分组

```php
xf_res_register_group('myicons', '/path/to/another/package/assets/icons');

// 之后即可：
xf_res_path('myicons', 'star.svg');        // 绝对路径
xf_res_relative('myicons', 'star.svg');    // "myicons/star.svg"
xf_res_url('myicons', 'star.svg');         // https://host/zxf/res/myicons/star.svg
```

### 批量注册

```php
xf_res_register_groups([
    'myicons' => '/path/to/icons',
    'myfonts' => '/path/to/fonts',
]);
```

### 注册整个外部 assets 根目录

自动把一级子目录注册为分组：

```php
xf_res_register_root('/path/to/another/package/assets');
// 该目录下的 icons、fonts、js 等一级目录会自动注册为分组
```

若担心与内置分组冲突，可加前缀：

```php
xf_res_register_root('/path/to/another/package/assets', 'ext');
// 自动注册为 ext_icons、ext_fonts、ext_js ...
```

### 注销与清空

```php
xf_res_unregister_group('myicons');   // 注销单个
xf_res_clear_groups();                // 清空全部外部注册（内置不受影响）
xf_res_has_group('myicons');          // 判断分组是否存在
xf_res_groups();                      // 获取全部分组名（含外部）
```

### 注册约束

- 分组名只允许字母、数字、下划线、连字符，且以字母或下划线开头；
- 外部目录必须是真实存在的可读目录；
- **不允许覆盖内置分组**（`fonts`、`css`、`js`、`images` 等），冲突时抛 `InvalidArgumentException`；
- 外部资源同样经过 `realpath` + 前缀校验，无路径逃逸风险。

---

## Laravel 11+ 接入

### 1. 自动注册路由

`composer.json` 已声明 `extra.laravel.providers`，**安装即生效**，无需手动注册服务提供者。

使用 `route:cache` 时执行一次：

```bash
php artisan route:cache
```

### 2. Blade 中使用

```blade
{{-- 内置资源 --}}
<link rel="stylesheet" href="{{ xf_res_url('css', 'base.css') }}">
<script src="{{ xf_res_url('js', 'lib/lodash.min.js') }}"></script>
<img src="{{ xf_res_url('images', 'logo.png') }}" alt="logo">

{{-- 外部注册资源 --}}
<link rel="stylesheet" href="{{ xf_res_url('myicons', 'icon.css') }}">

<style>
@font-face {
    font-family: 'Regular';
    src: url('{{ xf_res_url('fonts', 'regular.woff2') }}') format('woff2');
}
</style>
```

### 3. 在 Laravel 中注册外部资源

在 `app/Providers/AppServiceProvider.php` 的 `boot()` 中注册一次：

```php
use zxf\res\AssetLocator;

public function boot(): void
{
    // 方式一：注册单个外部目录
    AssetLocator::registerGroup('myicons', base_path('node_modules/my-icons/dist'));

    // 方式二：批量
    AssetLocator::registerGroups([
        'mycss' => '/path/to/another/package/assets/css',
        'myjs'  => '/path/to/another/package/assets/js',
    ]);

    // 方式三：注册整个外部 assets 根目录（自动识别一级子目录）
    AssetLocator::registerRoot('/path/to/another/package/assets');
}
```

### 4. 在 Laravel 中使用绝对路径

```php
$fontPath = xf_res_path('fonts', 'regular.woff2');

// 交给 PDF 引擎（Dompdf、TCPDF 等）
$pdf->registerFont($fontPath);
```

---

## ThinkPHP 8+ 接入

### 1. 注册路由

在 `route/app.php` 中注册：

```php
use think\facade\Route;

Route::get('zxf/res/<group>/<path>', function (string $group, string $path) {
    xf_res_serve($group, $path);
    exit; // 阻止 TP8 继续输出
})->pattern(['path' => '.+'])->name('zxf-res.asset');
```

### 2. 注册外部资源

在 `app/event.php` 的 `AppInit` 事件监听中，或 `app/common.php` 中：

```php
use zxf\res\AssetLocator;

AssetLocator::registerGroup('myicons', root_path('node_modules/my-icons/dist'));
```

### 3. 模板中使用

```php
<link rel="stylesheet" href="{:xf_res_url('css', 'demo/test/base.css')}">
<script src="{:xf_res_url('js', 'lib/lodash.min.js')}"></script>
<img src="{:xf_res_url('images', 'logo.png')}">
```

---

## 原生 PHP 8.2+ 接入

### 1. 入口文件

```php
<?php
require __DIR__ . '/vendor/autoload.php';

// 可选：注册外部资源
xf_res_register_group('myicons', __DIR__ . '/node_modules/my-icons/dist');

// 拦截资源请求
$prefix = xf_res_route_prefix(); // "/zxf/res"
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($uri, $prefix . '/')) {
    $rest = rawurldecode(substr($uri, strlen($prefix) + 1));
    [$group, $path] = array_pad(explode('/', $rest, 2), 2, '');

    xf_res_serve($group, $path);
    exit;
}

// 其他路由
echo 'Hello';
```

### 2. HTML 中使用

```html
<link rel="stylesheet" href="<?= xf_res_url('css', 'demo/test/base.css') ?>">
<script src="<?= xf_res_url('js', 'lib/lodash.min.js') ?>"></script>
<img src="<?= xf_res_url('images', 'logo.png') ?>">
```

### 3. Nginx 可选优化（跳过 PHP）

```nginx
location ^~ /zxf/res/ {
    rewrite ^/zxf/res/(.*)$ /$1 break;
    alias /path/to/project/vendor/zxf/res/assets/;
}
```

> 此配置让 Web 服务器直接响应，性能最优；但注意**外部注册的分组不在此规则内**（它们在别的目录下），仍走 PHP 代理。

---

## API 一览

### 助手函数（无命名空间，`xf_res_` 前缀）

| 函数 | 返回值 | 说明 |
|---|---|---|
| `xf_res_path($g, $r)` | string | 绝对路径 |
| `xf_res_relative($g, $r)` | string | 相对分组目录的规范化路径 |
| `xf_res_exists($g, $r)` | bool | 存在性判断，不抛异常 |
| `xf_res_read($g, $r)` | string | 读取全文（小文件） |
| `xf_res_route_prefix()` | string | 固定返回 `/zxf/res` |
| `xf_res_browser_path($g, $r)` | string | 浏览器路径（无域名） |
| `xf_res_url($g, $r, $base)` | string | 浏览器完整 URL |
| `xf_res_prepare($g, $r)` | array | 资源元数据 |
| `xf_res_etag_matches($h, $etag)` | bool | ETag 匹配判断 |
| `xf_res_serve($g, $r)` | void | 直接发送 HTTP 响应 |
| `xf_res_register_group($g, $p)` | void | 注册单个外部组 |
| `xf_res_register_groups($map)` | void | 批量注册外部组 |
| `xf_res_register_root($p, $prefix)` | void | 注册外部 assets 根目录 |
| `xf_res_unregister_group($g)` | void | 注销外部组 |
| `xf_res_clear_groups()` | void | 清空外部注册 |
| `xf_res_has_group($g)` | bool | 分组是否存在 |
| `xf_res_groups()` | array | 全部分组名 |
| `xf_res_all($type)` | array | 按类型收集资源 |
| `xf_res_fonts()` / `xf_res_css()` / `xf_res_js()` / `xf_res_images()` | array | 分类收集 |

### 类 API（命名空间 `zxf\res`）

- `AssetLocator::path()` / `relative()` / `exists()` / `read()` / `stream()` / `iterate()` / `iterateAll()` / `groups()` / `hasGroup()` / `root()` / `registerGroup()` / `registerGroups()` / `registerRoot()` / `unregisterGroup()` / `clearRegisteredGroups()`
- `AssetServer::prepare()` / `isNotModified()` / `notModifiedHeaders()` / `send()`
- `AssetType::fromExtension()` / `fromPath()` / `mimeFor()`

---

## 安全说明

- **路径逃逸**：所有入口经 `realpath` + 规范化前缀校验，`../` 与符号链接逃逸被拒绝；
- **外部注册冲突**：不允许覆盖内置分组，冲突时抛 `InvalidArgumentException`；
- **代理滥用**：仅 `AssetType` 声明的扩展名可被代理输出，`.php`、`.env` 等敏感文件无法通过代理读取；
- **MIME 混淆**：响应带 `X-Content-Type-Options: nosniff`；
- **大文件 OOM**：所有输出使用 `readfile` 流式；
- **Host 头**：`xf_res_url()` 使用 `$_SERVER['HTTP_HOST']`，最终由应用层校验 Host。

## 性能说明

- `xf_res_url()` 首次调用一次 `realpath`，之后走进程内缓存，零 IO；
- `AssetServer::prepare()` 一次 `stat()` 同时拿到 size 与 mtime；
- ETag 由 `mtime+size` 组成，不读文件内容；
- 强缓存 `max-age=31536000, immutable` + ETag 协商，浏览器二次访问不达服务器；
- 大文件流式输出，内存占用 O(1)；
- 注册外部分组后自动失效相关缓存，不影响已缓存的内置分组。

## License

MIT
