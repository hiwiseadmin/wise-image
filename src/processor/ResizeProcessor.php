<?php

declare(strict_types=1);

namespace wise\image\processor;

use wise\image\driver\DriverInterface;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * 缩放处理器
 *
 * 支持四种缩放模式：
 * - lfit :  等比缩放，长边不超过目标尺寸
 * - fill :  等比缩放后居中裁剪至目标尺寸
 * - fixed:  强制拉伸至目标尺寸
 * - ratio:  按百分比缩放
 *
 * 参数：
 * - w : 宽度（px，ratio 模式为百分比）
 * - h : 高度（px，ratio 模式忽略）
 * - m : 模式，默认 lfit
 */
class ResizeProcessor implements ProcessorInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(ImageManager $manager, DriverInterface $driver, array $params): void
    {
        $w    = isset($params['w']) ? (int) $params['w'] : 0;
        $h    = isset($params['h']) ? (int) $params['h'] : 0;
        $mode = (string) ($params['m'] ?? $params['mode'] ?? 'lfit');

        // 校验参数
        if ($w <= 0) {
            throw ImageException::paramInvalid('缩放宽度必须大于 0');
        }

        // 非 ratio 模式需要高度
        if ($mode !== 'ratio' && $h <= 0) {
            throw ImageException::paramInvalid('缩放高度必须大于 0');
        }

        $allowedModes = ['lfit', 'fill', 'fixed', 'ratio'];
        if (!in_array($mode, $allowedModes, true)) {
            throw ImageException::paramInvalid(
                "不支持的缩放模式：{$mode}，允许：lfit / fill / fixed / ratio"
            );
        }

        $driver->resize($w, $h, $mode);
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'resize';
    }
}
