<?php

declare(strict_types=1);

namespace wise\image;

use wise\image\cache\CachePathResolver;
use wise\image\driver\DriverInterface;
use wise\image\driver\GdDriver;
use wise\image\driver\ImagickDriver;
use wise\image\event\ImageCacheHitEvent;
use wise\image\event\ImageProcessedEvent;
use wise\image\event\ImageProcessingEvent;
use wise\image\exception\ErrorCode;
use wise\image\exception\ImageException;
use wise\image\processor\FormatProcessor;
use wise\image\processor\ProcessorInterface;
use wise\image\remote\RemoteFetcher;
use wise\image\security\Signer;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\lock\DistributedLock;
use wise\support\lock\LockInterface;
use wise\support\traits\Conditionable;
use wise\support\traits\Tappable;

/**
 * 图片处理核心管理器
 *
 * wise-image 的入口门面，提供构建器模式的图片处理 API。
 *
 * 核心能力：
 *   1. 打开/创建图片（open / make）
 *   2. 构建处理操作链（addOperation / parseUrlParams / style）
 *   3. 执行处理链（execute）
 *   4. 输出结果（save / output / response）
 *   5. 安全中间件（IP 黑名单 / Referer 防盗链 / 签名验证）
 *   6. 缓存支持（文件 / Redis）
 *
 * 用法示例：
 *   $manager = new ImageManager($config);
 *   $manager->open('/path/to/image.jpg')
 *       ->parseUrlParams('resize,w_200,h_200/quality,q_80')
 *       ->save('/path/to/output.webp', 'webp');
 */
class ImageManager
{
    use Conditionable;  // 条件链式调用
    use Tappable;       // 提供 tap() 方法用于在链式调用中执行回调

    // -----------------------------------------------------------------
    // 属性
    // -----------------------------------------------------------------

    /** @var array<string, mixed> 完整配置 */
    private array $config;

    /** @var DriverInterface|null 当前驱动实例（懒加载） */
    private ?DriverInterface $driver = null;

    /** @var string 源文件路径（open 时设置） */
    private string $sourcePath = '';

    /** @var string|null FormatProcessor 设置的目标格式 */
    private ?string $targetFormat = null;

    /**
     * 处理操作链
     *
     * 每一项为 [ProcessorInterface, array<string, mixed>]
     *
     * @var array<int, array{0: ProcessorInterface, 1: array<string, mixed>}>
     */
    private array $operations = [];

    /** @var bool 是否已执行处理链 */
    private bool $executed = false;

    /** @var bool make 模式（创建空白画布） */
    private bool $isMake = false;

    /** @var int make 模式宽度 */
    private int $makeWidth = 0;

    /** @var int make 模式高度 */
    private int $makeHeight = 0;

    /** @var \think\Request|null 框架请求实例（用于获取客户端 IP/Referer/参数） */
    private ?\think\Request $request = null;

    /** @var string 远程源 URL（openRemote 时设置，用于缓存键与调试） */
    private string $remoteUrl = '';

    /** @var RemoteFetcher|null 远程图片抓取器（懒加载，跨图片复用） */
    private ?RemoteFetcher $remoteFetcher = null;

    /**
     * 领域事件派发器
     *
     * 为 null 时回退到 ThinkPHP Event 总线；单元测试或自定义总线可注入替换。
     *
     * @var callable(object): void|null
     */
    private $eventDispatcher = null;

    // -----------------------------------------------------------------
    // 内置操作名 → 类名映射
    // -----------------------------------------------------------------

    /**
     * 内置操作名 → 处理器类
     *
     * 公开以便 ImageServiceProvider 将内置处理器注册到
     * {@see ExtPoints::IMAGE_PROCESSOR} 扩展点，保持单一来源。
     *
     * @var array<string, class-string<ProcessorInterface>>
     */
    public const OPERATION_MAP = [
        'resize'     => \wise\image\processor\ResizeProcessor::class,
        'crop'       => \wise\image\processor\CropProcessor::class,
        'watermark'  => \wise\image\processor\WatermarkProcessor::class,
        'format'     => \wise\image\processor\FormatProcessor::class,
        'quality'    => \wise\image\processor\QualityProcessor::class,
        'rotate'     => \wise\image\processor\RotateProcessor::class,
        'blur'       => \wise\image\processor\BlurProcessor::class,
        'brightness' => \wise\image\processor\BrightnessProcessor::class,
        'contrast'   => \wise\image\processor\ContrastProcessor::class,
    ];

    /** @var array<string, ProcessorInterface> 单例缓存 */
    private static array $processorCache = [];

    /** @var LockInterface|null 缓存单飞锁（懒加载；null = 未启用或不可用） */
    private ?LockInterface $cacheLock = null;

    /** @var bool 单飞锁是否已解析（用于区分「未解析」与「已解析为不启用」） */
    private bool $cacheLockResolved = false;

