<?php

declare(strict_types=1);

namespace wise\image\command;

use think\console\Command;
use wise\image\cache\CacheManager;

/**
 * 缓存治理命令基类
 *
 * 统一从容器读取 `wise-image` 配置并构造 {@see CacheManager}，
 * 并提供字节数/时间的人性化格式化。
 */
abstract class AbstractCacheCommand extends Command
{
    /**
     * 读取 wise-image 完整配置
     *
     * @return array<string, mixed>
     */
    protected function imageConfig(): array
    {
        if (!function_exists('app')) {
            return [];
        }

        try {
            return (array) app()->config->get('wise-image', []);
        } catch (\Throwable $e) {
            // 容器不可用时降级为空配置（走默认路径）
            return [];
        }
    }

    /**
     * 构造缓存治理器
     */
    protected function cacheManager(): CacheManager
    {
        $config = $this->imageConfig();

        return new CacheManager(
            (array) ($config['cache'] ?? []),
            (array) ($config['remote'] ?? [])
        );
    }

    /**
     * 人性化字节数
     */
    protected function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return $index === 0
            ? $bytes . ' B'
            : number_format($value, 2) . ' ' . $units[$index];
    }

    /**
     * 格式化时间戳
     */
    protected function formatTime(?int $timestamp): string
    {
        return $timestamp === null ? '-' : date('Y-m-d H:i:s', $timestamp);
    }
}
