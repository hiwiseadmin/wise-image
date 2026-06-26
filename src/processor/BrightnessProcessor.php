<?php

declare(strict_types=1);

namespace wise\image\processor;

use wise\image\driver\DriverInterface;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * 亮度处理器
 *
 * 调整图片亮度。
 *
 * 参数：
 * - v : 亮度级别（支持 v_50 / v_-30 格式），默认 0，范围 -100 ~ 100
 */
class BrightnessProcessor implements ProcessorInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(ImageManager $manager, DriverInterface $driver, array $params): void
    {
        $level = isset($params['v']) ? (int) $params['v'] : 0;

        if ($level < -100 || $level > 100) {
            throw ImageException::paramInvalid('亮度级别必须在 -100 ~ 100 之间');
        }

        $driver->brightness($level);
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'brightness';
    }
}
