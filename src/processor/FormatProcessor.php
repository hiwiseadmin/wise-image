<?php

declare(strict_types=1);

namespace wise\image\processor;

use wise\image\driver\DriverInterface;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * 格式转换处理器
 *
 * 指定输出图片格式，支持自动 WebP 检测。
 *
 * 参数：
 * - f : 目标格式（jpg/jpeg/png/gif/webp/bmp）
 *
 * 自动 WebP：
 * - 当 $params 中无 f 参数且配置 `auto_webp = true` 时，
 *   检查 Accept 请求头是否包含 `image/webp`。
 *
 * 注意：此处理器的 setFormat 调用由 ImageManager 在管道执行后统一处理。
 * 当前通过 $manager 上下文标记目标格式。
 */
class FormatProcessor implements ProcessorInterface
{
    /** 支持的输出格式 */
    private const ALLOWED_FORMATS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /**
     * {@inheritDoc}
     */
    public function process(ImageManager $manager, DriverInterface $driver, array $params): void
    {
        // 显式指定格式
        if (isset($params['f'])) {
            $format = strtolower((string) $params['f']);
            if (!in_array($format, self::ALLOWED_FORMATS, true)) {
                throw ImageException::invalidFormat($format);
            }
            $manager->setTargetFormat($format);
            return;
        }

        // 自动 WebP 检测（复用 predict，保证「执行期」与「缓存键预测」行为一致）
        $predicted = self::predict($manager, $params);
        if ($predicted !== null) {
            $manager->setTargetFormat($predicted);
        }
    }

    /**
     * 预测最终输出格式（不依赖执行状态）
     *
     * `ImageManager::getCacheKey()` 需要在 `execute()` **之前** 算出缓存键，
     * 而输出格式原本只在执行期由 process() 写入。这里提供唯一一份判定逻辑，
     * 供执行期与缓存键预测共用，避免「键算错导致永久缓存未命中」。
     *
     * @param ImageManager         $manager 管理器上下文（读取 auto_webp 与 Accept 头）
     * @param array<string, mixed> $params  处理器参数
     * @return string|null 预测格式；null 表示沿用源格式
     */
    public static function predict(ImageManager $manager, array $params): ?string
    {
        if (isset($params['f'])) {
            return strtolower((string) $params['f']);
        }

        if (empty($manager->getConfig()['auto_webp'])) {
            return null;
        }

        return str_contains($manager->getClientAcceptHeader(), 'image/webp') ? 'webp' : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'format';
    }
}
