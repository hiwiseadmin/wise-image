<?php

declare(strict_types=1);

namespace wise\image\driver;

use wise\image\exception\ImageException;

/**
 * GD 图片驱动
 *
 * 基于 PHP GD 扩展实现所有 DriverInterface 方法。
 * 支持 JPEG、PNG、GIF、WebP、BMP 格式。
 * TIFF 不受 GD 支持。
 *
 * @property \GdImage|resource|null $image GD 图片资源句柄
 * @property string                 $mime  当前图片 MIME 类型
 * @property int                    $quality 输出质量 (0-100)
 */
class GdDriver implements DriverInterface
{
    /** 支持的 MIME 类型映射 */
    private const MIME_TO_FORMAT = [
        'image/jpeg' => 'jpeg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/bmp'  => 'bmp',
    ];

    /** 格式到 MIME 的反向映射 */
    private const FORMAT_TO_MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
    ];

    /** @var \GdImage|resource|null */
    private $image = null;

    /** @var string */
    private string $mime = 'image/png';

    /** @var int */
    private int $quality = 85;

    /** @var int 原始宽度 */
    private int $srcWidth = 0;

    /** @var int 原始高度 */
    private int $srcHeight = 0;

    // -----------------------------------------------------------------
    // 生命周期
    // -----------------------------------------------------------------

    /**
     * @throws ImageException 当 GD 扩展不可用时
     */
    public function __construct()
    {
        if (!extension_loaded('gd')) {
            throw ImageException::driverNotFound('gd');
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

        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            throw ImageException::fileNotFound($path);
        }

        $mimeType = $this->detectMimeType($realPath);
        if (!isset(self::MIME_TO_FORMAT[$mimeType])) {
            throw ImageException::invalidFormat($mimeType);
        }

        $this->mime = $mimeType;

        $function = 'imagecreatefrom' . self::MIME_TO_FORMAT[$mimeType];
        if (!function_exists($function)) {
            throw ImageException::invalidFormat(self::MIME_TO_FORMAT[$mimeType]);
        }

        /** @var \GdImage|false $img */
        $img = @$function($realPath);
        if ($img === false) {
            $error = error_get_last();
            throw ImageException::processFailed(
                'open',
                $error['message'] ?? '无法加载图片文件'
            );
        }

        $this->image = $img;

        $width  = imagesx($this->image);
        $height = imagesy($this->image);

        if ($width === false || $height === false) {
            throw ImageException::processFailed('open', '无法获取图片尺寸');
        }

        $this->srcWidth  = $width;
        $this->srcHeight = $height;
    }

    /**
     * {@inheritDoc}
     */
    public function create(int $width, int $height): void
    {
        $this->destroy();

        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            throw ImageException::processFailed('create', '无法创建画布');
        }

        // 保留透明通道
        imagealphablending($img, false);
        imagesavealpha($img, true);

        // 填充透明背景
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefill($img, 0, 0, $transparent);
        }

        $this->image     = $img;
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

        $srcW = $this->srcWidth;
        $srcH = $this->srcHeight;

        switch ($mode) {
            case 'lfit':
                [$dstW, $dstH] = $this->calcLfit($srcW, $srcH, $width, $height);
                $this->doResample($dstW, $dstH, 0, 0, $srcW, $srcH, 0, 0, $dstW, $dstH);
                break;

            case 'fill':
                [$dstW, $dstH, $cropX, $cropY] = $this->calcFill($srcW, $srcH, $width, $height);
                $this->doResample($width, $height, $cropX, $cropY, $width, $height, 0, 0, $width, $height);
                break;

            case 'fixed':
                $this->doResample($width, $height, 0, 0, $srcW, $srcH, 0, 0, $width, $height);
                break;

            case 'ratio':
                $ratio = $width / 100.0;
                $dstW  = (int) round($srcW * $ratio);
                $dstH  = (int) round($srcH * $ratio);
                $this->doResample($dstW, $dstH, 0, 0, $srcW, $srcH, 0, 0, $dstW, $dstH);
                break;

            default:
                throw ImageException::paramInvalid("不支持的缩放模式：{$mode}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function crop(int $width, int $height, int $x, int $y): void
    {
        $this->ensureImageLoaded();

        // 确保裁剪区域不超出图片边界
        $x = max(0, min($x, $this->srcWidth - 1));
        $y = max(0, min($y, $this->srcHeight - 1));
        $cropW = min($width, $this->srcWidth - $x);
        $cropH = min($height, $this->srcHeight - $y);

        $dst = imagecreatetruecolor($width, $height);
        if ($dst === false) {
            throw ImageException::processFailed('crop', '无法创建裁剪画布');
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefill($dst, 0, 0, $transparent);
        }

        $result = imagecopyresampled(
            $dst, $this->image,
            0, 0, $x, $y,
            $width, $height,
            $cropW, $cropH
        );

        if ($result === false) {
            imagedestroy($dst);
            throw ImageException::processFailed('crop', '裁剪操作失败');
        }

        imagedestroy($this->image);
        $this->image     = $dst;
        $this->srcWidth  = $width;
        $this->srcHeight = $height;
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

        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            throw ImageException::fileNotFound($path);
        }

        $wmInfo = getimagesize($realPath);
        if ($wmInfo === false) {
            throw ImageException::processFailed('watermark', '无法读取水印图片信息');
        }

        $wmMime = $wmInfo['mime'] ?? '';
        $format = self::MIME_TO_FORMAT[$wmMime] ?? null;
        if ($format === null) {
            throw ImageException::invalidFormat($wmMime);
        }

        $createFn = 'imagecreatefrom' . $format;
        /** @var \GdImage|false $watermark */
        $watermark = @$createFn($realPath);
        if ($watermark === false) {
            $error = error_get_last();
            throw ImageException::processFailed(
                'watermark',
                $error['message'] ?? '无法加载水印图片'
            );
        }

        $wmW = imagesx($watermark);
        $wmH = imagesy($watermark);

        [$posX, $posY] = $this->calcPosition(
            $this->srcWidth, $this->srcHeight,
            $wmW, $wmH,
            $position
        );

        $result = imagecopymerge(
            $this->image, $watermark,
            $posX, $posY, 0, 0,
            $wmW, $wmH,
            $opacity
        );

        imagedestroy($watermark);

        if ($result === false) {
            throw ImageException::processFailed('watermark', '水印合成失败');
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
        $opacity    = (int) ($options['opacity'] ?? 50);
        $angle      = (float) ($options['angle'] ?? 0.0);

        $rgba = $this->hexToRgba($colorHex);

        // 计算文字尺寸
        if ($font !== null && is_string($font) && is_file($font)) {
            $bbox = imagettfbbox($size, $angle, $font, $text);
            if ($bbox === false) {
                // 降级到内置字体
                $font     = null;
                $textW    = imagefontwidth(5) * strlen($text);
                $textH    = imagefontheight(5);
                $baseline = 0;
            } else {
                $textW    = abs($bbox[4] - $bbox[0]);
                $textH    = abs($bbox[5] - $bbox[1]);
                $baseline = $bbox[1];
            }
        } else {
            $font     = null;
            $textW    = imagefontwidth(5) * strlen($text);
            $textH    = imagefontheight(5);
            $baseline = 0;
        }

        // 定位
        [$posX, $posY] = $this->calcPosition(
            $this->srcWidth, $this->srcHeight,
            $textW, $textH,
            $position
        );

        $posY -= $baseline;

        $colorAlloc = $this->allocateColorWithAlpha($rgba[0], $rgba[1], $rgba[2], (int) ((127 - ($opacity / 100 * 127))));

        if ($font !== null) {
            $result = @imagettftext(
                $this->image, $size, $angle,
                $posX, $posY, $colorAlloc, $font, $text
            );
            if ($result === false) {
                $error = error_get_last();
                throw ImageException::processFailed(
                    'textWatermark',
                    $error['message'] ?? 'TrueType 文字水印写入失败'
                );
            }
        } else {
            $result = imagestring(
                $this->image, 5,
                $posX, $posY, $text,
                $colorAlloc
            );
            if ($result === false) {
                throw ImageException::processFailed('textWatermark', '内置字体文字水印写入失败');
            }
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

        $transColor = imagecolorallocatealpha($this->image, 0, 0, 0, 127);
        if ($transColor === false) {
            $transColor = 0;
        }

        $rotated = imagerotate($this->image, -$angle, $transColor);
        if ($rotated === false) {
            throw ImageException::processFailed('rotate', '旋转操作失败');
        }

        imagedestroy($this->image);
        $this->image = $rotated;

        $newW = imagesx($this->image);
        $newH = imagesy($this->image);
        if ($newW !== false && $newH !== false) {
            $this->srcWidth  = $newW;
            $this->srcHeight = $newH;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function blur(int $radius): void
    {
        $this->ensureImageLoaded();

        $radius = max(1, min($radius, 100));

        // GD 没有原生高斯模糊，使用 SMOOTH 多轮降级 + 卷积矩阵增强
        $passes = min($radius, 5);
        for ($i = 0; $i < $passes; $i++) {
            $result = imagefilter($this->image, IMG_FILTER_SMOOTH, 0);
            if ($result === false) {
                throw ImageException::processFailed('blur', "第 {$i} 轮平滑滤波失败");
            }
        }

        // 额外使用卷积矩阵增加模糊强度
        if ($radius > 3) {
            $extra = $radius - 5;
            if ($extra > 0) {
                $matrix = [
                    [1.0, 2.0, 1.0],
                    [2.0, 4.0, 2.0],
                    [1.0, 2.0, 1.0],
                ];
                $divisor = 16.0;
                $imageconvolution = $this->image;
                @imageconvolution($imageconvolution, $matrix, $divisor, 0);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function brightness(int $level): void
    {
        $this->ensureImageLoaded();

        $level = max(-100, min(100, $level));
        $result = imagefilter($this->image, IMG_FILTER_BRIGHTNESS, $level);
        if ($result === false) {
            throw ImageException::processFailed('brightness', '亮度调整失败');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function contrast(int $level): void
    {
        $this->ensureImageLoaded();

        $level = max(-100, min(100, $level));
        $result = imagefilter($this->image, IMG_FILTER_CONTRAST, -$level);
        if ($result === false) {
            throw ImageException::processFailed('contrast', '对比度调整失败');
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
            'format' => self::MIME_TO_FORMAT[$this->mime] ?? 'unknown',
            'driver' => 'gd',
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

        $format  = $format ?? self::MIME_TO_FORMAT[$this->mime] ?? 'png';
        $format  = strtolower($format);

        // jpeg 统一
        if ($format === 'jpg') {
            $format = 'jpeg';
        }

        $result = $this->outputInternal($format);
        if ($result === null) {
            return false;
        }

        $written = @file_put_contents($path, $result);
        if ($written === false) {
            throw ImageException::processFailed('save', "写入文件失败：{$path}");
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function output(?string $format = null): string
    {
        $this->ensureImageLoaded();

        $format = $format ?? self::MIME_TO_FORMAT[$this->mime] ?? 'png';
        $format = strtolower($format);
        if ($format === 'jpg') {
            $format = 'jpeg';
        }

        $result = $this->outputInternal($format);
        if ($result === null) {
            throw ImageException::processFailed('output', "无法以 {$format} 格式输出图片");
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(): void
    {
        if ($this->image !== null && $this->image instanceof \GdImage) {
            imagedestroy($this->image);
        }
        $this->image     = null;
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
            throw ImageException::driverNotReady('GD 驱动未加载任何图片');
        }
    }

    /**
     * 检测文件 MIME 类型
     */
    private function detectMimeType(string $path): string
    {
        $info = getimagesize($path);
        if ($info === false) {
            throw ImageException::invalidFormat('无法识别图片格式');
        }
        return $info['mime'] ?? 'application/octet-stream';
    }

    /**
     * 执行重采样操作
     */
    private function doResample(
        int $dstW, int $dstH,
        int $srcX, int $srcY,
        int $srcW, int $srcH,
        int $dstX, int $dstY,
        int $actualDstW, int $actualDstH
    ): void {
        $dst = imagecreatetruecolor($actualDstW, $actualDstH);
        if ($dst === false) {
            throw ImageException::processFailed('resize', '无法创建目标画布');
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefill($dst, 0, 0, $transparent);
        }

        $result = imagecopyresampled(
            $dst, $this->image,
            $dstX, $dstY, $srcX, $srcY,
            $dstW, $dstH,
            $srcW, $srcH
        );

        if ($result === false) {
            imagedestroy($dst);
            throw ImageException::processFailed('resize', '重采样失败');
        }

        imagedestroy($this->image);
        $this->image     = $dst;
        $this->srcWidth  = $actualDstW;
        $this->srcHeight = $actualDstH;
    }

    /**
     * 计算 lfit（等比缩放，不超过宽高）模式的目标尺寸
     *
     * @return array{int, int}
     */
    private function calcLfit(int $srcW, int $srcH, int $maxW, int $maxH): array
    {
        $ratio = min($maxW / $srcW, $maxH / $srcH, 1.0);
        return [
            (int) round($srcW * $ratio),
            (int) round($srcH * $ratio),
        ];
    }

    /**
     * 计算 fill（等比缩放后居中裁剪）模式的参数
     *
     * @return array{int, int, int, int}
     */
    private function calcFill(int $srcW, int $srcH, int $targetW, int $targetH): array
    {
        $ratio = max($targetW / $srcW, $targetH / $srcH);
        $scaledW = (int) round($srcW * $ratio);
        $scaledH = (int) round($srcH * $ratio);
        $cropX   = (int) round(($scaledW - $targetW) / 2);
        $cropY   = (int) round(($scaledH - $targetH) / 2);

        return [$scaledW, $scaledH, $cropX, $cropY];
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

    /**
     * 十六进制颜色转 RGBA 数组
     *
     * @return array{int, int, int}
     */
    private function hexToRgba(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * 分配带透明度的颜色
     */
    private function allocateColorWithAlpha(int $r, int $g, int $b, int $alpha): int
    {
        $color = imagecolorallocatealpha($this->image, $r, $g, $b, $alpha);
        if ($color === false) {
            // 降级：不透明
            $color = imagecolorallocate($this->image, $r, $g, $b);
            if ($color === false) {
                $color = 0;
            }
        }
        return $color;
    }

    /**
     * 内部输出方法，不抛异常返回 null
     */
    private function outputInternal(string $format): ?string
    {
        ob_start();

        switch ($format) {
            case 'jpeg':
                $ok = imagejpeg($this->image, null, $this->quality);
                break;
            case 'png':
                // PNG 质量映射：0-9 压缩级别（0 无损）
                $pngQuality = (int) round(9 - ($this->quality / 100 * 9));
                $ok = imagepng($this->image, null, $pngQuality);
                break;
            case 'gif':
                $ok = imagegif($this->image);
                break;
            case 'webp':
                $ok = imagewebp($this->image, null, $this->quality);
                break;
            case 'bmp':
                if (function_exists('imagebmp')) {
                    $ok = imagebmp($this->image, null, true);
                } else {
                    ob_get_clean();
                    throw ImageException::invalidFormat('bmp（需要 PHP 8.1+ 且 GD 含 BMP 支持）');
                }
                break;
            default:
                ob_get_clean();
                throw ImageException::invalidFormat($format);
        }

        if ($ok === false) {
            ob_get_clean();
            return null;
        }

        return ob_get_clean() ?: null;
    }
}
