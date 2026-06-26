<?php

declare(strict_types=1);

if (!function_exists('wise_image')) {
    /**
     * 获取 ImageManager 实例（快捷方式）
     *
     * @param string|null $source 可选源文件路径，传入后自动调用 make()
     * @return \wise\image\ImageManager
     */
    function wise_image(?string $source = null): \wise\image\ImageManager
    {
        /** @var \wise\image\ImageManager $manager */
        $manager = app(\wise\image\ImageManager::class);

        if ($source !== null) {
            $manager->make($source);
        }

        return $manager;
    }
}
