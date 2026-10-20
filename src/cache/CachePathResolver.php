<?php

declare(strict_types=1);

namespace wise\image\cache;

/**
 * 缓存目录解析器
 *
 * 统一「相对路径 → 项目根目录」的解析规则，避免 ImageManager、RemoteFetcher
 * 与缓存治理命令各自实现一套导致行为不一致。
 */
class CachePathResolver
{
    /**
     * 解析缓存目录为绝对路径
     *
     * 规则：
     *   - 绝对路径（`/` 或 `X:\` 开头）原样返回；
     *   - 相对路径基于 `ROOT_PATH`（未定义时回退到包内推算的项目 src 目录）拼接。
     *
     * @param string|null $dir      配置中的缓存目录
     * @param string      $fallback 配置缺失时的默认目录
     * @return string 绝对路径（不含尾部斜杠）
     */
    public static function resolve(?string $dir, string $fallback = ''): string
    {
        $dir = trim((string) $dir);
        if ($dir === '') {
            $dir = $fallback;
        }

        if ($dir === '') {
            return '';
        }

        if (!self::isAbsolute($dir)) {
            $rootPath = defined('ROOT_PATH') ? (string) ROOT_PATH : dirname(__DIR__, 5);
            $dir = $rootPath . DIRECTORY_SEPARATOR . $dir;
        }

        // 统一分隔符，避免 `D:\a/src/b` 这类混合写法影响后续拼接与日志可读性
        $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);

        return rtrim($dir, DIRECTORY_SEPARATOR);
    }

    /**
     * 是否为绝对路径
     */
    public static function isAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        // Windows 盘符：C:\ 或 C:/
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
