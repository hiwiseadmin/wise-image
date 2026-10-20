<?php

declare(strict_types=1);

namespace wise\image\event;

class ImageProcessedEvent
{
    public readonly string $sourcePath;
    public readonly float $startTime;
    public readonly float $endTime;
    public readonly float $duration;
    public readonly string $targetFormat;
    public readonly int $resultSize;

    public function __construct(string $sourcePath, float $startTime, string $targetFormat, int $resultSize = 0)
    {
        $this->sourcePath = $sourcePath;
        $this->startTime = $startTime;
        $this->endTime = microtime(true);
        $this->duration = round(($this->endTime - $startTime) * 1000, 2);
        $this->targetFormat = $targetFormat;
        $this->resultSize = $resultSize;
    }
}
