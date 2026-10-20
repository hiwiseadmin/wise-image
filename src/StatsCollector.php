<?php

declare(strict_types=1);

namespace wise\image;

/**
 * 统计收集器
 *
 * 收集处理过程中的统计数据，用于统计和监控。
 */
class StatsCollector
{
    private array $stats = [
        'processed' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'remote_fetches' => 0,
        'lock_wait_hits' => 0,
        'lock_wait_timeouts' => 0,
        'total_duration_ms' => 0.0,
        'errors' => 0,
        'bytes_output' => 0,
    ];

    private static ?self $instance = null;

    /**
     * 获取实例
     *
     * @return self
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * 记录一次处理
     *
     * @param float $durationMs 处理耗时（毫秒）
     * @param int $bytes 输出字节数
     */
    public function recordProcess(float $durationMs, int $bytes = 0): void
    {
        $this->stats['processed']++;
        $this->stats['total_duration_ms'] += $durationMs;
        $this->stats['bytes_output'] += $bytes;
    }

    /**
     * 记录一次缓存命中
     */
    public function recordCacheHit(): void
    {
        $this->stats['cache_hits']++;
    }

    /**
     * 记录一次缓存未命中
     */
    public function recordCacheMiss(): void
    {
        $this->stats['cache_misses']++;
    }

    /**
     * 记录一次错误
     */
    public function recordError(): void
    {
        $this->stats['errors']++;
    }

    /**
     * 记录一次远程图片抓取
     */
    public function recordRemoteFetch(): void
    {
        $this->stats['remote_fetches']++;
    }

    /**
     * 记录一次「未抢到单飞锁、等待后命中缓存」（说明削峰生效）
     */
    public function recordLockWaitHit(): void
    {
        $this->stats['lock_wait_hits']++;
    }

    /**
     * 记录一次「等待缓存超时、降级自行处理」（说明 wait_timeout 偏小或处理过慢）
     */
    public function recordLockTimeout(): void
    {
        $this->stats['lock_wait_timeouts']++;
    }

    /**
     * 获取统计数据
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $total = $this->stats['processed'];
        $totalCaches = $this->stats['cache_hits'] + $this->stats['cache_misses'];

        return [
            'total_processed' => $total,
            'cache_hits' => $this->stats['cache_hits'],
            'cache_misses' => $this->stats['cache_misses'],
            'cache_hit_rate' => ($totalCaches > 0) ? round(($this->stats['cache_hits'] / $totalCaches) * 100, 1) : 0,
            'remote_fetches' => $this->stats['remote_fetches'],
            'lock_wait_hits' => $this->stats['lock_wait_hits'],
            'lock_wait_timeouts' => $this->stats['lock_wait_timeouts'],
            'avg_duration_ms' => ($total > 0) ? round($this->stats['total_duration_ms'] / $total, 2) : 0,
            'total_bytes' => $this->stats['bytes_output'],
            'errors' => $this->stats['errors'],
        ];
    }

    /**
     * 重置统计数据
     */
    public function reset(): void
    {
        $this->stats = [
            'processed' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'remote_fetches' => 0,
            'lock_wait_hits' => 0,
            'lock_wait_timeouts' => 0,
            'total_duration_ms' => 0.0,
            'errors' => 0,
            'bytes_output' => 0,
        ];
    }
}
