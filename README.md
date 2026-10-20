# wiseadmin/wise-image

> WiseAdmin 图片处理包 — 支持 GD/Imagick 双驱动、阿里云 OSS 风格参数处理、防盗链与签名验证

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-Apache%202.0-brightgreen)](LICENSE)
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
| **安全防护** | IP 黑名单、Referer 白名单/黑名单、签名防盗链（含 path 与时效）、源图保护 |
| **样式预设** | 预定义样式（如 `thumb`），一键应用 |
| **HTTP 响应** | 内置路由，直接输出处理后的图片 |
| **远程抓取** | 支持 `http(s)://` 远程图片源，内置 SSRF 防护、大小/超时限制与本地缓存 |
| **领域事件** | 处理前 / 处理后 / 缓存命中事件，可按需接入监听器 |
| **链式 API** | 9 个处理器均提供同名链式方法，与 URL 参数、样式预设等价 |
| **缓存治理** | 内置 `cache-clear` / `cache-prune` / `stats` 命令；缓存键含源文件指纹，原图更新自动失效 |
| **协商缓存** | 可配 `Cache-Control`，输出 `ETag` / `Last-Modified` 并支持 304 |
| **路径约束** | `security.file_root` 限制图片根目录，阻断目录穿越 |
| **处理器扩展** | 通过 `ExtPoints::IMAGE_PROCESSOR` 新增 / 覆盖图片操作（如 `sharpen`、`qrcode`） |
| **击穿治理** | 可选单飞锁（复用 `wise-support` 分布式锁），同图并发首访只处理一次 |

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
        // 允许访问的图片根目录（非空时禁止目录穿越），空 = 不限制
        'file_root'       => '',
    ],

    // 缓存配置
    'cache' => [
        'driver' => 'file',               // file / redis
        'ttl'    => 2592000,              // 30天
        'path'   => 'runtime/wise-image/cache',
        'prefix' => 'wise_image:',

        // 单飞锁：同一 cache key 并发首访只允许一个请求真正处理（默认关闭）
        'lock' => [
            'enabled'       => false,
            'driver'        => 'file',    // file | redis | database
            'ttl'           => 30,        // 锁最长持有（秒）
            'wait_timeout'  => 3.0,       // 等待方最长等待（秒）
            'poll_interval' => 50,        // 轮询间隔（毫秒）
            'redis_store'   => 'redis',
            'path'          => 'runtime/wise-image/locks',
        ],
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

    // 远程图片抓取（默认关闭，开启前请务必阅读「远程图片抓取」章节的安全说明）
    'remote' => [
        'enabled'          => false,
        'timeout'          => 10,
        'connect_timeout'  => 5,
        'max_size'         => 10485760,            // 10MB
        'max_redirects'    => 3,
        'allowed_schemes'  => ['http', 'https'],
        'allowed_hosts'    => [],                  // 空 = 不限制，支持 *.example.com
        'blocked_hosts'    => [],
        'allow_private_ip' => false,               // 禁止解析到内网/保留地址（防 SSRF）
        'allowed_mime'     => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'],
        'user_agent'       => 'WiseImage/1.0',
        'headers'          => [],                  // 如 ['Referer' => 'https://example.com/']
        'verify_tls'       => true,
        'cache'            => [
            'enabled' => true,
            'path'    => 'runtime/wise-image/remote',
            'ttl'     => 86400,
        ],
    ],

    // HTTP 协商缓存（ETag / Last-Modified / 304）
    'response' => [
        'cache_control' => true,
        'max_age'       => null,      // null = 取 cache.ttl
        'immutable'     => false,
        'etag'          => true,
        'last_modified' => true,
        'visibility'    => 'public',  // public | private
    ],
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

### 7. 远程图片抓取

> 需先在 `config/wise-image.php` 中设置 `remote.enabled = true`。

```php
use wise\image\facade\Image;

// 方式一：直接打开远程图片（自动下载到本地再交给驱动处理）
Image::open('https://cdn.example.com/photo.jpg')
    ->parseUrlParams('resize,w_400,h_400/quality,q_80')
    ->save('/path/to/remote-thumb.jpg');

// 方式二：显式打开远程图片（可控制是否复用本地缓存副本）
Image::openRemote('https://cdn.example.com/photo.jpg', true)
    ->getInfo();

// 方式三：仅下载到本地，返回本地文件路径
$localPath = Image::download('https://cdn.example.com/photo.jpg');
```

`Image::open()` 会自动识别 `http://` / `https://` 前缀并委托远程抓取，
因此 `?url=` 之类的动态图床场景可直接把 URL 透传给 `open()`。

**安全说明（重要）**：

- 默认**关闭**远程抓取；开启后仍默认禁止解析到内网 / 回环 / 保留地址（`allow_private_ip = false`），
  可有效阻断 SSRF（含 `169.254.169.254` 云元数据探测）。
- 每次重定向都会重新做安全校验，避免通过 302 跳转到内网。
- 使用 cURL 时通过 `CURLOPT_RESOLVE` 绑定已校验 IP，规避 DNS Rebinding。
- 边下载边计数，超过 `max_size` 立即中断，防止大文件打满内存。
- 下载结果需同时通过 `Content-Type` 与 `getimagesizefromstring` 双重校验。
- 若源站存在防盗链，可通过 `remote.headers.Referer` 配置绕过。

### 8. 签名防盗链

开启后，图片请求必须携带合法签名，否则返回 403。

```php
// config/wise-image.php
'security' => [
    'signature' => [
        'enabled' => true,
        'secret'  => env('IMAGE_SIGN_SECRET', ''),
        'timeout' => 3600,   // 签名有效期（秒）；<= 0 表示不校验时效
    ],
],
```

**生成签名 URL**：

```php
use wise\image\facade\Image;

// 返回 /image/upload/foo.jpg?x-oss-process=...&t=1700000000&sign=xxxx
$url = Image::signUrl('/image/upload/foo.jpg', [
    'x-oss-process' => 'image/resize,w_200',
]);

// 也可直接使用签名器
$signer = new \wise\image\security\Signer($secret, 3600);
$url    = $signer->signUrl('/image/upload/foo.jpg', ['style' => 'thumb']);
```

**签名规则**（与客户端约定，务必一致）：

```
待签名字符串 = path + '?' + 排序后的查询参数 + '&t=' + 时间戳
签名值       = hash_hmac('sha256', 待签名字符串, secret)   // 十六进制小写
```

- `path` 为请求 pathinfo（**含**路由前缀），如 `/image/upload/foo.jpg`；
  支持传入完整 URL，内部会自动提取路径部分。
- 查询参数**不含** `sign` / `t` / `ts`，且会按 key 升序排序，因此客户端参数顺序不影响验签。
- `timeout > 0` 时**必须**携带 `t`（Unix 秒），服务端同时校验「已过期」与「未来时间」
  （容忍 60 秒客户端时钟偏移）。
- 签名串包含 `path`，因此**同一签名无法复用到其它图片**。

### 9. 缓存治理命令

命令由 `ImageServiceProvider` 通过 `$this->commands([...])` 注册，`php think list` 可见：

```bash
php think wise:image:cache-clear              # 清空主缓存 + 远程下载副本
php think wise:image:cache-clear --dry-run    # 只统计不删除

php think wise:image:cache-prune              # 默认按 cache.ttl 折算天数
php think wise:image:cache-prune --days=7
php think wise:image:cache-prune --days=7 --dry-run

php think wise:image:stats                    # 缓存目录占用 / 文件数 / 时间跨度
```

> 三条命令仅适用于 `cache.driver = file`；Redis 驱动会提示改用框架缓存命令。

**缓存键与失效**：缓存键 = `源身份(远程URL/文件路径) + filemtime×filesize + 操作链 + 驱动 + 格式 + 质量`。
原图被替换（修改时间或大小变化）后旧缓存自动失效，无需手动清理。

### 10. HTTP 协商缓存

`response()` 会按 `response` 配置输出 `Cache-Control`、`ETag`、`Last-Modified`；
客户端带 `If-None-Match` / `If-Modified-Since` 且命中时直接返回 **304**（不输出图片体）。

```php
// 编程式检查当前请求是否会走 304（便于单测 / 调试）
$state = Image::open($path)->httpCacheState();
// ['etag' => '"..."', 'last_modified' => 1700000000,
//  'cache_control' => 'public, max-age=2592000', 'not_modified' => false]
```

### 11. 扩展自定义驱动

驱动解析优先走 `ExtPoints::IMAGE_DRIVER` 扩展点，未注册时回退内置 `gd` / `imagick`：

```php
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\image\driver\DriverInterface;

ExtPoint::definePoint(ExtPoints::IMAGE_DRIVER, ExtPoint::POLICY_SINGLE, DriverInterface::class);
ExtPoint::register(ExtPoints::IMAGE_DRIVER, 'my_gd', MyGdDriver::class);

// config/wise-image.php: 'driver' => 'my_gd'
```

### 12. 扩展自定义处理器

内置处理器（`resize` / `crop` / …）在 `ImageServiceProvider` 启动时已注册到
`ExtPoints::IMAGE_PROCESSOR`（`multi` 策略）。注册同名实现可**覆盖**内置行为，
注册新名字则**新增**一种操作：

```php
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\image\processor\ProcessorInterface;

// 新增操作：?x-oss-process=image/sharpen,r_2
ExtPoint::register(ExtPoints::IMAGE_PROCESSOR, 'sharpen', SharpenProcessor::class);

// 覆盖内置实现（例如用 Imagick 高斯模糊替换 GD 的）
ExtPoint::register(ExtPoints::IMAGE_PROCESSOR, 'blur', ImagickBlurProcessor::class);
```

自定义处理器只需实现两个方法：

```php
class SharpenProcessor implements ProcessorInterface
{
    public function process(ImageManager $manager, DriverInterface $driver, array $params): void
    {
        $driver->sharpen((int) ($params['r'] ?? 1));   // 参数来自 URL / 样式串 / operation()
    }

    public function getName(): string
    {
        return 'sharpen';   // 与 URL 中的操作名一致
    }
}
```

注册后三种入口都可使用：

| 入口 | 用法 |
|------|------|
| URL 参数 | `?x-oss-process=image/sharpen,r_2` |
| 样式预设 | `'styles' => ['sharp' => 'sharpen,r_2']` |
| 链式 API | `$manager->operation('sharpen', ['r' => 2])` |

> 内置处理器首次解析后会进程内复用；长驻进程热重载场景可调用
> `ImageManager::clearProcessorCache()` 清空缓存。可用操作列表见 `$manager->availableOperations()`。

### 13. 高并发：单飞锁（缓存击穿治理）

同一张图并发首访时默认会各自跑一遍处理链。开启单飞锁后只允许一个请求真正处理，
其余请求短暂轮询缓存，命中即返回；等待超时则降级自行处理（削峰而非消除，保证可用性）。

```php
// config/wise-image.php
'cache' => [
    'lock' => [
        'enabled'      => true,
        'driver'       => 'file',   // 单机多进程；多副本部署请用 redis
        'wait_timeout' => 3.0,      // 等待方最长等待（秒）：等待会占用 FPM worker，不宜过大
        'ttl'          => 30,       // 锁最长持有（秒），防死锁
    ],
],
```

锁实现复用 `wise-support` 的 `DistributedLock`（`file` / `redis` / `database` 三驱动，
含 token 所有权与 TTL 兜底），也可通过 `ImageManager::setCacheLock()` 注入自定义实现。

**锁粒度**为单个 cache key，因此同一张图的 `thumb` 与 `medium` 互不阻塞。

**配套收益**：`output()` / `response()` 已改为**先查缓存再执行处理链** —— 缓存命中时不再解码与重算。
这比加锁收益更大：此前命中仍会跑完整处理链，只省下「最终编码」一步。

---

## 处理器说明

| 处理器 | 链式方法 | 参数 | 说明 |
|--------|----------|------|------|
| Resize | `resize($w, $h = 0, $mode = 'lfit')` | w, h, mode | 缩放；`h` 省略时按原图等比推算 |
| Resize | `width($w)` / `height($h)` | w / h | 仅指定单边等比缩放 |
| Crop | `crop($w, $h, $x = 0, $y = 0)` | w, h, x, y | 按坐标裁剪 |
| Crop | `cropByGravity($w, $h, $gravity = 'c')` | w, h, gravity | 九宫格定位裁剪 |
| Watermark | `watermark($path, $pos = 'se', $opacity = 50)` | path, pos, opacity(0-100) | 图片水印 |
| Watermark | `textWatermark($text, $options = [])` | text, font/size/color/position/opacity/angle | 文字水印 |
| Rotate | `rotate($angle)` | angle(-360~360) | 旋转 |
| Blur | `blur($radius = 3)` | radius(1-100) | 高斯模糊 |
| Brightness | `brightness($level)` | level(-100~100) | 亮度调整 |
| Contrast | `contrast($level)` | level(-100~100) | 对比度调整 |
| Format | `format($ext)` | ext(jpg/png/webp/gif/bmp) | 格式转换 |
| Format | `autoFormat()` | — | 按 Accept 头自动选择（WebP） |
| Quality | `quality($q)` | q(0-100) | 质量 |

**缩放模式**：`lfit`（默认，等比不超出，别名 `contain` / `fit`）、`fill`（等比后居中裁剪，别名 `cover` / `crop`）、
`fixed`（强制拉伸，别名 `stretch` / `exact`）、`ratio`（按百分比，别名 `percent` / `scale`）。

**位置参数**：使用 `nw|n|ne|w|c|e|sw|s|se`，同时兼容 `southeast`、`bottom-right`、`pos_southeast` 等写法。

> 链式方法与 `parseUrlParams()` / `style()` 等价，可混用；如需自定义处理器，仍可使用 `addOperation()`。

---

## 目录结构

```
wise-image/
├── composer.json
├── config/
│   └── wise-image.php              # 配置文件
└── src/
    ├── ImageServiceProvider.php    # 服务提供者
    ├── ImageManager.php            # 核心图片管理器（操作链构建 + 安全 + 缓存）
    ├── StatsCollector.php          # 统计收集器
    ├── helper.php                  # 助手函数（wise_image()）
    ├── driver/
    │   ├── DriverInterface.php     # 驱动接口
    │   ├── GdDriver.php            # GD 驱动
    │   └── ImagickDriver.php       # Imagick 驱动
    ├── processor/
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
    ├── remote/
    │   └── RemoteFetcher.php       # 远程图片抓取器（SSRF 防护 / 大小限制 / 本地缓存）
    ├── cache/
    │   ├── CacheManager.php        # 缓存统计 / 清空 / 过期裁剪
    │   └── CachePathResolver.php   # 缓存目录解析（相对路径 → 项目根）
    ├── command/
    │   ├── AbstractCacheCommand.php
    │   ├── CacheClearCommand.php   # wise:image:cache-clear
    │   ├── CachePruneCommand.php   # wise:image:cache-prune
    │   └── StatsCommand.php        # wise:image:stats
    ├── security/
    │   └── Signer.php              # URL 签名器（HMAC-SHA256，含 path 与时效校验）
    ├── event/
    │   ├── ImageCacheHitEvent.php
    │   ├── ImageProcessingEvent.php
    │   └── ImageProcessedEvent.php
    ├── exception/
    │   ├── ErrorCode.php           # 业务错误码枚举（120000~129999）
    │   └── ImageException.php      # 统一异常 + 静态工厂
    ├── facade/
    │   └── Image.php               # Facade
    └── util/
        └── PositionHelper.php      # 九宫格定位计算
```

---

## 依赖关系

- **必需**：`wise-support`
- **可选（suggest）**：`ext-imagick`（Imagick 驱动，性能更优且支持更多格式；未装则回退 GD）

## 许可证

MIT
