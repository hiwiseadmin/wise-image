<?php

declare(strict_types=1);

namespace wise\image\event;

class ImageProcessingEvent
{
    public readonly string $sourcePath;
    public readonly array $operations;
    public readonly float $startTime;

    public function __construct(string $sourcePath, array $operations)
    {
        $this->sourcePath = $sourcePath;
        $this->operations = $operations;
        $this->startTime = microtime(true);
    }
}
