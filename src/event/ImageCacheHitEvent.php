<?php

declare(strict_types=1);

namespace wise\image\event;

class ImageCacheHitEvent
{
    public readonly string $cacheKey;
    public readonly string $sourcePath;

    public function __construct(string $cacheKey, string $sourcePath)
    {
        $this->cacheKey = $cacheKey;
        $this->sourcePath = $sourcePath;
    }
}
