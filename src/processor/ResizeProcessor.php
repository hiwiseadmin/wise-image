<?php

declare(strict_types=1);

namespace wise\image\processor;

use wise\image\driver\DriverInterface;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * 缩放处理器
 *
 * 支持四种缩放模式：
 * - lfit :  等比缩放，长边不超过目标尺寸（支持只给宽度或高度）
 * - fill :  等比缩放后居中裁剪至目标尺寸
 * - fixed:  强制拉伸至目标尺寸
 * - ratio:  按百分比缩放
 *
 * 参数：
 * - w : 宽度（px，ratio 模式为百分比）
 * - h : 高度（px，ratio 模式忽略）
 * - m : 模式，默认 lfit
 *
 * 模式别名（便于链式 API 使用直觉命名）：
 * - contain / fit  → lfit
 * - cover / crop   → fill
 * - stretch / exact → fixed
 * - percent / scale → ratio
 */
class ResizeProcessor implements ProcessorInterface
{
    /** 支持的模式 */
    private const ALLOWED_MODES = ['lfit', 'fill', 'fixed', 'ratio'];

    /**
     * {@inheritDoc}
     */
    public function process(ImageManager $manager, DriverInterface $driver, array $params): void
    {
        $w    = isset($params['w']) ? (int) $params['w'] : 0;
        $h    = isset($params['h']) ? (int) $params['h'] : 0;
        $mode = $this->normalizeMode((string) ($params['m'] ?? $params['mode'] ?? 'lfit'));

        if (!in_array($mode, self::ALLOWED_MODES, true)) {
            throw ImageException::paramInvalid(
                "不支持的缩放模式：{$mode}，允许：" . implode(' / ', self::ALLOWED_MODES)
            );
        }

        // ratio：w 为百分比，忽略 h
        if ($mode === 'ratio') {
            if ($w <= 0) {
                throw ImageException::paramInvalid('缩放百分比必须大于 0');
            }
            $driver->resize($w, $h, $mode);
            return;
        }

        if ($w <= 0 && $h <= 0) {
            throw ImageException::paramInvalid('缩放宽度和高度至少需要一个大于 0');
        }

        if ($mode === 'lfit') {
            // 单边等比缩放：只给一个维度时，按原图比例补全另一个
            if ($w <= 0) {
                $w = $this->scaleByHeight($driver, $h);
            } elseif ($h <= 0) {
                $h = $this->scaleByWidth($driver, $w);
            }
        } elseif ($w <= 0 || $h <= 0) {
            throw ImageException::paramInvalid("缩放模式 {$mode} 需要同时指定宽度和高度");
        }

        $driver->resize($w, $h, $mode);
    }

    /**
     * 归一化缩放模式（支持常见别名）
     */
    private function normalizeMode(string $mode): string
    {
        return match (strtolower(trim($mode))) {
            'contain', 'fit', 'inside' => 'lfit',
            'cover', 'crop', 'fill'    => 'fill',
            'fixed', 'exact', 'stretch' => 'fixed',
            'ratio', 'percent', 'scale' => 'ratio',
            default                    => strtolower(trim($mode)),
        };
    }

    /**
     * 按指定宽度等比推算高度
     *
     * @throws ImageException 原图尺寸不可用时
     */
    private function scaleByWidth(DriverInterface $driver, int $width): int
    {
        $srcW = $driver->getWidth();
        $srcH = $driver->getHeight();

        if ($srcW <= 0 || $srcH <= 0) {
            throw ImageException::paramInvalid('无法获取原图尺寸，请同时指定宽度和高度');
        }

        return max(1, (int) round($srcH * $width / $srcW));
    }

    /**
     * 按指定高度等比推算宽度
     *
     * @throws ImageException 原图尺寸不可用时
     */
    private function scaleByHeight(DriverInterface $driver, int $height): int
    {
        $srcW = $driver->getWidth();
        $srcH = $driver->getHeight();

        if ($srcW <= 0 || $srcH <= 0) {
            throw ImageException::paramInvalid('无法获取原图尺寸，请同时指定宽度和高度');
        }

        return max(1, (int) round($srcW * $height / $srcH));
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'resize';
    }
}
