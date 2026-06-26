<?php

declare(strict_types=1);

namespace wise\image;

class StatsCollector
{
    private array $stats = [
        'processed' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'total_duration_ms' => 0.0,
        'errors' => 0,
        'bytes_output' => 0,
    ];

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function recordProcess(float $durationMs, int $bytes = 0): void
    {
        $this->stats['processed']++;
        $this->stats['total_duration_ms'] += $durationMs;
        $this->stats['bytes_output'] += $bytes;
    }

    public function recordCacheHit(): void
    {
        $this->stats['cache_hits']++;
    }

    public function recordCacheMiss(): void
    {
        $this->stats['cache_misses']++;
    }

    public function recordError(): void
    {
        $this->stats['errors']++;
    }

    public function getStats(): array
    {
        $total = $this->stats['processed'];
        $totalCaches = $this->stats['cache_hits'] + $this->stats['cache_misses'];

        return [
            'total_processed' => $total,
            'cache_hits' => $this->stats['cache_hits'],
            'cache_misses' => $this->stats['cache_misses'],
            'cache_hit_rate' => ($totalCaches > 0) ? round(($this->stats['cache_hits'] / $totalCaches) * 100, 1) : 0,
            'avg_duration_ms' => ($total > 0) ? round($this->stats['total_duration_ms'] / $total, 2) : 0,
            'total_bytes' => $this->stats['bytes_output'],
            'errors' => $this->stats['errors'],
        ];
    }

    public function reset(): void
    {
        $this->stats = [
            'processed' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'total_duration_ms' => 0.0,
            'errors' => 0,
            'bytes_output' => 0,
        ];
    }
}
