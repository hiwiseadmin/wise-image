<?php

declare(strict_types=1);

namespace wise\image\util;

/**
 * 9 宫格位置计算工具
 *
 * 提取 GdDriver 和 ImagickDriver 中重复的 calcPosition 逻辑，
 * 统一维护水印/文字水印的定位算法。
 */
class PositionHelper
{
    /**
     * 默认内边距（像素）
     */
    private const PADDING = 10;

    /** 九宫格短标识 */
    private const SHORT_POSITIONS = ['nw', 'n', 'ne', 'w', 'c', 'e', 'sw', 's', 'se'];

    /** 位置别名 → 九宫格短标识（覆盖长英文名与 pos_ 前缀写法） */
    private const ALIASES = [
        'northwest'     => 'nw',
        'north'         => 'n',
        'northeast'     => 'ne',
        'west'          => 'w',
        'center'        => 'c',
        'centre'        => 'c',
        'middle'        => 'c',
        'east'          => 'e',
        'southwest'     => 'sw',
        'south'         => 's',
        'southeast'     => 'se',
        'top'           => 'n',
        'bottom'        => 's',
        'left'          => 'w',
        'right'         => 'e',
        'topleft'       => 'nw',
        'topright'      => 'ne',
        'bottomleft'    => 'sw',
        'bottomright'   => 'se',
    ];

    /**
     * 归一化位置标识
     *
     * 兼容多种写法，统一为 `nw|n|ne|w|c|e|sw|s|se`：
     *   - 短标识：`se`
     *   - 长英文名：`southeast` / `bottom-right` / `bottom_right`
     *   - OSS 风格前缀：`pos_southeast`
     *
     * 无法识别时回退为居中 `c`。
     *
     * @param string $position 原始位置标识
     * @return string 九宫格短标识
     */
    public static function normalize(string $position): string
    {
        $key = strtolower(trim($position));

        // 兼容 pos_southeast / pos-se
        $key = (string) preg_replace('/^pos[_\-\s]?/', '', $key);

        // 统一分隔符后先查长名别名
        $key = str_replace(['_', ' '], '-', $key);
        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }

        // 去掉分隔符再查（bottom-right / bottomright 都覆盖）
        $compact = str_replace('-', '', $key);
        if (isset(self::ALIASES[$compact])) {
            return self::ALIASES[$compact];
        }

        return in_array($compact, self::SHORT_POSITIONS, true) ? $compact : 'c';
    }

    /**
     * 计算元素在背景中的定位坐标
     *
     * @param int    $bgW   背景宽度
     * @param int    $bgH   背景高度
     * @param int    $elemW 元素宽度
     * @param int    $elemH 元素高度
     * @param string $pos   位置标识：nw|n|ne|w|c|e|sw|s|se
     * @return array{int, int}
     */
    public static function calc(
        int $bgW, int $bgH,
        int $elemW, int $elemH,
        string $pos
    ): array {
        return match ($pos) {
            'nw' => [self::PADDING, self::PADDING],
            'n'  => [(int) (($bgW - $elemW) / 2), self::PADDING],
            'ne' => [$bgW - $elemW - self::PADDING, self::PADDING],
            'w'  => [self::PADDING, (int) (($bgH - $elemH) / 2)],
            'e'  => [$bgW - $elemW - self::PADDING, (int) (($bgH - $elemH) / 2)],
            'sw' => [self::PADDING, $bgH - $elemH - self::PADDING],
            's'  => [(int) (($bgW - $elemW) / 2), $bgH - $elemH - self::PADDING],
            'se' => [$bgW - $elemW - self::PADDING, $bgH - $elemH - self::PADDING],
            default => [(int) (($bgW - $elemW) / 2), (int) (($bgH - $elemH) / 2)],
        };
    }
}
