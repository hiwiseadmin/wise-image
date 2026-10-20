<?php

declare(strict_types=1);

namespace wise\image\processor;

use wise\image\driver\DriverInterface;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * 模糊处理器
 *
 * 对图片应用高斯模糊效果。
 *
 * 参数：
 * - r : 模糊半径（支持 r_5 格式），默认 3，范围 1 ~ 100
 */
class BlurProcessor implements ProcessorInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(ImageManager $manager, DriverInterface $driver, array $params): void
    {
        $radius = isset($params['r']) ? (int) $params['r'] : 3;

        if ($radius < 1 || $radius > 100) {
            throw ImageException::paramInvalid('模糊半径必须在 1 ~ 100 之间');
        }

        $driver->blur($radius);
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'blur';
    }
}
