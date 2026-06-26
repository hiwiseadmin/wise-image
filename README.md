# wiseadmin/wise-image

> WiseAdmin 图片处理包 — 支持 GD/Imagick 双驱动、阿里云 OSS 风格参数处理、防盗链与签名验证

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-MIT-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--image-orange)](https://packagist.org/packages/wiseadmin/wise-image)

---

## 特性

| 模块 | 说明 |
|------|------|
| **双驱动** | GD（PHP 内置）/ Imagick（更优性能），驱动层完全解耦 |
| **9 种处理器** | 缩放、裁剪、旋转、水印、模糊、亮度、对比度、格式转换、质量调整 |
| **链式操作** | `open()->resize()->watermark()->save()` 构建器风格 |
| **OSS 风格 URL** | 从 URL 参数 `?x-oss-process=image/resize,w_200` 解析操作链 |
| **缓存系统** | 支持 File / Redis 双缓存，TTL 可配 |
| **安全防护** | IP 黑名单、Referer 白名单/黑名单、签名防盗链、源图保护 |
| **样式预设** | 预定义样式（如 `thumb`），一键应用 |
| **HTTP 响应** | 内置路由，直接输出处理后的图片 |

---

## 安装

```bash
composer require wiseadmin/wise-image
```

**驱动依赖**：

- **GD 驱动**（默认，零配置）：需 PHP `ext-gd` 扩展
- **Imagick 驱动**（推荐，性能更优）：需 PHP `ext-imagick` 扩展

```bash
# 可选安装 Imagick 扩展以获得更优性能和更多格式支持
composer require ext-imagick
```

---

## 配置

发布配置文件：

```bash
php think wise:image:publish
```

配置文件 `config/wise-image.php`：

```php
return [
    // 驱动选择：gd / imagick
    'driver' => 'gd',

    // 默认输出质量（1-100）
    'default_quality' => 85,

    // 是否自动转为 WebP
    'auto_webp' => true,

    // 安全防护
    'security' => [
        // IP 黑名单
        'ip_blacklist' => [
            'enabled' => false,
            'ips'     => [],
        ],
        // Referer 检查
        'referer' => [
            'enabled'      => false,
            'allow_empty'  => true,
            'whitelist'    => [],
            'blacklist'    => [],
        ],
        // 签名防盗链
        'signature' => [
            'enabled'  => false,
            'secret'   => '',
            'timeout'  => 3600,
        ],
        // 源图保护
        'origin_protect' => [
            'enabled' => false,
        ],
        // 最大尺寸限制
        'max_width'  => 4096,
        'max_height' => 4096,
        // 允许的格式
        'allowed_formats' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
    ],

    // 缓存配置
    'cache' => [
        'driver' => 'file',               // file / redis
        'ttl'    => 2592000,              // 30天
        'path'   => 'runtime/wise-image/cache',
        'prefix' => 'wise_image:',
    ],

    // 预定义样式
    'styles' => [
        'thumb'   => 'resize,w_200,h_200/quality,q_80',
        'small'   => 'resize,w_400,h_400',
        'medium'  => 'resize,w_800,h_800',
        'watermark' => 'watermark,path_logo.png,pos_southeast',
    ],

    // 字体文件路径（水印文字用）
    'font_path' => null,

    // 内置路由前缀
    'route_prefix' => '/image',
];
```

---

## 使用指南

### 1. 基本操作（Facade）

```php
use wise\image\facade\Image;

// 打开图片 → 缩放 → 裁剪 → 保存
Image::open('/path/to/photo.jpg')
    ->resize(800, 600)
    ->crop(400, 400, 100, 50)
    ->quality(85)
    ->save('/path/to/output.jpg');

// 创建空白画布 → 生成缩略图
Image::make(800, 600)
    ->open('/path/to/photo.jpg')
    ->resize(800, 600, 'fill')
    ->save('/path/to/thumb.jpg');
```

### 2. 链式操作

```php
Image::open('/path/to/photo.jpg')
    ->resize(1200, 800)           // 缩放
    ->watermark('/path/to/logo.png', 'southeast', 50)  // 右下角水印，透明度50
    ->blur(3)                      // 模糊半径3
    ->brightness(10)               // 亮度+10
    ->contrast(5)                  // 对比度+5
    ->rotate(90)                   // 旋转90度
    ->format('webp')               // 转换为 WebP
    ->quality(80)                  // 质量80
    ->save('/path/to/result.webp');

// 获取图片信息
$info = Image::open('/path/to/photo.jpg')->getInfo();
// ['width' => 1920, 'height' => 1080, 'mime' => 'image/jpeg', ...]
```

### 3. OSS 风格 URL 参数

```php
// 解析 URL 参数并执行操作链
Image::open('/path/to/photo.jpg')
    ->parseUrlParams('image/resize,w_400,h_300/quality,q_80/rotate,90')
    ->save('/path/to/result.jpg');
```

URL 格式：`?x-oss-process=image/resize,w_400,h_300/quality,q_80`

### 4. 使用预定义样式

```php
// 在配置中定义样式
// 'styles' => ['thumb' => 'resize,w_200,h_200/quality,q_80']

// 直接使用
Image::open('/path/to/photo.jpg')
    ->style('thumb')
    ->save('/path/to/thumb.jpg');
```

### 5. HTTP 响应输出

```php
// 在控制器中直接输出处理后的图片
public function preview()
{
    Image::open('/path/to/photo.jpg')
        ->resize(600, 400)
        ->format('webp')
        ->response();  // 输出 Content-Type: image/webp
}
```

### 6. 依赖注入方式

```php
use wise\image\ImageManager;

class PhotoController
{
    public function process(ImageManager $image)
    {
        $image->open('/path/to/photo.jpg')
            ->resize(400, 300)
            ->save('/path/to/thumb.jpg');

        return json(['ok' => true]);
    }
}
```

### 7. 命令行

```bash
# 发布配置
php think wise:image:publish

# 强制覆盖已有配置
php think wise:image:publish --force
```

---

## 处理器说明

| 处理器 | 方法 | 参数 | 说明 |
|--------|------|------|------|
| Resize | `resize($w, $h, $mode)` | w, h, mode(contain/fill/cover) | 缩放 |
| Crop | `crop($w, $h, $x, $y)` | w, h, x, y | 裁剪 |
| Rotate | `rotate($angle)` | angle(0-360) | 旋转 |
| Watermark | `watermark($path, $pos, $opacity)` | path, pos, opacity(0-100) | 图片水印 |
| Blur | `blur($radius)` | radius | 高斯模糊 |
| Brightness | `brightness($level)` | level(-100~100) | 亮度调整 |
| Contrast | `contrast($level)` | level(-100~100) | 对比度调整 |
| Format | `format($ext)` | ext(jpg/png/webp/gif) | 格式转换 |
| Quality | `quality($q)` | q(1-100) | 质量 |

---

## 目录结构

```
wise-image/
├── composer.json
├── config/
│   └── wise-image.php              # 配置文件
└── src/
    ├── Service.php                 # 服务提供者
    ├── ImageManager.php            # 核心图片管理器（构建器模式）
    ├── StatsCollector.php          # 统计收集器
    ├── helper.php                  # 助手函数
    ├── command/
    │   └── Publish.php             # 发布配置命令
    ├── Driver/
    │   ├── DriverInterface.php     # 驱动接口
    │   ├── GdDriver.php            # GD 驱动
    │   └── ImagickDriver.php       # Imagick 驱动
    ├── Event/
    │   ├── ImageCacheHitEvent.php
    │   ├── ImageProcessingEvent.php
    │   └── ImageProcessedEvent.php
    ├── Exception/
    │   └── ImageException.php
    ├── Facade/
    │   └── Image.php               # Facade
    ├── Processor/
    │   ├── ProcessorInterface.php  # 处理器接口
    │   ├── ResizeProcessor.php
    │   ├── CropProcessor.php
    │   ├── WatermarkProcessor.php
    │   ├── FormatProcessor.php
    │   ├── QualityProcessor.php
    │   ├── RotateProcessor.php
    │   ├── BlurProcessor.php
    │   ├── BrightnessProcessor.php
    │   └── ContrastProcessor.php
    └── trait/
        ├── ServiceTrait.php
        └── CommandTrait.php
```

---

## License

MIT
