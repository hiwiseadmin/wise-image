<?php

declare(strict_types=1);

namespace wise\image\processor;

use wise\image\driver\DriverInterface;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * 裁剪处理器
 *
 * 支持按像素精确裁剪和基于重力的 9 宫格自动定位。
 *
 * 参数：
 * - w : 裁剪宽度
 * - h : 裁剪高度
 * - x : 起始 X 坐标（与 g 互斥，优先级高于 g）
 * - y : 起始 Y 坐标
 * - g : 重力/位置（nw|n|ne|w|c|e|sw|s|se），未指定 x/y 时自动计算
 */
class CropProcessor implements ProcessorInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(ImageManager $manager, DriverInterface $driver, array $params): void
    {
        $w = isset($params['w']) ? (int) $params['w'] : 0;
        $h = isset($params['h']) ? (int) $params['h'] : 0;

        if ($w <= 0 || $h <= 0) {
            throw ImageException::paramInvalid('裁剪宽度和高度必须大于 0');
        }

        // x / y 显式指定时使用
        if (isset($params['x']) || isset($params['y'])) {
            $x = (int) ($params['x'] ?? 0);
            $y = (int) ($params['y'] ?? 0);
            $driver->crop($w, $h, $x, $y);
            return;
        }

        // 根据重力 gravity 自动计算（委托 PositionHelper 统一 9 宫格定位，兼容 center/southeast 等长名）
        $gravity = \wise\image\util\PositionHelper::normalize((string) ($params['g'] ?? 'c'));
        $srcW    = $driver->getWidth();
        $srcH    = $driver->getHeight();

        [$x, $y] = \wise\image\util\PositionHelper::calc($srcW, $srcH, $w, $h, $gravity);

        $x = max(0, $x);
        $y = max(0, $y);

        $driver->crop($w, $h, $x, $y);
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'crop';
    }
}
