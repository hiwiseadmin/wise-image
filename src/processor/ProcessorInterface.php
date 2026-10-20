<?php

declare(strict_types=1);

namespace wise\image\processor;

use wise\image\driver\DriverInterface;
use wise\image\ImageManager;

/**
 * 图片处理器接口
 *
 * 每个处理器负责解析一组参数并调用驱动执行对应的图片操作。
 * 处理器之间相互独立，通过 ImageManager 按顺序执行。
 */
interface ProcessorInterface
{
    /**
     * 执行图片处理操作
     *
     * @param ImageManager   $manager 图片管理器上下文（用于获取配置、缓存等）
     * @param DriverInterface $driver  当前图片驱动实例
     * @param array<string, mixed> $params 处理器参数
     * @throws \wise\image\exception\ImageException 处理失败时抛出
     */
    public function process(ImageManager $manager, DriverInterface $driver, array $params): void;

    /**
     * 获取处理器名称（与 URL 参数中的操作名对应）
     *
     * @return string
     */
    public function getName(): string;
}