    /** 输出格式 → MIME */
    private const MIME_MAP = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
    ];

    // -----------------------------------------------------------------
    // 构造函数
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $config 完整配置（与 config/wise-image.php 结构一致）
     */
    public function __construct(array $config = [])
    {
        $defaults = [
            'driver'         => 'gd',
            'default_quality' => 85,
            'auto_webp'      => true,
            'cache'          => [
                'driver' => 'file',
                'ttl'    => 2592000,
                'path'   => 'runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'cache',
                'prefix' => 'wise_image:',
                // 单飞锁：同一 cache key 并发首访只允许一个请求真正处理（默认关闭）
                'lock'   => [
                    'enabled'       => false,
                    'driver'        => 'file', // file | redis | database（复用 wise-support DistributedLock）
                    'ttl'           => 30,     // 锁最长持有（秒），防死锁
                    'wait_timeout'  => 3.0,    // 等待方最长等待（秒）
                    'poll_interval' => 50,     // 轮询间隔（毫秒）
                    'redis_store'   => 'redis',
                    'path'          => 'runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'locks',
                ],
            ],
            'security'       => [
                'ip_blacklist'  => ['enabled' => false, 'ips' => []],
                'referer'       => ['enabled' => false, 'allow_empty' => true, 'whitelist' => [], 'blacklist' => []],
                'signature'     => ['enabled' => false, 'secret' => '', 'timeout' => 3600],
                'origin_protect' => ['enabled' => false],
                'max_width'     => 4096,
                'max_height'    => 4096,
                'allowed_formats' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
                // 非空时，本地图片必须落在该目录内（防目录穿越），空 = 不限制
                'file_root'     => '',
            ],
            'remote'         => RemoteFetcher::DEFAULTS,
            'response'       => [
                'cache_control' => true,
                'max_age'       => null, // null = 取 cache.ttl
                'immutable'     => false,
                'etag'          => true,
                'last_modified' => true,
                'visibility'    => 'public',
            ],
            'styles'         => [],
            'font_path'      => null,
            'route_prefix'   => '/image',
        ];

        $this->config = array_merge($defaults, $config);

        // 嵌套配置递归合并：宿主只覆盖单个字段（如 security.max_width）时不应丢失其余默认值。
        // styles 等列表型配置仍保持「整体覆盖」语义，避免默认样式无法被清空。
        foreach (['cache', 'security', 'remote', 'response'] as $nested) {
            $override = $config[$nested] ?? null;
            $this->config[$nested] = self::mergeConfig(
                $defaults[$nested],
                is_array($override) ? $override : []
            );
        }
    }

    /**
     * 配置深度合并
     *
     * 关联数组递归合并；列表（索引数组，如 allowed_hosts / headers）整体覆盖，
     * 避免按索引错位合并产生重复项。
     *
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function mergeConfig(array $defaults, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                array_key_exists($key, $defaults)
                && is_array($defaults[$key])
                && is_array($value)
                && !array_is_list($defaults[$key])
                && !array_is_list($value)
            ) {
                $defaults[$key] = self::mergeConfig($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    /**
     * 注入框架请求实例
     *
     * 设置后，安全检查将使用框架的 IP/Referer/参数获取方法，
     * 而非直接读取超全局变量。未设置时自动回退到 $_SERVER/$_GET。
     */
    public function setRequest(\think\Request $request): void
    {
        $this->request = $request;
    }

    /**
     * 注入领域事件派发器
     *
     * 默认回退到 ThinkPHP `Event::trigger()`；在没有应用容器（单元测试）
     * 或需要接入自定义总线时，可通过此方法替换。
     *
     * @param callable(object): void|null $dispatcher
     */
    public function setEventDispatcher(?callable $dispatcher): void
    {
        $this->eventDispatcher = $dispatcher;
    }

    public function __destruct()
    {
        $this->destroy();
    }

    // -----------------------------------------------------------------
    // 构建器方法（返回 $this 实现链式调用）
    // -----------------------------------------------------------------

    /**
     * 打开已有图片
     *
     * 支持三种来源：
     *   1. 本地绝对/相对路径；
     *   2. HTTP(S) 远程 URL —— 自动委托 {@see openRemote()}（受 remote.enabled 开关约束）；
     *   3. 其他协议（file:// 等）一律按本地路径处理，非法路径抛异常。
     *
     * @param string $path 图片文件路径或 http(s) URL
     * @return $this
     * @throws ImageException 当文件不存在、远程抓取未启用或下载失败时
     */
    public function open(string $path): self
    {
        // 远程 URL：统一走 RemoteFetcher（含 SSRF 防护 / 大小限制 / 缓存）
        if ($this->isRemoteUrl($path)) {
            return $this->openRemote($path);
        }

        if (!is_file($path)) {
            throw ImageException::fileNotFound($path);
        }

        // 路径约束：配置 security.file_root 后禁止访问目录之外的图片
        $this->assertPathAllowed($path);

        $this->reset();
        $this->sourcePath = $path;
        $this->isMake     = false;

        return $this;
    }

    /**
     * 打开远程图片（先下载到本地再交给驱动处理）
     *
     * @param string $url      远程图片 URL
     * @param bool   $useCache 是否复用本地已下载副本（false 强制重新下载）
     * @return $this
     * @throws ImageException 远程抓取未启用 / 主机被拒 / 下载失败 / 非图片时
     */
    public function openRemote(string $url, bool $useCache = true): self
    {
        $localPath = $this->getRemoteFetcher()->fetch($url, $useCache);

        $this->reset();
        $this->sourcePath = $localPath;
        $this->remoteUrl  = $url;
        $this->isMake     = false;

        StatsCollector::getInstance()->recordRemoteFetch();

        return $this;
    }

    /**
     * 下载远程图片到本地并返回本地文件路径（不打开）
     *
     * @param string $url      远程图片 URL
     * @param bool   $useCache 是否复用本地已下载副本
     * @return string 本地文件路径
     * @throws ImageException
     */
    public function download(string $url, bool $useCache = true): string
    {
        $path = $this->getRemoteFetcher()->fetch($url, $useCache);
        StatsCollector::getInstance()->recordRemoteFetch();

        return $path;
    }

    /**
     * 判断路径是否为 http(s) 远程 URL
     */
    public function isRemoteUrl(string $path): bool
    {
        return preg_match('#^https?://#i', trim($path)) === 1;
    }

    /**
     * 获取远程抓取器（懒加载，同一管理器内复用）
     */
    public function getRemoteFetcher(): RemoteFetcher
    {
        if ($this->remoteFetcher === null) {
            $this->remoteFetcher = new RemoteFetcher((array) ($this->config['remote'] ?? []));
        }

        return $this->remoteFetcher;
    }

    /**
     * 创建空白画布
     *
     * @param int $width  画布宽度
     * @param int $height 画布高度
     * @return $this
     */
    public function make(int $width, int $height): self
    {
        $this->reset();
        $this->isMake     = true;
        $this->makeWidth  = $width;
        $this->makeHeight = $height;

        return $this;
    }

    /**
     * 添加处理操作到链
     *
     * @param ProcessorInterface    $processor 处理器实例
     * @param array<string, mixed>  $params    处理器参数
     * @return $this
     */
    public function addOperation(ProcessorInterface $processor, array $params): self
    {
        $this->operations[] = [$processor, $params];
        return $this;
    }

    /**
     * 从 OSS 风格 URL 参数字符串解析操作链
     *
     * 格式：/resize,w_200,h_200,m_lfit/quality,q_80/format,f_webp/crop,w_300,h_300,g_center
     *
     * @param string $paramString 参数字符串（不含前导路由前缀）
     * @return $this
     * @throws ImageException 当操作名不存在时
     */
    public function parseUrlParams(string $paramString): self
    {
        // 去除收尾斜杠
        $paramString = trim($paramString, '/');

        if ($paramString === '') {
            return $this;
        }

        // 按 / 分割为操作分段
        $segments = explode('/', $paramString);

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            // 按 , 分割为操作名 + k_v 参数对
            $parts = explode(',', $segment);
            $operationName = strtolower(trim($parts[0]));

            $params = [];
            for ($i = 1, $len = count($parts); $i < $len; $i++) {
                $kv = trim($parts[$i]);
                if ($kv === '') {
                    continue;
                }
                $pos = strpos($kv, '_');
                if ($pos !== false && $pos > 0 && $pos < strlen($kv) - 1) {
                    $key          = substr($kv, 0, $pos);
                    $value        = substr($kv, $pos + 1);
                    $params[$key] = $value;
                }
            }

            // 查找并创建对应 Processor
            $processor = $this->resolveProcessor($operationName);
            $this->operations[] = [$processor, $params];
        }

        return $this;
    }

    /**
     * 应用预定义样式
     *
     * 从 `config.styles` 中查找样式名对应的参数字符串，
     * 并委托给 parseUrlParams 解析。
     *
     * @param string $name 样式名称
     * @return $this
     * @throws ImageException 当样式未找到时
     */
    public function style(string $name): self
    {
        $styles = $this->config['styles'] ?? [];

        if (!isset($styles[$name])) {
            throw ImageException::styleNotFound($name);
        }

        return $this->parseUrlParams((string) $styles[$name]);
    }

    // -----------------------------------------------------------------
    // 链式处理快捷方法（等价于 addOperation，构建器风格）
    // -----------------------------------------------------------------

    /**
     * 等比/拉伸缩放
     *
     * @param int    $width  目标宽度（px；ratio 模式下为百分比）
     * @param int    $height 目标高度（px；lfit 模式下可省略，按原图比例推算）
     * @param string $mode   模式：lfit/contain（默认）/ fill/cover / fixed / ratio
     * @return $this
     */
    public function resize(int $width, int $height = 0, string $mode = 'lfit'): self
    {
        return $this->addOperation($this->resolveProcessor('resize'), [
            'w' => $width,
            'h' => $height,
            'm' => $mode,
        ]);
    }

    /**
     * 仅指定宽度等比缩放
     *
     * @param int $width 目标宽度（px）
     * @return $this
     */
    public function width(int $width): self
    {
        return $this->addOperation($this->resolveProcessor('resize'), [
            'w' => $width,
            'm' => 'lfit',
        ]);
    }

    /**
     * 仅指定高度等比缩放
     *
     * @param int $height 目标高度（px）
     * @return $this
     */
    public function height(int $height): self
    {
        return $this->addOperation($this->resolveProcessor('resize'), [
            'h' => $height,
            'm' => 'lfit',
        ]);
    }

    /**
     * 按坐标裁剪
     *
     * @param int $width  裁剪宽度
     * @param int $height 裁剪高度
     * @param int $x      起始 X 坐标
     * @param int $y      起始 Y 坐标
     * @return $this
     */
    public function crop(int $width, int $height, int $x = 0, int $y = 0): self
    {
        return $this->addOperation($this->resolveProcessor('crop'), [
            'w' => $width,
            'h' => $height,
            'x' => $x,
            'y' => $y,
        ]);
    }

    /**
     * 按九宫格定位裁剪
     *
     * @param int    $width    裁剪宽度
     * @param int    $height   裁剪高度
     * @param string $gravity  定位：c（默认）/ nw / n / ne / w / e / sw / s / se，兼容 southeast 等长名
     * @return $this
     */
    public function cropByGravity(int $width, int $height, string $gravity = 'c'): self
    {
        return $this->addOperation($this->resolveProcessor('crop'), [
            'w' => $width,
            'h' => $height,
            'g' => $gravity,
        ]);
    }

    /**
     * 添加图片水印
     *
     * @param string $path     水印图片路径
     * @param string $position 位置：se（默认）/ nw / n / ne / w / c / e / sw / s，兼容 southeast 等长名
     * @param int    $opacity  透明度 0-100
     * @return $this
     */
    public function watermark(string $path, string $position = 'se', int $opacity = 50): self
    {
        return $this->addOperation($this->resolveProcessor('watermark'), [
            'image'   => $path,
            'g'       => $position,
            'opacity' => $opacity,
        ]);
    }

    /**
     * 添加文字水印
     *
     * @param string               $text    水印文字
     * @param array<string, mixed> $options 选项：font / size / color / position / opacity / angle
     * @return $this
     */
    public function textWatermark(string $text, array $options = []): self
    {
        return $this->addOperation($this->resolveProcessor('watermark'), [
            'text'    => $text,
            'font'    => $options['font'] ?? null,
            'size'    => (int) ($options['size'] ?? 20),
            'color'   => (string) ($options['color'] ?? '#FFFFFF'),
            'g'       => (string) ($options['position'] ?? 'se'),
            'opacity' => (int) ($options['opacity'] ?? 50),
            'angle'   => (float) ($options['angle'] ?? 0.0),
        ]);
    }

    /**
     * 设置输出格式
     *
     * @param string $format jpg / jpeg / png / gif / webp / bmp
     * @return $this
     */
    public function format(string $format): self
    {
        return $this->addOperation($this->resolveProcessor('format'), [
            'f' => strtolower($format),
        ]);
    }

    /**
     * 按客户端 Accept 头自动选择格式（auto_webp = true 时输出 WebP）
     *
     * @return $this
     */
    public function autoFormat(): self
    {
        return $this->addOperation($this->resolveProcessor('format'), []);
    }

    /**
     * 设置输出质量
     *
     * @param int $quality 0-100
     * @return $this
     */
    public function quality(int $quality): self
    {
        return $this->addOperation($this->resolveProcessor('quality'), [
            'q' => $quality,
        ]);
    }

    /**
     * 旋转图片
     *
     * @param float $angle 角度（顺时针为正，范围 -360 ~ 360）
     * @return $this
     */
    public function rotate(float $angle): self
    {
        return $this->addOperation($this->resolveProcessor('rotate'), [
            'v' => $angle,
        ]);
    }

    /**
     * 高斯模糊
     *
     * @param int $radius 模糊半径 1-100
     * @return $this
     */
    public function blur(int $radius = 3): self
    {
        return $this->addOperation($this->resolveProcessor('blur'), [
            'r' => $radius,
        ]);
    }

    /**
     * 调整亮度
     *
     * @param int $level -100 ~ 100
     * @return $this
     */
    public function brightness(int $level): self
    {
        return $this->addOperation($this->resolveProcessor('brightness'), [
            'v' => $level,
        ]);
    }

    /**
     * 调整对比度
     *
     * @param int $level -100 ~ 100
     * @return $this
     */
    public function contrast(int $level): self
    {
        return $this->addOperation($this->resolveProcessor('contrast'), [
            'v' => $level,
        ]);
    }

    /**
     * 添加任意已注册操作（内置或通过扩展点注册的自定义处理器）
     *
     * 9 个内置处理器都有同名快捷方法；自定义处理器统一用本方法：
     *
     * ```php
     * $manager->operation('sharpen', ['r' => 2]);
     * ```
     *
     * @param string               $name   操作名（与 URL 参数 / 样式串中的操作名一致）
     * @param array<string, mixed> $params 处理器参数
     * @return $this
     * @throws ImageException 操作未注册时
     */
    public function operation(string $name, array $params = []): self
    {
        return $this->addOperation($this->resolveProcessor(strtolower($name)), $params);
    }

    /**
     * 清空内置处理器单例缓存
     *
     * 内置处理器首次解析后会进程内复用；在长驻进程热重载、或测试中验证
     * 「扩展点覆盖内置实现」时需要先清空，避免旧实例把新注册挡住。
     */
    public static function clearProcessorCache(): void
    {
        self::$processorCache = [];
    }

    /**
     * 当前可用的操作名列表（内置 + 扩展点注册）
     *
     * @return string[]
     */
    public function availableOperations(): array
    {
        $names = array_keys(self::OPERATION_MAP);

        if (class_exists(ExtPoint::class) && class_exists(ExtPoints::class)) {
            foreach (array_keys(ExtPoint::all(ExtPoints::IMAGE_PROCESSOR)) as $name) {
                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    // -----------------------------------------------------------------
    // 上下文方法（供 Processor 使用）
    // -----------------------------------------------------------------

    /**
     * 获取完整配置
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 设置目标输出格式（由 FormatProcessor 调用）
     *
     * @param string $format 目标格式名（jpg/png/gif/webp/bmp）
     */
    public function setTargetFormat(string $format): void
    {
        $this->targetFormat = strtolower($format);
    }

    /**
     * 获取目标输出格式
     *
     * @return string|null
     */
    public function getTargetFormat(): ?string
    {
        return $this->targetFormat;
    }

    /**
     * 获取客户端 Accept 请求头
     *
     * 优先使用框架的 request 对象，未注入时回退到 $_SERVER。
     *
     * @return string
     */
    public function getClientAcceptHeader(): string
    {
        if ($this->request !== null) {
            return $this->request->server('HTTP_ACCEPT', '');
        }
        return $_SERVER['HTTP_ACCEPT'] ?? '';
    }

    /**
     * 获取签名器（用于生成带签名的图片 URL）
     *
     * @return Signer|null 未配置 secret 时返回 null
     */
    public function getSigner(): ?Signer
    {
        $signature = (array) ($this->config['security']['signature'] ?? []);
        $secret    = (string) ($signature['secret'] ?? '');

        if ($secret === '') {
            return null;
        }

        return new Signer($secret, (int) ($signature['timeout'] ?? 3600));
    }

    /**
     * 为图片路径生成带签名的 URL
     *
     * @param string               $path      图片路径（如 /image/upload/foo.jpg）
     * @param array<string, mixed> $params    附加查询参数（会一并参与签名）
     * @param int|null             $timestamp 时间戳，默认当前时间
     * @return string
     * @throws ImageException 未配置签名密钥时
     */
    public function signUrl(string $path, array $params = [], ?int $timestamp = null): string
    {
        $signer = $this->getSigner();
        if ($signer === null) {
            throw ImageException::signNotConfigured();
        }

        return $signer->signUrl($path, $params, $timestamp);
    }

    // -----------------------------------------------------------------
    // 执行引擎
    // -----------------------------------------------------------------

    /**
     * 执行处理链
     *
     * 依次：
     *   1. 安全中间件检查
     *   2. 获取/创建驱动
     *   3. 依次执行所有 Processor
     *   4. 标记已执行
     *
     * @return $this
     */
    public function execute(): self
    {
        $this->checkSecurity();
        $driver = $this->getDriver();

        // 图像尺寸与格式安全检查（5）
        $this->checkImageSecurity($driver);

        $startTime = microtime(true);

        // 处理前事件：监听器可做风控 / 审计 / 参数修正等
        $this->dispatchEvent(new ImageProcessingEvent(
            $this->sourceIdentity(),
            $this->describeOperations()
        ));

        foreach ($this->operations as [$processor, $params]) {
            $processor->process($this, $driver, $params);
        }

        $this->executed = true;
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        StatsCollector::getInstance()->recordProcess($durationMs);

        // 处理后事件：此时 resultSize 尚未产生（输出阶段才可确定），固定为 0
        $this->dispatchEvent(new ImageProcessedEvent(
            $this->sourceIdentity(),
            $startTime,
            (string) ($this->targetFormat ?? ''),
            0
        ));

        return $this;
    }

    // -----------------------------------------------------------------
    // 输出方法
    // -----------------------------------------------------------------

    /**
     * 保存图片到文件
     *
     * 若尚未执行，先调用 execute()。
     *
     * @param string       $path   目标文件路径
     * @param string|null  $format 输出格式（null 则按 targetFormat → 源格式）
     * @return bool 是否成功
     */
    public function save(string $path, ?string $format = null): bool
    {
        if (!$this->executed) {
            $this->execute();
        }

        $format = $format ?? $this->targetFormat;

        return $this->getDriver()->save($path, $format);
    }

    /**
     * 输出图片二进制字符串
     *
     * 若尚未执行，先调用 execute()。
     *
     * @param string|null $format 输出格式
     * @return string 图片二进制数据
     */
    public function output(?string $format = null): string
    {
        $format = $this->resolveOutputFormat($format);

        // 1) 先查缓存：命中则完全跳过解码 / 处理 / 编码
        $cached = $this->getCachedResult();
        if ($cached !== null) {
            StatsCollector::getInstance()->recordCacheHit();
            $this->dispatchEvent(new ImageCacheHitEvent($this->getCacheKey(), $this->sourceIdentity()));
            return $cached;
        }
        StatsCollector::getInstance()->recordCacheMiss();

        // 2) 未命中：单飞锁（可选）→ 处理 → 写缓存
        return $this->processAndCache($format);
    }

    /**
     * 解析最终输出格式（不依赖执行状态）
     *
     * 缓存键必须在 `execute()` **之前** 计算，因此这里需要能预测
     * {@see FormatProcessor} 的行为；预测逻辑与执行期共用同一份实现。
     *
     * @param string|null $explicit 调用方显式指定的格式（优先级最高）
     * @return string|null null 表示沿用源格式
     */
    public function resolveOutputFormat(?string $explicit = null): ?string
    {
        if ($explicit !== null) {
            return strtolower($explicit);
        }

        if ($this->targetFormat !== null) {
            return $this->targetFormat;
        }

        foreach ($this->operations as [$processor, $params]) {
            if ($processor->getName() === 'format') {
                return FormatProcessor::predict($this, $params);
            }
        }

        return null;
    }

    /**
     * 解析输出 MIME
     *
     * 刻意不使用 `$driver->getMime()`：那需要先 `open()`（完整解码），
     * 会让缓存命中也付出解码成本。未指定格式时只读文件头即可判定。
     */
    private function resolveOutputMime(?string $format): string
    {
        if ($format !== null) {
            return self::MIME_MAP[strtolower($format)] ?? 'application/octet-stream';
        }

        if ($this->sourcePath !== '' && is_file($this->sourcePath)) {
            $info = @getimagesize($this->sourcePath);
            if (is_array($info) && !empty($info['mime'])) {
                return (string) $info['mime'];
            }
        }

        return 'application/octet-stream';
    }

    /**
     * MIME 清洗与白名单兜底（防止响应头注入）
     */
    private function sanitizeMime(string $mime): string
    {
        $mime = preg_replace('/[\r\n]/', '', $mime) ?? $mime;

        $allowed = array_merge(array_values(self::MIME_MAP), ['application/octet-stream']);

        return in_array($mime, $allowed, true) ? $mime : 'application/octet-stream';
    }

    /**
     * 处理并写入缓存（含可选的单飞锁）
     *
     * 单飞锁用于削峰：同一 cache key 并发首访时只允许一个请求真正处理，
     * 其余请求短暂轮询缓存，命中即返回；等待超时则降级自行处理（保证可用性）。
     *
     * @throws ImageException
     */
    private function processAndCache(?string $format): string
    {
        $lock = $this->cacheLock();

        if ($lock === null) {
            return $this->renderAndCache($format);
        }

        $lockKey = 'wise-image:' . md5($this->getCacheKey());

        if ($lock->acquire($lockKey, $this->lockTtl())) {
            try {
                return $this->renderAndCache($format);
            } finally {
                $lock->release($lockKey);
            }
        }

        // 未抢到锁：等待持有者写出缓存
        $lockConfig = (array) ($this->config['cache']['lock'] ?? []);
        $cached = $this->waitForCache(
            (float) ($lockConfig['wait_timeout'] ?? 3.0),
            (int) ($lockConfig['poll_interval'] ?? 50)
        );

        if ($cached !== null) {
            StatsCollector::getInstance()->recordCacheHit();
            StatsCollector::getInstance()->recordLockWaitHit();
            $this->dispatchEvent(new ImageCacheHitEvent($this->getCacheKey(), $this->sourceIdentity()));
            return $cached;
        }

        // 等待超时：降级自行处理，保证可用性（削峰而非消除）
        StatsCollector::getInstance()->recordLockTimeout();

        return $this->renderAndCache($format);
    }

    /**
     * 真正执行处理链并写入缓存
     */
    private function renderAndCache(?string $format): string
    {
        if (!$this->executed) {
            $this->execute();
        }

        $result = $this->getDriver()->output($format);
        $this->setCachedResult($result);

        return $result;
    }

    /**
     * 轮询等待缓存出现（不忙等）
     *
     * @param float $timeout        最长等待秒数
     * @param int   $pollIntervalMs 轮询间隔毫秒
     */
    private function waitForCache(float $timeout, int $pollIntervalMs): ?string
    {
        if ($timeout <= 0) {
            return null;
        }

        $pollIntervalMs = max(10, $pollIntervalMs);
        $deadline       = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            usleep($pollIntervalMs * 1000);

            $cached = $this->getCachedResult();
            if ($cached !== null) {
                return $cached;
            }
        }

        return null;
    }

    /**
     * 缓存单飞锁（懒加载）
     *
     * 复用 wise-support 的 {@see DistributedLock}（redis / file / database 三驱动，
     * 含 token 所有权与 TTL 兜底）。未启用或扩展不可用时返回 null。
     */
    public function cacheLock(): ?LockInterface
    {
        if ($this->cacheLockResolved) {
            return $this->cacheLock;
        }

        $this->cacheLockResolved = true;

        $lockConfig = (array) ($this->config['cache']['lock'] ?? []);
        if (empty($lockConfig['enabled']) || !class_exists(DistributedLock::class)) {
            return null;
        }

        // 始终显式给出 lock_dir，避免 DistributedLock 回退到 runtime_path() 助手
        $options = [
            'redis_store' => $lockConfig['redis_store'] ?? 'redis',
            'lock_dir'    => CachePathResolver::resolve(
                isset($lockConfig['path']) ? (string) $lockConfig['path'] : null,
                'runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'locks'
            ),
        ];

        $this->cacheLock = new DistributedLock((string) ($lockConfig['driver'] ?? 'file'), $options);

        return $this->cacheLock;
    }

    /**
     * 注入自定义单飞锁（测试 / DI 用）
     */
    public function setCacheLock(?LockInterface $lock): void
    {
        $this->cacheLock         = $lock;
        $this->cacheLockResolved = true;
    }

    /**
     * 锁最长持有时间（秒）
     */
    private function lockTtl(): int
    {
        return max(1, (int) ($this->config['cache']['lock']['ttl'] ?? 30));
    }

    /**
     * HTTP 响应输出
     *
     * 设置 Content-Type 头后输出图片数据。
     *
     * @param string|null $format 输出格式
     */
    public function response(?string $format = null): void
    {
        // 格式与 MIME 均可提前解析：无需 execute()（缓存命中时完全跳过解码）
        $format = $this->resolveOutputFormat($format);
        $mime   = $this->sanitizeMime($this->resolveOutputMime($format));

        // 设置响应头（$mime 已在 sanitizeMime 中做清洗与白名单兜底，防止响应头注入）
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);

            // HTTP 协商缓存：Cache-Control / ETag / Last-Modified，命中校验器可直接返回 304
            $state = $this->httpCacheState();

            if ($state['cache_control'] !== null) {
                header('Cache-Control: ' . $state['cache_control']);
            }
            if ($state['etag'] !== null) {
                header('ETag: ' . $state['etag']);
            }
            if ($state['last_modified'] !== null) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $state['last_modified']) . ' GMT');
            }

            if ($state['not_modified']) {
                http_response_code(304);
                return;
            }
        }

        echo $this->output($format);
    }

    /**
     * 计算 HTTP 协商缓存状态
     *
     * 返回「应写入的响应头 + 是否应返回 304」，便于在无 HTTP 上下文的环境
     * （CLI / 单测）中直接断言，而不必依赖 `headers_list()`。
     *
     * @return array{etag: string|null, last_modified: int|null, cache_control: string|null, not_modified: bool}
     */
    public function httpCacheState(): array
    {
        $response = (array) ($this->config['response'] ?? []);

        // ETag：基于缓存键（源身份 + 指纹 + 操作链 + 驱动 + 格式 + 质量）
        $etag = !empty($response['etag']) ? '"' . md5($this->getCacheKey()) . '"' : null;

        // Last-Modified：取源文件（远程为本地副本）修改时间
        $lastModified = null;
        if (!empty($response['last_modified']) && $this->sourcePath !== '' && is_file($this->sourcePath)) {
            $mtime        = @filemtime($this->sourcePath);
            $lastModified = $mtime === false ? null : (int) $mtime;
        }

        $cacheControl = null;
        if (!empty($response['cache_control'])) {
            $maxAge = $response['max_age'] ?? null;
            $maxAge = $maxAge === null
                ? (int) ($this->config['cache']['ttl'] ?? 2592000)
                : (int) $maxAge;

            $visibility   = ($response['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
            $cacheControl = $visibility . ', max-age=' . max(0, $maxAge);

            if (!empty($response['immutable'])) {
                $cacheControl .= ', immutable';
            }
        }

        return [
            'etag'          => $etag,
            'last_modified' => $lastModified,
            'cache_control' => $cacheControl,
            'not_modified'  => $this->isNotModified($etag, $lastModified),
        ];
    }

    /**
     * 判断客户端缓存是否仍然有效
     *
     * 按 RFC 9110：同时存在 If-None-Match 与 If-Modified-Since 时，前者优先。
     */
    private function isNotModified(?string $etag, ?int $lastModified): bool
    {
        $ifNoneMatch = trim($this->requestHeader('HTTP_IF_NONE_MATCH'));

        if ($ifNoneMatch !== '') {
            if ($etag === null) {
                return false;
            }

            return $ifNoneMatch === '*' || str_contains($ifNoneMatch, $etag);
        }

        $ifModifiedSince = trim($this->requestHeader('HTTP_IF_MODIFIED_SINCE'));
        if ($ifModifiedSince !== '' && $lastModified !== null) {
            $since = strtotime($ifModifiedSince);

            return $since !== false && $lastModified <= $since;
        }

        return false;
    }

    /**
     * 读取请求头（优先框架 request，回退超全局）
     */
    private function requestHeader(string $key): string
    {
        if ($this->request !== null) {
            return (string) $this->request->server($key, '');
        }

        return (string) ($_SERVER[$key] ?? '');
    }

    /**
     * 获取当前图片信息
     *
     * @return array{width: int, height: int, mime: string, format: string, driver: string}
     */
    public function getInfo(): array
    {
        if (!$this->executed) {
            $this->execute();
        }
        return $this->getDriver()->getInfo();
    }

    /**
     * 销毁驱动资源
     */
    public function destroy(): void
    {
        if ($this->driver !== null) {
            $this->driver->destroy();
            $this->driver = null;
        }
        $this->executed = false;
    }

    // -----------------------------------------------------------------
    // 驱动工厂（内部）
    // -----------------------------------------------------------------

    /**
     * 获取或创建驱动实例
     *
     * 懒加载策略：首次调用时根据 config.driver 创建。
     *
     * @return DriverInterface
     * @throws ImageException 当驱动不存在或不可用时
     */
    private function getDriver(): DriverInterface
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $driverName = strtolower((string) ($this->config['driver'] ?? 'gd'));
        $driverClass = $this->resolveDriverClass($driverName);

        // ImagickDriver 需要 remote 配置，以便 open() 远程 URL 时委托 RemoteFetcher 抓取
        $this->driver = is_a($driverClass, ImagickDriver::class, true)
            ? new $driverClass((array) ($this->config['remote'] ?? []))
            : new $driverClass();

        // 打开或创建
        if ($this->isMake) {
            $this->driver->create($this->makeWidth, $this->makeHeight);
        } elseif ($this->sourcePath !== '') {
            $this->driver->open($this->sourcePath);
        }

        return $this->driver;
    }

    /**
     * 解析驱动类名
     *
     * 优先级：
     *   1. `ExtPoints::IMAGE_DRIVER` 扩展点中按名称注册的实现（允许宿主/插件替换或新增驱动）；
     *   2. 包内置的 gd / imagick。
     *
     * @param string $name 驱动名（配置 `driver`）
     * @return class-string<DriverInterface>
     * @throws ImageException 驱动未注册时
     */
    private function resolveDriverClass(string $name): string
    {
        if (
            class_exists(ExtPoint::class)
            && class_exists(ExtPoints::class)
            && ExtPoint::has(ExtPoints::IMAGE_DRIVER, $name)
        ) {
            $concrete = ExtPoint::get(ExtPoints::IMAGE_DRIVER, $name);
            if (is_string($concrete) && class_exists($concrete)) {
                /** @var class-string<DriverInterface> $concrete */
                return $concrete;
            }
        }

        $builtin = [
            'gd'      => GdDriver::class,
            'imagick' => ImagickDriver::class,
        ];

        if (!isset($builtin[$name])) {
            throw ImageException::driverNotFound($name);
        }

        return $builtin[$name];
    }

    // -----------------------------------------------------------------
    // 安全中间件（内部）
    // -----------------------------------------------------------------

    /**
     * 安全检查
     *
     * 依次检查 IP 黑名单 → Referer 防盗链 → 签名验证。
     * 仅当对应 config 项 enabled = true 时检查。
     *
     * @throws ImageException 安全检查未通过时
     */
    private function checkSecurity(): void
    {
        $security = $this->config['security'] ?? [];

        // 1. IP 黑名单
        if (!empty($security['ip_blacklist']['enabled'])) {
            $this->checkIpBlacklist($security['ip_blacklist']['ips'] ?? []);
        }

        // 2. Referer 防盗链
        if (!empty($security['referer']['enabled'])) {
            $this->checkReferer($security['referer']);
        }

        // 3. URL 签名验证
        if (!empty($security['signature']['enabled'])) {
            $this->checkSignature($security['signature']);
        }

        // 4. 原图保护（P2）
        if (!empty($security['origin_protect']['enabled'])) {
            $this->checkOriginProtect();
        }
    }

    /**
     * 图片尺寸与格式安全检查
     *
     * 防止 image bomb 攻击：超大尺寸图片可能导致内存溢出，
     * 非白名单格式可能存在解析漏洞。
     */
    private function checkImageSecurity(DriverInterface $driver): void
    {
        $security = $this->config['security'] ?? [];
        $maxWidth  = (int) ($security['max_width'] ?? 4096);
        $maxHeight = (int) ($security['max_height'] ?? 4096);
        $allowedFormats = (array) ($security['allowed_formats'] ?? []);

        // 尺寸检查
        if ($maxWidth > 0 && $maxHeight > 0) {
            $w = $driver->getWidth();
            $h = $driver->getHeight();
            if ($w > $maxWidth || $h > $maxHeight) {
                throw ImageException::processFailed('open', sprintf(
                    '图片尺寸 %dx%d 超过最大限制 %dx%d',
                    $w, $h, $maxWidth, $maxHeight
                ));
            }
        }

        // 格式检查
        if (!empty($allowedFormats)) {
            $mime   = $driver->getMime();
            $extMap = [
                'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
                'image/png'  => 'png', 'image/gif' => 'gif',
                'image/webp' => 'webp', 'image/bmp' => 'bmp', 'image/x-bmp' => 'bmp',
            ];
            $ext = $extMap[$mime] ?? null;
            if ($ext === null || !in_array($ext, $allowedFormats, true)) {
                throw ImageException::processFailed('open', sprintf(
                    '不支持图片格式: %s（允许: %s）',
                    $mime, implode(', ', $allowedFormats)
                ));
            }
        }
    }

    /**
     * IP 黑名单检查
     *
     * @param string[] $blacklistedIps 黑名单 IP 列表
     * @throws ImageException
     */
    private function checkIpBlacklist(array $blacklistedIps): void
    {
        if (empty($blacklistedIps)) {
            return;
        }

        // 使用框架请求实例（如果有），回退到超全局变量
        $clientIp = $this->request ? $this->request->ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        foreach ($blacklistedIps as $ip) {
            if ($this->ipMatches($clientIp, $ip)) {
                throw new ImageException(
                    'IP 在黑名单中，访问被拒绝',
                    ErrorCode::REFERER_DENIED->value,
                    ['client_ip' => $clientIp, 'rule' => $ip]
                );
            }
        }
    }

    /**
     * Referer 防盗链检查
     *
     * @param array<string, mixed> $rules Referer 规则配置
     * @throws ImageException
     */
    private function checkReferer(array $rules): void
    {
        // 使用框架请求实例，回退到超全局变量
        $referer = $this->request ? ($this->request->server('HTTP_REFERER', '')) : ($_SERVER['HTTP_REFERER'] ?? '');

        // 允许空 Referer
        if ($referer === '' && !empty($rules['allow_empty'])) {
            return;
        }

        // 检查黑名单（优先）
        if (!empty($rules['blacklist'])) {
            foreach ((array) $rules['blacklist'] as $pattern) {
                if ($this->refererMatches($referer, $pattern)) {
                    throw ImageException::refererDenied();
                }
            }
        }

        // 检查白名单
        if (!empty($rules['whitelist'])) {
            foreach ((array) $rules['whitelist'] as $pattern) {
                if ($this->refererMatches($referer, $pattern)) {
                    return; // 白名单匹配，放行
                }
            }
            throw ImageException::refererDenied();
        }
    }

    /**
     * URL 签名验证
     *
     * 委托 {@see Signer} 完成规范化与校验：
     *   - 签名串包含图片 path，杜绝跨图片重放；
     *   - 参数排序，避免顺序敏感；
     *   - timeout > 0 时强制要求时间戳，并同时校验「过期」与「未来时间」。
     *
     * @param array<string, mixed> $sigConfig 签名配置
     * @throws ImageException 验签不通过时
     */
    private function checkSignature(array $sigConfig): void
    {
        $secret = (string) ($sigConfig['secret'] ?? '');
        if ($secret === '') {
            return;
        }

        // 获取请求参数（使用框架请求回退到超全局）
        $queryParams = $this->request ? $this->request->get() : $_GET;
        if (!is_array($queryParams)) {
            $queryParams = [];
        }

        $signer = new Signer($secret, (int) ($sigConfig['timeout'] ?? 3600));

        if (!$signer->verify($this->signaturePath(), $queryParams)) {
            throw ImageException::signInvalid();
        }
    }

    /**
     * 获取参与签名的图片路径
     *
     * 约定为请求 pathinfo（含路由前缀，如 `/image/upload/foo.jpg`）。
     * 无请求上下文（CLI / 队列）时回退为源文件路径，保证同一套验签逻辑可复用。
     */
    private function signaturePath(): string
    {
        if ($this->request !== null) {
            return Signer::normalizePath((string) $this->request->pathinfo());
        }

        return Signer::normalizePath($this->sourcePath);
    }

    /**
     * 原图保护检查（P2）
     *
     * 当 origin_protect.enabled = true 时，禁止直接访问原始文件。
     * 必须通过 style 参数或 URL 参数指定处理操作，否则拒绝访问。
     */
    private function checkOriginProtect(): void
    {
        if (!empty($this->operations)) {
            return;
        }

        throw new ImageException(
            '原图保护已启用，禁止直接访问原始图片。请使用 style 参数或图片处理参数。',
            ErrorCode::REFERER_DENIED->value,
            ['source_path' => $this->sourcePath, 'remote_url' => $this->remoteUrl]
        );
    }

    // -----------------------------------------------------------------
    // 缓存支持（内部）
    // -----------------------------------------------------------------

    /**
     * 生成缓存键
     *
     * 基于源路径 + 操作链 + 关键配置项生成唯一 MD5 键。
     *
     * @return string
     */
    private function getCacheKey(): string
    {
        // 以源身份（远程 URL 优先）作为缓存标识，避免不同图源串缓存
        $seed = $this->sourceIdentity() . '|';

        // 源文件指纹：原图被替换（mtime/大小变化）后旧缓存自动失效
        $seed .= $this->sourceFingerprint() . '|';

        foreach ($this->operations as [$processor, $params]) {
            $seed .= $processor->getName() . ':' . json_encode($params) . ';';
        }

        // 驱动不同则输出字节不同（gd / imagick），必须纳入缓存标识
        $seed .= strtolower((string) ($this->config['driver'] ?? 'gd')) . '|';
        // 用「可提前推断」的输出格式，保证缓存键在 execute() 之前即可计算
        $seed .= ($this->resolveOutputFormat() ?? 'auto') . '|' . ($this->config['default_quality'] ?? 85);

        $prefix = $this->config['cache']['prefix'] ?? 'wise_image:';
        return $prefix . md5($seed);
    }

    /**
     * 源文件指纹（filemtime + filesize）
     *
     * 用于让「原图被替换」后旧缓存自动失效。远程图片使用其本地副本的指纹，
     * 因此 RemoteFetcher 重新下载后同样会触发缓存刷新。
     */
    private function sourceFingerprint(): string
    {
        $path = $this->sourcePath;

        if ($path === '' || !is_file($path)) {
            return 'nofile';
        }

        $mtime = @filemtime($path);
        $size  = @filesize($path);

        return ($mtime === false ? 0 : $mtime) . 'x' . ($size === false ? 0 : $size);
    }

    /**
     * 路径约束校验
     *
     * `security.file_root` 非空时，图片真实路径必须落在该目录内，
     * 防止 `../../../etc/xxx.png` 之类的目录穿越访问。
     *
     * @throws ImageException 越权访问时
     */
    private function assertPathAllowed(string $path): void
    {
        $root = (string) ($this->config['security']['file_root'] ?? '');
        if ($root === '') {
            return; // 未配置即不限制，保持向后兼容
        }

        $realPath = realpath($path);
        $realRoot = realpath($root);

        if ($realPath === false || $realRoot === false) {
            throw ImageException::fileAccessDenied($path, $root);
        }

        $normalizedPath = self::normalizeFsPath($realPath);
        $normalizedRoot = rtrim(self::normalizeFsPath($realRoot), '/');
        $prefix         = $normalizedRoot === '' ? '/' : $normalizedRoot . '/';

        if ($normalizedPath !== $normalizedRoot && !str_starts_with($normalizedPath, $prefix)) {
            throw ImageException::fileAccessDenied($path, $root);
        }
    }

    /**
     * 文件系统路径规范化（统一分隔符；Windows 下大小写不敏感）
     */
    private static function normalizeFsPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    /**
     * 尝试读取缓存结果
     *
     * @return string|null 缓存的图片数据，未命中返回 null
     */
    private function getCachedResult(): ?string
    {
        $cacheConfig = $this->config['cache'] ?? [];

        // 文件缓存驱动
        if (($cacheConfig['driver'] ?? 'file') === 'file') {
            $cachePath = $this->getCacheFilePath();

            if ($cachePath !== null && file_exists($cachePath)) {
                $ttl = (int) ($cacheConfig['ttl'] ?? 2592000);
                if ((time() - filemtime($cachePath)) < $ttl) {
                    $content = @file_get_contents($cachePath);
                    return $content !== false ? $content : null;
                }
            }

            return null;
        }

        // Redis 缓存驱动（依赖 think\cache）
        if (($cacheConfig['driver'] ?? '') === 'redis') {
            try {
                if (class_exists(\think\facade\Cache::class)) {
                    $key = $this->getCacheKey();
                    $result = \think\facade\Cache::get($key);
                    return is_string($result) ? $result : null;
                }
            } catch (\Throwable $e) {
                // 缓存不可用时静默降级
            }
        }

        return null;
    }

    /**
     * 写入缓存
     *
     * @param string $content 图片二进制数据
     */
    private function setCachedResult(string $content): void
    {
        $cacheConfig = $this->config['cache'] ?? [];

        // 文件缓存驱动
        if (($cacheConfig['driver'] ?? 'file') === 'file') {
            $cachePath = $this->getCacheFilePath();
            if ($cachePath !== null) {
                $dir = dirname($cachePath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @file_put_contents($cachePath, $content);
            }
            return;
        }

        // Redis 缓存驱动
        if (($cacheConfig['driver'] ?? '') === 'redis') {
            try {
                if (class_exists(\think\facade\Cache::class)) {
                    $key = $this->getCacheKey();
                    $ttl = (int) ($cacheConfig['ttl'] ?? 2592000);
                    \think\facade\Cache::set($key, $content, $ttl);
                }
            } catch (\Throwable $e) {
                // 缓存不可用时静默降级
            }
        }
    }

    /**
     * 获取文件缓存的完整路径
     *
     * @return string|null
     */
    private function getCacheFilePath(): ?string
    {
        $key = $this->getCacheKey();
        // 去掉前缀后的 MD5 部分
        $hash = str_replace($this->config['cache']['prefix'] ?? 'wise_image:', '', $key);

        $cacheDir = CachePathResolver::resolve(
            isset($this->config['cache']['path']) ? (string) $this->config['cache']['path'] : null,
            'runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'cache'
        );

        // 按 hash 前 2 位分片避免单目录文件过多
        $subDir = substr($hash, 0, 2);

        return $cacheDir . DIRECTORY_SEPARATOR . $subDir . DIRECTORY_SEPARATOR . $hash;
    }

    // -----------------------------------------------------------------
    // 内部工具方法
    // -----------------------------------------------------------------

    /**
     * 源身份标识
     *
     * 远程图片以原始 URL 为准（本地副本路径随缓存策略变化），本地图片用文件路径。
     * 用于缓存键、事件载荷与调试信息，避免同一图源产生多个身份。
     */
    private function sourceIdentity(): string
    {
        return $this->remoteUrl !== '' ? $this->remoteUrl : $this->sourcePath;
    }

    /**
     * 将操作链转为监听器友好的结构
     *
     * @return array<int, array{operation: string, params: array<string, mixed>}>
     */
    private function describeOperations(): array
    {
        $described = [];

        foreach ($this->operations as [$processor, $params]) {
            $described[] = [
                'operation' => $processor->getName(),
                'params'    => $params,
            ];
        }

        return $described;
    }

    /**
     * 派发领域事件
     *
     * - 已注入派发器（测试 / 自定义总线）时优先使用；
     * - 否则回退 ThinkPHP Event 总线；
     * - 监听器异常与总线缺失均静默降级，不影响图片处理主流程。
     */
    private function dispatchEvent(object $event): void
    {
        if ($this->eventDispatcher !== null) {
            try {
                ($this->eventDispatcher)($event);
            } catch (\Throwable $e) {
                // 监听器异常不阻断图片处理
            }
            return;
        }

        if (!class_exists(\think\facade\Event::class)) {
            return;
        }

        try {
            \think\facade\Event::trigger($event);
        } catch (\Throwable $e) {
            // 事件总线不可用时静默降级
        }
    }

    /**
     * 根据操作名解析并返回 Processor 实例（单例缓存）
     *
     * @param string $operationName 操作名
     * @return ProcessorInterface
     * @throws ImageException 当操作名未注册时
     */
    private function resolveProcessor(string $operationName): ProcessorInterface
    {
        if (isset(self::$processorCache[$operationName])) {
            return self::$processorCache[$operationName];
        }

        // 1) 扩展点优先：允许插件新增操作（sharpen / qrcode / label …）或替换内置实现
        if (
            class_exists(ExtPoint::class)
            && class_exists(ExtPoints::class)
            && ExtPoint::has(ExtPoints::IMAGE_PROCESSOR, $operationName)
        ) {
            // 扩展注册的处理器不进入静态缓存，避免「注册覆盖内置」被旧实例挡住
            return $this->instantiateProcessor(
                ExtPoint::get(ExtPoints::IMAGE_PROCESSOR, $operationName),
                $operationName
            );
        }

        // 2) 内置兜底
        $className = self::OPERATION_MAP[$operationName] ?? null;

        if ($className === null) {
            throw ImageException::paramInvalid(sprintf(
                '未知的图片操作：%s，支持：%s',
                $operationName,
                implode(' / ', $this->availableOperations())
            ));
        }

        $instance = new $className();
        self::$processorCache[$operationName] = $instance;

        return $instance;
    }

    /**
     * 实例化扩展点注册的处理器
     *
     * @param string|callable|null $concrete 类名或工厂闭包
     * @throws ImageException 实现无效时
     */
    private function instantiateProcessor(mixed $concrete, string $operationName): ProcessorInterface
    {
        if (is_string($concrete) && class_exists($concrete)) {
            $instance = new $concrete();
        } elseif (is_callable($concrete)) {
            $instance = $concrete();
        } else {
            throw ImageException::paramInvalid("已注册的图片操作 {$operationName} 实现无效");
        }

        if (!$instance instanceof ProcessorInterface) {
            throw ImageException::paramInvalid(
                "已注册的图片操作 {$operationName} 未实现 ProcessorInterface"
            );
        }

        return $instance;
    }

    /**
     * 检查客户端 IP 是否匹配规则（支持 CIDR 简写）
     *
     * @param string $clientIp 客户端 IP
     * @param string $rule     匹配规则（精确 IP 或 CIDR）
     * @return bool
     */
    private function ipMatches(string $clientIp, string $rule): bool
    {
        // 精确匹配
        if ($clientIp === $rule) {
            return true;
        }

        // CIDR 匹配
        if (str_contains($rule, '/')) {
            $parts = explode('/', $rule);
            $subnet  = $parts[0];
            $mask    = (int) ($parts[1] ?? 32);

            if ($mask < 0 || $mask > 32) {
                return false;
            }

            $clientLong = ip2long($clientIp);
            $subnetLong = ip2long($subnet);

            if ($clientLong === false || $subnetLong === false) {
                return false;
            }

            // 使用无符号整数掩码避免 -1 << 32 的未定义行为
            // mask=0 时 netmask=0（匹配所有IP），mask=32 时 netmask=0xFFFFFFFF
            $netmask = ($mask === 0) ? 0 : (~0 << (32 - $mask));

            // 在 64 位 PHP 上 ip2long 返回的有符号值会被符号扩展到 64 位，
            // 需用 0xFFFFFFFF 掩码截断只取低 32 位后再与 netmask 比较
            $clientMasked = ($clientLong & 0xFFFFFFFF) & $netmask;
            $subnetMasked = ($subnetLong & 0xFFFFFFFF) & $netmask;

            return $clientMasked === $subnetMasked;
        }

        return false;
    }

    /**
     * 检查 Referer 是否匹配规则（支持通配符 *）
     *
     * @param string $referer Referer URL
     * @param string $pattern 匹配模式
     * @return bool
     */
    private function refererMatches(string $referer, string $pattern): bool
    {
        // 将通配符 * 转为正则
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
        return preg_match($regex, $referer) === 1;
    }

    /**
     * 重置内部状态
     */
    private function reset(): void
    {
        $this->destroy();
        $this->sourcePath   = '';
        $this->remoteUrl    = '';
        $this->targetFormat = null;
        $this->operations   = [];
        $this->executed     = false;
        $this->isMake       = false;
        $this->makeWidth    = 0;
        $this->makeHeight   = 0;
    }
}
