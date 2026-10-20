<?php

declare(strict_types=1);

namespace wise\image\driver;

use wise\image\exception\ImageException;
use wise\image\remote\RemoteFetcher;

/**
 * Imagick 图片驱动
 *
 * 基于 PHP Imagick 扩展实现所有 DriverInterface 方法。
 * 支持 Imagick 原生支持的所有图片格式。
 * 
 */
class ImagickDriver implements DriverInterface
{
    /**
     * Imagick 扩展类名（字符串形式）
     *
     * 统一以字符串类名 + constant() 运行时解析的方式引用 Imagick 系列符号，
     * 兼容 IDE / 静态分析环境未加载 imagick 扩展（intelephense P1009）的情况。
     */
    private const IMAGICK_CLASS = 'Imagick';

    /** @var string ImagickPixel 类名（字符串形式，同上） */
    private const IMAGICK_PIXEL_CLASS = 'ImagickPixel';

    /** @var string ImagickDraw 类名（字符串形式，同上） */
    private const IMAGICK_DRAW_CLASS = 'ImagickDraw';

    /** 支持的通用格式映射 */
    private const FORMAT_TO_MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
    ];

    /** @var \Imagick|null Imagick 图片对象（不声明类型 hint 以兼容 IDE 无扩展环境） */
    private $image = null;

    /** @var string */
    private string $mime = 'image/png';

    /** @var int */
    private int $quality = 85;

    /** @var int */
    private int $srcWidth = 0;

    /** @var int */
    private int $srcHeight = 0;

    /** @var bool 资源限制是否已设置（全局一次性） */
    private static bool $resourceLimitsSet = false;

    // -----------------------------------------------------------------
    // 生命周期
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $remoteConfig 远程抓取配置（open() 收到 URL 时委托 RemoteFetcher）
     * @throws ImageException 当 Imagick 扩展不可用时
     */
    public function __construct(private readonly array $remoteConfig = [])
    {
        if (!extension_loaded('imagick')) {
            throw ImageException::driverNotFound('imagick');
        }

        // 全局资源限制仅设置一次，避免重复调用覆盖其他代码的配置
        if (!self::$resourceLimitsSet) {
            self::applyResourceLimits();
            self::$resourceLimitsSet = true;
        }
    }

    public function __destruct()
    {
        $this->destroy();
    }

    // -----------------------------------------------------------------
    // 打开与创建
    // -----------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    public function open(string $path): void
    {
        $this->destroy();

        // 远程 URL 统一委托 RemoteFetcher 抓取（含 SSRF / 体积 / 超时 / 类型校验），
        // 不再交给 Imagick 内部下载，避免绕过 wise-image 的安全层。
        if (preg_match('#^https?://#i', $path) === 1) {
            $path = (new RemoteFetcher($this->remoteConfig))->fetch($path);
        }

        if (!is_file($path)) {
            throw ImageException::fileNotFound($path);
        }

        try {
            // 通过字符串类名实例化，兼容 IDE / 静态分析环境未加载 imagick 扩展的情况
            $imagickClass = self::IMAGICK_CLASS;
            $imagick      = new $imagickClass($path);
        } catch (\Exception $e) {
            throw ImageException::processFailed('open', $e->getMessage());
        }

        $this->image = $imagick;
        $this->mime  = $this->image->getImageMimeType();

        $this->srcWidth  = $this->image->getImageWidth();
        $this->srcHeight = $this->image->getImageHeight();
    }

    /**
     * {@inheritDoc}
     */
    public function create(int $width, int $height): void
    {
        $this->destroy();

        try {
            $imagickClass = self::IMAGICK_CLASS;
            $pixelClass   = self::IMAGICK_PIXEL_CLASS;

            /** @var \Imagick $imagick */
            $imagick = new $imagickClass();
            $imagick->newImage($width, $height, new $pixelClass('none'));
            $imagick->setImageFormat('png');
            $imagick->setImageType(self::imagickConst('IMGTYPE_TRUECOLORMATTE'));
        } catch (\Exception $e) {
            // ImagickException 继承 \Exception，此处统一捕获
            throw ImageException::processFailed('create', $e->getMessage());
        }

        $this->image     = $imagick;
        $this->mime      = 'image/png';
        $this->srcWidth  = $width;
        $this->srcHeight = $height;
    }

    // -----------------------------------------------------------------
    // 几何变换
    // -----------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    public function resize(int $width, int $height, string $mode): void
    {
        $this->ensureImageLoaded();

        // 模式合法性先校验，避免 paramInvalid 被下面的统一 catch 转成 processFailed
        if (!in_array($mode, ['lfit', 'fill', 'fixed', 'ratio'], true)) {
            throw ImageException::paramInvalid("不支持的缩放模式：{$mode}");
        }

        $filter = self::imagickConst('FILTER_LANCZOS');

        try {
            switch ($mode) {
                case 'lfit':
                    $this->image->resizeImage(
                        $width, $height,
                        $filter, 1,
                        true
                    );
                    break;

                case 'fill':
                    $this->image->resizeImage(
                        $width, $height,
                        $filter, 1,
                        true
                    );
                    $this->image->cropThumbnailImage($width, $height);
                    break;

                case 'fixed':
                    $this->image->resizeImage(
                        $width, $height,
                        $filter, 1,
                        false
                    );
                    break;

                case 'ratio':
                    $ratio = $width / 100.0;
                    $dstW  = (int) round($this->srcWidth * $ratio);
                    $dstH  = (int) round($this->srcHeight * $ratio);
                    $this->image->resizeImage(
                        $dstW, $dstH,
                        $filter, 1,
                        false
                    );
                    break;
            }
        } catch (\Exception $e) {
            // ImagickException 继承 \Exception，此处统一捕获
            throw ImageException::processFailed('resize', $e->getMessage());
        }

        $this->syncDimensions();
    }

    /**
     * {@inheritDoc}
     */
    public function crop(int $width, int $height, int $x, int $y): void
    {
        $this->ensureImageLoaded();

        try {
            $this->image->cropImage(
                $width, $height,
                $x, $y
            );
            $this->image->setImagePage($width, $height, 0, 0);
        } catch (\Exception $e) {
            // ImagickException 继承 \Exception，此处统一捕获
            throw ImageException::processFailed('crop', $e->getMessage());
        }

        $this->syncDimensions();
    }

    // -----------------------------------------------------------------
    // 水印
    // -----------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    public function watermark(string $path, string $position, int $opacity): void
    {
        $this->ensureImageLoaded();

        if (!is_file($path)) {
            throw ImageException::fileNotFound($path);
        }

        try {
            $imagickClass = self::IMAGICK_CLASS;

            /** @var \Imagick $watermark */
            $watermark = new $imagickClass($path);
        } catch (\Exception $e) {
            throw ImageException::processFailed('watermark', "无法加载水印图片：{$e->getMessage()}");
        }

        try {
            // 设置透明度
            if ($opacity < 100) {
                $watermark->evaluateImage(
                    self::imagickConst('EVALUATE_MULTIPLY'),
                    $opacity / 100,
                    self::imagickConst('CHANNEL_ALPHA')
                );
            }

            $wmW = $watermark->getImageWidth();
            $wmH = $watermark->getImageHeight();

            [$posX, $posY] = $this->calcPosition(
                $this->srcWidth, $this->srcHeight,
                $wmW, $wmH,
                $position
            );

            $this->image->compositeImage(
                $watermark,
                self::imagickConst('COMPOSITE_OVER'),
                $posX, $posY
            );

            $watermark->clear();
            $watermark->destroy();
        } catch (\Exception $e) {
            if (isset($watermark)) {
                $watermark->clear();
            }
            throw ImageException::processFailed('watermark', $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function textWatermark(string $text, array $options): void
    {
        $this->ensureImageLoaded();

        $font       = $options['font'] ?? null;
        $size       = (int) ($options['size'] ?? 20);
        $colorHex   = $options['color'] ?? '#000000';
        $position   = $options['position'] ?? 'se';
        $opacity    = (float) (($options['opacity'] ?? 50) / 100);
        $angle      = (float) ($options['angle'] ?? 0.0);

        try {
            $drawClass  = self::IMAGICK_DRAW_CLASS;
            $pixelClass = self::IMAGICK_PIXEL_CLASS;

            /** @var \ImagickDraw $draw */
            $draw  = new $drawClass();

            // 颜色
            $pixel = new $pixelClass($colorHex);
            $draw->setFillColor($pixel);
            $draw->setFillOpacity($opacity);

            // 字体
            if ($font !== null && is_string($font) && is_file($font)) {
                $draw->setFont($font);
            }

            $draw->setFontSize($size);

            // 获取文字度量
            $metrics = $this->image->queryFontMetrics($draw, $text);
            $textW   = (int) ($metrics['textWidth'] ?? 0);
            $textH   = (int) ($metrics['textHeight'] ?? 0);

            [$posX, $posY] = $this->calcPosition(
                $this->srcWidth, $this->srcHeight,
                $textW, $textH,
                $position
            );

            // 补偿基线
            $ascender = (int) ($metrics['ascender'] ?? 0);
            $posY    += $ascender;

            if ($angle != 0.0) {
                $draw->rotate($angle);
            }

            $this->image->annotateImage($draw, $posX, $posY, $angle, $text);

            $draw->clear();
            $draw->destroy();
        } catch (\Exception $e) {
            // ImagickException / ImagickDrawException 均继承 \Exception
            throw ImageException::processFailed('textWatermark', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // 滤镜与效果
    // -----------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    public function rotate(float $angle): void
    {
        $this->ensureImageLoaded();

        try {
            $pixelClass = self::IMAGICK_PIXEL_CLASS;
            $this->image->rotateImage(new $pixelClass('none'), $angle);
        } catch (\Exception $e) {
            // ImagickException 继承 \Exception，此处统一捕获
            throw ImageException::processFailed('rotate', $e->getMessage());
        }

        $this->syncDimensions();
    }

    /**
     * {@inheritDoc}
     */
    public function blur(int $radius): void
    {
        $this->ensureImageLoaded();

        $radius = max(1, min($radius, 100));

        try {
            $this->image->blurImage($radius * 2, $radius);
        } catch (\Exception $e) {
            throw ImageException::processFailed('blur', $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function brightness(int $level): void
    {
        $this->ensureImageLoaded();

        $level = max(-100, min(100, $level));

        try {
            $this->image->brightnessContrastImage($level, 0);
        } catch (\Exception $e) {
            throw ImageException::processFailed('brightness', $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function contrast(int $level): void
    {
        $this->ensureImageLoaded();

        $level = max(-100, min(100, $level));

        try {
            $this->image->brightnessContrastImage(0, $level);
        } catch (\Exception $e) {
            throw ImageException::processFailed('contrast', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // 质量与输出
    // -----------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    public function setQuality(int $quality): void
    {
        $this->quality = max(0, min(100, $quality));

        if ($this->image !== null) {
            $this->image->setImageCompressionQuality($this->quality);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getWidth(): int
    {
        return $this->srcWidth;
    }

    /**
     * {@inheritDoc}
     */
    public function getHeight(): int
    {
        return $this->srcHeight;
    }

    /**
     * {@inheritDoc}
     */
    public function getMime(): string
    {
        return $this->mime;
    }

    /**
     * {@inheritDoc}
     */
    public function getInfo(): array
    {
        return [
            'width'  => $this->srcWidth,
            'height' => $this->srcHeight,
            'mime'   => $this->mime,
            'format' => $this->mimeToFormat($this->mime),
            'driver' => 'imagick',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function save(string $path, ?string $format = null): bool
    {
        $this->ensureImageLoaded();

        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                throw ImageException::processFailed('save', "无法创建目录：{$dir}");
            }
        }

        // 保存原始格式，避免 setImageFormat 永久修改对象状态
        $originalFormat = null;
        if ($format !== null) {
            $originalFormat = $this->image->getImageFormat();
            $this->image->setImageFormat($this->normalizeFormat($format));
        }

        $this->image->setImageCompressionQuality($this->quality);

        try {
            $result = $this->image->writeImage($path);
        } catch (\Exception $e) {
            throw ImageException::processFailed('save', $e->getMessage());
        } finally {
            // 恢复原始格式，避免影响后续操作（如 output 再次调用）
            if ($originalFormat !== null) {
                $this->image->setImageFormat($originalFormat);
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function output(?string $format = null): string
    {
        $this->ensureImageLoaded();

        // 保存原始格式，避免 setImageFormat 永久修改对象状态
        $originalFormat = null;
        if ($format !== null) {
            $originalFormat = $this->image->getImageFormat();
            $this->image->setImageFormat($this->normalizeFormat($format));
        }

        $this->image->setImageCompressionQuality($this->quality);

        try {
            $blob = $this->image->getImageBlob();
        } catch (\Exception $e) {
            throw ImageException::processFailed('output', $e->getMessage());
        } finally {
            // 恢复原始格式，避免影响后续操作
            if ($originalFormat !== null) {
                $this->image->setImageFormat($originalFormat);
            }
        }

        return $blob;
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(): void
    {
        if ($this->image !== null) {
            $this->image->clear();
            $this->image->destroy();
            $this->image = null;
        }
        $this->srcWidth  = 0;
        $this->srcHeight = 0;
    }

    /**
     * 设置 Imagick 全局资源限制（仅首次实例化时调用一次）
     *
     * 防止恶意大图导致内存溢出，限制值可通过配置覆盖。
     */
    private static function applyResourceLimits(): void
    {
        $imagickClass = self::IMAGICK_CLASS;

        try {
            // 通过 call_user_func + constant() 运行时解析，兼容 IDE 无 imagick 扩展环境
            call_user_func(
                [$imagickClass, 'setResourceLimit'],
                self::imagickConst('RESOURCETYPE_MEMORY'),
                256 * 1024 * 1024
            );

            $mapConst = $imagickClass . '::RESOURCETYPE_MAP';
            if (defined($mapConst)) {
                call_user_func(
                    [$imagickClass, 'setResourceLimit'],
                    self::imagickConst('RESOURCETYPE_MAP'),
                    512 * 1024 * 1024
                );
            }

            $areaConst = $imagickClass . '::RESOURCETYPE_AREA';
            if (defined($areaConst)) {
                call_user_func(
                    [$imagickClass, 'setResourceLimit'],
                    self::imagickConst('RESOURCETYPE_AREA'),
                    8192 * 8192
                );
            }
        } catch (\Exception|\Error) {
            // 某些版本不支持 setResourceLimit 或常量未定义，静默降级
        }
    }

    // -----------------------------------------------------------------
    // 内部方法
    // -----------------------------------------------------------------

    /**
     * 确保图片已加载
     *
     * @throws ImageException
     */
    private function ensureImageLoaded(): void
    {
        if ($this->image === null) {
            throw ImageException::driverNotReady('Imagick 驱动未加载任何图片');
        }
    }

    /**
     * 同步尺寸信息
     */
    private function syncDimensions(): void
    {
        $this->srcWidth  = $this->image->getImageWidth();
        $this->srcHeight = $this->image->getImageHeight();
    }

    /**
     * 运行时读取 Imagick 类常量
     *
     * 用 constant() 延迟解析，避免静态分析环境（未加载 imagick 扩展）报 "Undefined type 'Imagick'"；
     * 结果做进程内缓存，避免每次调用都走一次常量查找。
     *
     * @param string $name 常量名（如 FILTER_LANCZOS、COMPOSITE_OVER）
     */
    private static function imagickConst(string $name): int
    {
        /** @var array<string, int> $cache */
        static $cache = [];

        return $cache[$name] ??= (int) constant(self::IMAGICK_CLASS . '::' . $name);
    }

    /**
     * 标准化格式名（jpg → jpeg）
     */
    private function normalizeFormat(string $format): string
    {
        $format = strtolower($format);
        return ($format === 'jpg') ? 'jpeg' : $format;
    }

    /**
     * MIME 类型转简短格式名
     */
    private function mimeToFormat(string $mime): string
    {
        return array_flip(self::FORMAT_TO_MIME)[$mime] ?? 'png';
    }

    /**
     * 根据 9 宫格位置计算起始坐标
     *
     * @return array{int, int}
     */
    private function calcPosition(
        int $bgW, int $bgH,
        int $elemW, int $elemH,
        string $position
    ): array {
        $padding = 10;

        return match ($position) {
            'nw' => [$padding, $padding],
            'n'  => [(int) (($bgW - $elemW) / 2), $padding],
            'ne' => [$bgW - $elemW - $padding, $padding],
            'w'  => [$padding, (int) (($bgH - $elemH) / 2)],
            'e'  => [$bgW - $elemW - $padding, (int) (($bgH - $elemH) / 2)],
            'sw' => [$padding, $bgH - $elemH - $padding],
            's'  => [(int) (($bgW - $elemW) / 2), $bgH - $elemH - $padding],
            'se' => [$bgW - $elemW - $padding, $bgH - $elemH - $padding],
            default => [(int) (($bgW - $elemW) / 2), (int) (($bgH - $elemH) / 2)], // 'c'
        };
    }
}
