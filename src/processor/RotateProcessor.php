<?php

declare(strict_types=1);

namespace wise\image\processor;

use wise\image\driver\DriverInterface;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * 旋转处理器
 *
 * 按指定角度旋转图片（顺时针为正）。
 *
 * 参数：
 * - v : 旋转角度（支持 v_90 格式），范围 -360 ~ 360
 */
class RotateProcessor implements ProcessorInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(ImageManager $manager, DriverInterface $driver, array $params): void
    {
        $angle = isset($params['v']) ? (float) $params['v'] : 0.0;

        // clamp 到 -360 ~ 360
        $angle = max(-360.0, min(360.0, $angle));

        $driver->rotate($angle);
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'rotate';
    }
}
