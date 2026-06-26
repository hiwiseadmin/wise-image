<?php

declare(strict_types=1);

namespace wise\image\facade;

use wise\image\ImageManager;

/**
 * Image Facade — 静态代理 ImageManager
 *
 * 提供简洁的静态入口，无需每次 new ImageManager。
 *
 * 用法示例：
 *   Image::setConfig(['driver' => 'gd']);
 *   Image::open('/path/to/img.jpg')
 *       ->resize(100, 100)
 *       ->save('/path/to/output.jpg');
 *
 *   Image::make(800, 600)
 *       ->blur(5)
 *       ->save('/path/to/blurred.jpg');
 */
class Image
{
    /** @var ImageManager|null 单例 ImageManager 实例 */
    private static ?ImageManager $instance = null;

    /** @var array<string, mixed> 全局配置 */
    private static array $config = [];

    /**
     * 设置全局配置
     *
     * @param array<string, mixed> $config 完整配置数组
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
        self::$instance = null; // 配置变更时重置实例
    }

    /**
     * 打开已有图片
     *
     * @param string $path 图片文件路径
     * @return ImageManager
     */
    public static function open(string $path): ImageManager
    {
        return self::manager()->open($path);
    }

    /**
     * 创建空白画布
     *
     * @param int $width  画布宽度
     * @param int $height 画布高度
     * @return ImageManager
     */
    public static function make(int $width, int $height): ImageManager
    {
        return self::manager()->make($width, $height);
    }

    /**
     * 获取 ImageManager 单例
     *
     * @return ImageManager
     */
    private static function manager(): ImageManager
    {
        if (self::$instance === null) {
            self::$instance = new ImageManager(self::$config);
        }
        return self::$instance;
    }

    /**
     * 魔术方法 — 将未定义的静态调用委托给 ImageManager 实例
     *
     * 支持如 Image::parseUrlParams(...), Image::style(...) 等调用。
     *
     * @param string                $method    方法名
     * @param array<int, mixed>     $arguments 方法参数
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::manager()->{$method}(...$arguments);
    }
}
