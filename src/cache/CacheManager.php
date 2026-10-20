<?php

declare(strict_types=1);

namespace wise\image\cache;

/**
 * 图片缓存治理器
 *
 * 提供缓存目录的统计、清理与过期裁剪能力，供 `wise:image:cache-*` 系列命令
 * 及宿主应用直接调用（不依赖控制台环境，便于单测与编程式调用）。
 *
 * 注意：仅作用于 **file** 缓存驱动下的磁盘文件；Redis 驱动请使用框架缓存命令。
 */
class CacheManager
{
    /** 主缓存目录默认值 */
    public const DEFAULT_CACHE_DIR = 'runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'cache';

    /** 远程副本缓存目录默认值 */
    public const DEFAULT_REMOTE_DIR = 'runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'remote';

    /**
     * @param array<string, mixed> $cacheConfig  配置的 `cache` 段
     * @param array<string, mixed> $remoteConfig 配置的 `remote` 段（用于定位远程副本目录）
     */
    public function __construct(
        private readonly array $cacheConfig = [],
        private readonly array $remoteConfig = []
    ) {
    }

    /**
     * 缓存驱动名
     */
    public function driver(): string
    {
        return (string) ($this->cacheConfig['driver'] ?? 'file');
    }

    /**
     * 是否为文件驱动（命令可直接治理）
     */
    public function supportsFileOperations(): bool
    {
        return $this->driver() === 'file';
    }

    /**
     * 主缓存目录（绝对路径）
     */
    public function cacheDir(): string
    {
        return CachePathResolver::resolve(
            isset($this->cacheConfig['path']) ? (string) $this->cacheConfig['path'] : null,
            self::DEFAULT_CACHE_DIR
        );
    }

    /**
     * 远程副本缓存目录（绝对路径）
     */
    public function remoteDir(): string
    {
        $remoteCache = (array) ($this->remoteConfig['cache'] ?? []);

        return CachePathResolver::resolve(
            isset($remoteCache['path']) ? (string) $remoteCache['path'] : null,
            self::DEFAULT_REMOTE_DIR
        );
    }

    /**
     * 统计缓存占用
     *
     * @return array{
     *     driver: string,
     *     main: array{dir: string, exists: bool, files: int, bytes: int, oldest: int|null, newest: int|null},
     *     remote: array{dir: string, exists: bool, files: int, bytes: int, oldest: int|null, newest: int|null}
     * }
     */
    public function stats(): array
    {
        return [
            'driver' => $this->driver(),
            'main'   => $this->scan($this->cacheDir()),
            'remote' => $this->scan($this->remoteDir()),
        ];
    }

    /**
     * 清空全部缓存文件
     *
     * @param bool $dryRun 仅统计不删除
     * @return array{files: int, bytes: int, dry_run: bool}
     */
    public function clear(bool $dryRun = false): array
    {
        $files = 0;
        $bytes = 0;

        foreach ([$this->cacheDir(), $this->remoteDir()] as $dir) {
            $result = $this->deleteFiles($dir, null, $dryRun);
            $files += $result['files'];
            $bytes += $result['bytes'];
        }

        return ['files' => $files, 'bytes' => $bytes, 'dry_run' => $dryRun];
    }

    /**
     * 清理超过指定天数的缓存文件
     *
     * @param int  $days   保留天数（<= 0 时按 1 天处理）
     * @param bool $dryRun 仅统计不删除
     * @return array{files: int, bytes: int, dry_run: bool, days: int}
     */
    public function prune(int $days, bool $dryRun = false): array
    {
        $days  = max(1, $days);
        $limit = time() - $days * 86400;

        $files = 0;
        $bytes = 0;

        foreach ([$this->cacheDir(), $this->remoteDir()] as $dir) {
            $result = $this->deleteFiles($dir, $limit, $dryRun);
            $files += $result['files'];
            $bytes += $result['bytes'];
        }

        return ['files' => $files, 'bytes' => $bytes, 'dry_run' => $dryRun, 'days' => $days];
    }

    // -----------------------------------------------------------------
    // 内部实现
    // -----------------------------------------------------------------

    /**
     * 按 mtime 早于 $olderThan 删除文件；$olderThan 为 null 表示全部删除
     *
     * @return array{files: int, bytes: int}
     */
    private function deleteFiles(string $dir, ?int $olderThan, bool $dryRun): array
    {
        $files = 0;
        $bytes = 0;

        if ($dir === '' || !is_dir($dir)) {
            return ['files' => 0, 'bytes' => 0];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                if (!$dryRun) {
                    @rmdir($item->getPathname());
                }
                continue;
            }

            $path = $item->getPathname();

            if ($olderThan !== null) {
                $mtime = @filemtime($path);
                if ($mtime === false || $mtime > $olderThan) {
                    continue;
                }
            }

            $files++;
            $bytes += (int) ($item->getSize() ?: 0);

            if (!$dryRun) {
                @unlink($path);
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * 扫描目录统计文件数、总大小与时间跨度
     *
     * @return array{dir: string, exists: bool, files: int, bytes: int, oldest: int|null, newest: int|null}
     */
    private function scan(string $dir): array
    {
        $result = [
            'dir'    => $dir,
            'exists' => $dir !== '' && is_dir($dir),
            'files'  => 0,
            'bytes'  => 0,
            'oldest' => null,
            'newest' => null,
        ];

        if (!$result['exists']) {
            return $result;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }

            $result['files']++;
            $result['bytes'] += (int) ($item->getSize() ?: 0);

            $mtime = @filemtime($item->getPathname());
            if ($mtime === false) {
                continue;
            }

            $result['oldest'] = $result['oldest'] === null ? $mtime : min($result['oldest'], $mtime);
            $result['newest'] = $result['newest'] === null ? $mtime : max($result['newest'], $mtime);
        }

        return $result;
    }
}
