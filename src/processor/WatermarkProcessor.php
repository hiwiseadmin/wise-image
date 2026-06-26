<?php

declare(strict_types=1);

namespace wise\image\processor;

use wise\image\driver\DriverInterface;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * 水印处理器
 *
 * 支持图片水印和文字水印两种模式。
 *
 * 图片水印参数：
 * - image   : 水印图片路径（必填，触发图片水印模式）
 * - g       : 位置（nw|n|ne|w|c|e|sw|s|se），默认 se
 * - opacity : 透明度 (0-100)，默认 50
 *
 * 文字水印参数：
 * - text    : 水印文字内容（必填，触发文字水印模式）
 * - font    : 字体文件路径
 * - size    : 字号，默认 20
 * - color   : 颜色（十六进制），默认 #FFFFFF
 * - g       : 位置，默认 se
 * - opacity : 透明度 (0-100)，默认 50
 * - angle   : 旋转角度，默认 0
 */
class WatermarkProcessor implements ProcessorInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(ImageManager $manager, DriverInterface $driver, array $params): void
    {
        // 图片水印模式
        if (isset($params['image'])) {
            $this->processImageWatermark($driver, $params);
            return;
        }

        // 文字水印模式
        if (isset($params['text'])) {
            $this->processTextWatermark($driver, $params);
            return;
        }

        throw ImageException::paramInvalid('水印处理器需要 image 或 text 参数');
    }

    /**
     * 图片水印
     *
     * @param DriverInterface      $driver
     * @param array<string, mixed> $params
     */
    private function processImageWatermark(DriverInterface $driver, array $params): void
    {
        $imagePath = (string) $params['image'];
        if ($imagePath === '') {
            throw ImageException::paramInvalid('水印图片路径不能为空');
        }

        $position = (string) ($params['g'] ?? 'se');
        $opacity  = isset($params['opacity']) ? (int) $params['opacity'] : 50;
        $opacity  = max(0, min(100, $opacity));

        $driver->watermark($imagePath, $position, $opacity);
    }

    /**
     * 文字水印
     *
     * @param DriverInterface      $driver
     * @param array<string, mixed> $params
     */
    private function processTextWatermark(DriverInterface $driver, array $params): void
    {
        $text = (string) $params['text'];
        if ($text === '') {
            throw ImageException::paramInvalid('水印文字内容不能为空');
        }

        $options = [
            'font'     => $params['font'] ?? null,
            'size'     => (int) ($params['size'] ?? 20),
            'color'    => (string) ($params['color'] ?? '#FFFFFF'),
            'position' => (string) ($params['g'] ?? 'se'),
            'opacity'  => (int) ($params['opacity'] ?? 50),
            'angle'    => (float) ($params['angle'] ?? 0.0),
        ];

        $options['opacity'] = max(0, min(100, $options['opacity']));

        $driver->textWatermark($text, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'watermark';
    }
}
