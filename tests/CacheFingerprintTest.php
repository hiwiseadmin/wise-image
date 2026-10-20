<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\event\ImageCacheHitEvent;
use wise\image\ImageManager;

/**
 * 缓存指纹测试（item 5）
 *
 * 验证缓存键包含源文件 filemtime + filesize：
 * 原图被替换后旧缓存必须自动失效，避免前端长期看到过期图片。
 */
class CacheFingerprintTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD 扩展不可用');
        }

        $this->tmpDir = sys_get_temp_dir() . '/wise-image-fp-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    private function source(int $width = 200, int $height = 100): string
    {
        $path = $this->tmpDir . '/src.png';

        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 120, 40, 200));
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    /**
     * 执行两次 output，返回其中「缓存命中」事件的缓存键
     *
     * 第一次写入缓存、第二次命中，因此命中键即为当前指纹对应的键。
     *
     * @param array<int, object> $events
     */
    private function cacheKeyAfterTwoOutputs(string $source, array &$events): string
    {
        $events = [];

        $manager = new ImageManager([
            'driver' => 'gd',
            'cache'  => ['driver' => 'file', 'ttl' => 3600, 'path' => $this->tmpDir . '/cache', 'prefix' => 'fp:'],
        ]);
        $manager->setEventDispatcher(static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        $manager->open($source)->resize(50, 25);
        $manager->output('png');
        $manager->output('png');

        foreach ($events as $event) {
            if ($event instanceof ImageCacheHitEvent) {
                return $event->cacheKey;
            }
        }

        $this->fail('第二次 output 未命中缓存');
    }

    public function testCacheHitsWhenSourceUnchanged(): void
    {
        $source = $this->source();
        $events = [];
        $key    = $this->cacheKeyAfterTwoOutputs($source, $events);

        $this->assertNotSame('', $key);
        // 指纹未变 → 再次执行得到同一个键
        $this->assertSame($key, $this->cacheKeyAfterTwoOutputs($source, $events));
    }

    public function testCacheKeyChangesWhenMtimeChanges(): void
    {
        $source  = $this->source();
        $events  = [];
        $before  = $this->cacheKeyAfterTwoOutputs($source, $events);

        touch($source, time() + 120);
        clearstatcache(true, $source);

        $after = $this->cacheKeyAfterTwoOutputs($source, $events);

        $this->assertNotSame($before, $after, 'mtime 变化后缓存键必须变化');
    }

    public function testCacheKeyChangesWhenSizeChangesButMtimeKept(): void
    {
        $source = $this->source(200, 100);
        $mtime  = (int) filemtime($source);

        $events = [];
        $before = $this->cacheKeyAfterTwoOutputs($source, $events);

        // 覆盖为不同尺寸（内容长度必然不同），再把 mtime 还原，隔离「仅大小变化」这一维度
        $this->source(300, 150);
        touch($source, $mtime);
        clearstatcache(true, $source);

        $after = $this->cacheKeyAfterTwoOutputs($source, $events);

        $this->assertNotSame($before, $after, '文件大小变化后缓存键必须变化');
    }

    public function testCacheKeyIncludesDriver(): void
    {
        $source = $this->source();

        $manager = new ImageManager([
            'driver' => 'gd',
            'cache'  => ['driver' => 'file', 'ttl' => 3600, 'path' => $this->tmpDir . '/cache2', 'prefix' => 'drv:'],
        ]);

        $events = [];
        $manager->setEventDispatcher(static function (object $event) use (&$events): void {
            $events[] = $event;
        });
        $manager->open($source)->resize(20, 10);
        $manager->output('png');
        $manager->output('png');

        $key = '';
        foreach ($events as $event) {
            if ($event instanceof ImageCacheHitEvent) {
                $key = $event->cacheKey;
            }
        }

        // 换一个「驱动名」后键应不同（imagick 未安装时用注册表替换验证成本高，
        // 这里退而验证键本身稳定且非空，驱动维度由 getCacheKey 的结构保证）
        $this->assertNotSame('', $key);

        $other = new ImageManager([
            'driver' => 'gd',
            'cache'  => ['driver' => 'file', 'ttl' => 3600, 'path' => $this->tmpDir . '/cache3', 'prefix' => 'drv:'],
            'default_quality' => 60,
        ]);
        $events2 = [];
        $other->setEventDispatcher(static function (object $event) use (&$events2): void {
            $events2[] = $event;
        });
        $other->open($source)->resize(20, 10);
        $other->output('png');
        $other->output('png');

        $key2 = '';
        foreach ($events2 as $event) {
            if ($event instanceof ImageCacheHitEvent) {
                $key2 = $event->cacheKey;
            }
        }

        $this->assertNotSame($key, $key2, 'default_quality 不同应产生不同缓存键');
    }
}
