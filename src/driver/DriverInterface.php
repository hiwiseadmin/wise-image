<?php

declare(strict_types=1);

namespace wise\image\driver;

/**
 * 图片驱动抽象接口
 *
 * 定义所有图片驱动必须实现的方法签名。
 * GD 和 Imagick 驱动均须实现此接口，确保上层调用的一致性与可替换性。
 */
interface DriverInterface
{
    /**
     * 打开已存在的图片文件
     *
     * @param string $path 图片文件路径
     * @throws \wise\image\exception\ImageException 文件不存在或格式不支持时抛出
     */
    public function open(string $path): void;

    /**
     * 创建空白画布
     *
     * @param int $width  画布宽度（像素）
     * @param int $height 画布高度（像素）
     */
    public function create(int $width, int $height): void;

    /**
     * 调整图片尺寸
     *
     * @param int    $width  目标宽度
     * @param int    $height 目标高度
     * @param string $mode   缩放模式：lfit | fill | fixed | ratio
     */
    public function resize(int $width, int $height, string $mode): void;

    /**
     * 裁剪图片
     *
     * @param int $width  裁剪宽度
     * @param int $height 裁剪高度
     * @param int $x      裁剪起始 X 坐标
     * @param int $y      裁剪起始 Y 坐标
     */
    public function crop(int $width, int $height, int $x, int $y): void;

    /**
     * 添加图片水印
     *
     * @param string $path     水印图片路径
     * @param string $position 水印位置（nw|n|ne|w|c|e|sw|s|se）
     * @param int    $opacity  透明度 (0-100)
     */
    public function watermark(string $path, string $position, int $opacity): void;

    /**
     * 添加文字水印
     *
     * @param string               $text    水印文字
     * @param array<string, mixed> $options 文字选项（font/size/color/position/opacity/angle）
     */
    public function textWatermark(string $text, array $options): void;

    /**
     * 旋转图片
     *
     * @param float $angle 旋转角度（顺时针为正）
     */
    public function rotate(float $angle): void;

    /**
     * 模糊图片
     *
     * @param int $radius 模糊半径
     */
    public function blur(int $radius): void;

    /**
     * 调整亮度
     *
     * @param int $level 亮度级别 (-100 ~ 100)
     */
    public function brightness(int $level): void;

    /**
     * 调整对比度
     *
     * @param int $level 对比度级别 (-100 ~ 100)
     */
    public function contrast(int $level): void;

    /**
     * 设置输出质量
     *
     * @param int $quality 质量 (0-100)
     */
    public function setQuality(int $quality): void;

    /**
     * 获取图片宽度
     *
     * @return int
     */
    public function getWidth(): int;

    /**
     * 获取图片高度
     *
     * @return int
     */
    public function getHeight(): int;

    /**
     * 获取图片 MIME 类型
     *
     * @return string
     */
    public function getMime(): string;

    /**
     * 获取图片详细信息
     *
     * @return array<string, mixed>
     */
    public function getInfo(): array;

    /**
     * 保存图片到文件
     *
     * @param string       $path   保存路径
     * @param string|null  $format 输出格式（null 保持原格式）
     * @return bool
     */
    public function save(string $path, ?string $format = null): bool;

    /**
     * 输出图片为二进制字符串
     *
     * @param string|null $format 输出格式（null 保持原格式）
     * @return string
     */
    public function output(?string $format = null): string;

    /**
     * 销毁图片资源
     */
    public function destroy(): void;
}
