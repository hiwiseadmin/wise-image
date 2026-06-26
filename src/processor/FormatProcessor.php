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
            // 将目标格式写入 manager 上下文（后续在 ImageManager 中统一执行）
            // 此处通过 setQuality 等已有接口不足以传递 format，
            // 使用 manager 的 setTargetFormat（T03 由 ImageManager 提供）
            if (method_exists($manager, 'setTargetFormat')) {
                $manager->setTargetFormat($format);
            }
            return;
        }

        // 自动 WebP 检测
        if ($this->shouldAutoWebP($manager)) {
            if (method_exists($manager, 'setTargetFormat')) {
                $manager->setTargetFormat('webp');
            }
        }
    }

    /**
     * 判断是否应自动输出 WebP
     */
    private function shouldAutoWebP(ImageManager $manager): bool
    {
        // 检查配置
        $config = [];
        if (method_exists($manager, 'getConfig')) {
            $config = $manager->getConfig();
        }

        if (empty($config['auto_webp'])) {
            return false;
        }

        // 检查 Accept 请求头
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'image/webp');
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'format';
    }
}
