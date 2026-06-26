<?php

declare(strict_types=1);

namespace wise\image\processor;

use wise\image\driver\DriverInterface;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * 质量处理器
 *
 * 控制输出图片的压缩质量。
 *
 * 参数：
 * - q : 质量值 (0-100)，默认取配置 `default_quality`
 */
class QualityProcessor implements ProcessorInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(ImageManager $manager, DriverInterface $driver, array $params): void
    {
        if (isset($params['q'])) {
            $quality = (int) $params['q'];
        } else {
            // 从配置取默认质量
            $config = [];
            if (method_exists($manager, 'getConfig')) {
                $config = $manager->getConfig();
            }
            $quality = (int) ($config['default_quality'] ?? 85);
        }

        if ($quality < 0 || $quality > 100) {
            throw ImageException::paramInvalid(
                "质量值必须在 0-100 之间，当前值：{$quality}"
            );
        }

        $driver->setQuality($quality);
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'quality';
    }
}
