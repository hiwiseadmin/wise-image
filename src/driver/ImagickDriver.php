<?php

declare(strict_types=1);

namespace wise\image\driver;

use wise\image\exception\ImageException;

/**
 * Imagick 图片驱动
 *
 * 基于 PHP Imagick 扩展实现所有 DriverInterface 方法。
 * 支持 Imagick 原生支持的所有图片格式。
 */
class ImagickDriver implements DriverInterface
{
    /** 支持的通用格式映射 */
    private const FORMAT_TO_MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
    ];

    /** @var \Imagick|null */
    private ?\Imagick $image = null;

    /** @var string */
    private string $mime = 'image/png';

    /** @var int */
    private int $quality = 85;

    /** @var int */
    private int $srcWidth = 0;

    /** @var int */
    private int $srcHeight = 0;

    // -----------------------------------------------------------------
    // 生命周期
    // -----------------------------------------------------------------

    /**
     * @throws ImageException 当 Imagick 扩展不可用时
     */
    public function __construct()
    {
        if (!extension_loaded('imagick')) {
            throw ImageException::driverNotFound('imagick');
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

        if (!is_file($path) && !filter_var($path, FILTER_VALIDATE_URL)) {
            throw ImageException::fileNotFound($path);
        }

        // 设置资源限制防止大图导致内存溢出
        try {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_AREA, 8192 * 8192);
        } catch (\ImagickException $e) {
            // 某些版本不支持 setResourceLimit，忽略
        }

        try {
            $imagick = new \Imagick($path);
        } catch (\ImagickException $e) {
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
            $imagick = new \Imagick();
            $imagick->newImage($width, $height, new \ImagickPixel('none'));
            $imagick->setImageFormat('png');
            $imagick->setImageType(\Imagick::IMGTYPE_TRUECOLORMATTE);
        } catch (\ImagickException $e) {
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

        try {
            switch ($mode) {
                case 'lfit':
                    $this->image->resizeImage(
                        $width, $height,
                        \Imagick::FILTER_LANCZOS, 1,
                        true
                    );
                    break;

                case 'fill':
                    $this->image->resizeImage(
                        $width, $height,
                        \Imagick::FILTER_LANCZOS, 1,
                        true
                    );
                    $this->image->cropThumbnailImage($width, $height);
                    break;

                case 'fixed':
                    $this->image->resizeImage(
                        $width, $height,
                        \Imagick::FILTER_LANCZOS, 1,
                        false
                    );
                    break;

                case 'ratio':
                    $ratio = $width / 100.0;
                    $dstW  = (int) round($this->srcWidth * $ratio);
                    $dstH  = (int) round($this->srcHeight * $ratio);
                    $this->image->resizeImage(
                        $dstW, $dstH,
                        \Imagick::FILTER_LANCZOS, 1,
                        false
                    );
                    break;

                default:
                    throw ImageException::paramInvalid("不支持的缩放模式：{$mode}");
            }
        } catch (\ImagickException $e) {
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
        } catch (\ImagickException $e) {
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
            $watermark = new \Imagick($path);
        } catch (\ImagickException $e) {
            throw ImageException::processFailed('watermark', "无法加载水印图片：{$e->getMessage()}");
        }

        try {
            // 设置透明度
            if ($opacity < 100) {
                $watermark->evaluateImage(
                    \Imagick::EVALUATE_MULTIPLY,
                    $opacity / 100,
                    \Imagick::CHANNEL_ALPHA
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
                \Imagick::COMPOSITE_OVER,
                $posX, $posY
            );

            $watermark->clear();
            $watermark->destroy();
        } catch (\ImagickException $e) {
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
            $draw = new \ImagickDraw();

            // 颜色
            $pixel = new \ImagickPixel($colorHex);
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
        } catch (\ImagickException $e) {
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
            $this->image->rotateImage(new \ImagickPixel('none'), $angle);
        } catch (\ImagickException $e) {
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
        } catch (\ImagickException $e) {
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
        } catch (\ImagickException $e) {
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
        } catch (\ImagickException $e) {
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

        if ($format !== null) {
            $this->image->setImageFormat($this->normalizeFormat($format));
        }

        $this->image->setImageCompressionQuality($this->quality);

        try {
            $result = $this->image->writeImage($path);
        } catch (\ImagickException $e) {
            throw ImageException::processFailed('save', $e->getMessage());
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function output(?string $format = null): string
    {
        $this->ensureImageLoaded();

        if ($format !== null) {
            $this->image->setImageFormat($this->normalizeFormat($format));
        }

        $this->image->setImageCompressionQuality($this->quality);

        try {
            $blob = $this->image->getImageBlob();
        } catch (\ImagickException $e) {
            throw ImageException::processFailed('output', $e->getMessage());
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
