<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\event\ImageCacheHitEvent;
use wise\image\event\ImageProcessedEvent;
use wise\image\event\ImageProcessingEvent;
use wise\image\exception\ImageException;
use wise\image\ImageManager;
use wise\image\StatsCollector;
use wise\support\lock\DistributedLock;
use wise\support\lock\LockInterface;

/**
 * 缓存击穿治理测试
 *
 * Step 1：缓存查询前移 —— 命中时不得再执行处理链
 * Step 2：单飞锁 —— 未抢到锁时等待缓存 / 超时降级
 */
class CacheStampedeTest extends TestCase
{
    private string $tmpDir;
    private string $source;

    /** @var array<int, object> */
    private array $events = [];

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD 扩展不可用');
        }

        $this->tmpDir = sys_get_temp_dir() . '/wise-image-ss-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);

        $this->source = $this->tmpDir . '/src.png';
        $img = imagecreatetruecolor(200, 100);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 40, 140, 60));
        imagepng($img, $this->source);
        imagedestroy($img);

        StatsCollector::getInstance()->reset();
        unset($_SERVER['HTTP_ACCEPT']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_ACCEPT']);

        if (!is_dir($this->tmpDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    // -----------------------------------------------------------------
    // 辅助
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $cacheExtra
     * @param array<string, mixed> $rootExtra
     */
    private function config(array $cacheExtra = [], array $rootExtra = []): array
    {
        return array_merge([
            'driver' => 'gd',
            'cache'  => array_merge([
                'driver' => 'file',
                'ttl'    => 3600,
                'path'   => $this->tmpDir . '/cache',
                'prefix' => 'ss:',
            ], $cacheExtra),
        ], $rootExtra);
    }

    private function manager(array $cacheExtra = [], array $rootExtra = []): ImageManager
    {
        $manager = new ImageManager($this->config($cacheExtra, $rootExtra));

        $this->events = [];
        $manager->setEventDispatcher(function (object $event): void {
            $this->events[] = $event;
        });

        return $manager;
    }

    /**
     * @return string[]
     */
    private function eventNames(): array
    {
        return array_map('get_class', $this->events);
    }

    // -----------------------------------------------------------------
    // Step 1：缓存查询前移
    // -----------------------------------------------------------------

    public function testCacheMissProcessesAndWritesCache(): void
    {
        $manager = $this->manager();
        $bytes   = $manager->open($this->source)->resize(50, 25)->output('png');

        $this->assertNotSame('', $bytes);
        $this->assertContains(ImageProcessingEvent::class, $this->eventNames());
        $this->assertContains(ImageProcessedEvent::class, $this->eventNames());
        $this->assertSame(1, StatsCollector::getInstance()->getStats()['cache_misses']);
    }

    public function testCacheHitSkipsProcessingEntirely(): void
    {
        // 第一次：未命中 → 处理 + 写缓存
        $first = $this->manager();
        $first->open($this->source)->resize(50, 25)->output('png');
        $this->assertContains(ImageProcessingEvent::class, $this->eventNames());

        // 第二次：新实例命中缓存 → 完全不应触发处理链
        $second = $this->manager();
        $bytes  = $second->open($this->source)->resize(50, 25)->output('png');

        $this->assertNotSame('', $bytes);
        $this->assertNotContains(
            ImageProcessingEvent::class,
            $this->eventNames(),
            '命中缓存时不应再执行处理链（这正是 Step 1 的收益）'
        );
        $this->assertContains(ImageCacheHitEvent::class, $this->eventNames());
    }

    public function testCacheKeyIsStableWhenFormatComesFromChain(): void
    {
        // format 由链式 API 指定：缓存键必须在 execute() 之前就能算出一致结果
        $first = $this->manager();
        $first->open($this->source)->resize(30, 15)->format('jpg')->output();
        $this->assertContains(ImageProcessingEvent::class, $this->eventNames());

        $second = $this->manager();
        $second->open($this->source)->resize(30, 15)->format('jpg')->output();

        $this->assertNotContains(ImageProcessingEvent::class, $this->eventNames());
    }

    public function testOutputFormatIsPredictableBeforeExecute(): void
    {
        $manager = $this->manager();
        $manager->open($this->source)->format('png');

        $this->assertSame('png', $manager->resolveOutputFormat());
        $this->assertSame('jpg', $manager->resolveOutputFormat('JPG'), '显式参数优先且大小写归一');
    }

    public function testAutoWebpIsPredictedFromAcceptHeader(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'image/avif,image/webp,*/*';

        $manager = $this->manager([], ['auto_webp' => true]);
        $manager->open($this->source)->resize(20, 10)->autoFormat();

        $this->assertSame('webp', $manager->resolveOutputFormat());

        // 客户端不支持 webp 时不预测
        $_SERVER['HTTP_ACCEPT'] = 'image/png';
        $other = $this->manager([], ['auto_webp' => true]);
        $other->open($this->source)->resize(20, 10)->autoFormat();

        $this->assertNull($other->resolveOutputFormat());
    }

    // -----------------------------------------------------------------
    // Step 2：单飞锁
    // -----------------------------------------------------------------

    public function testLockIsDisabledByDefault(): void
    {
        $this->assertNull($this->manager()->cacheLock());
    }

    public function testLockEnabledReturnsDistributedLock(): void
    {
        $manager = $this->manager([
            'lock' => ['enabled' => true, 'driver' => 'file', 'path' => $this->tmpDir . '/locks'],
        ]);

        $this->assertInstanceOf(DistributedLock::class, $manager->cacheLock());
        $this->assertInstanceOf(LockInterface::class, $manager->cacheLock());
    }

    public function testProcessingWorksWithRealFileLockEnabled(): void
    {
        $manager = $this->manager([
            'lock' => ['enabled' => true, 'driver' => 'file', 'path' => $this->tmpDir . '/locks'],
        ]);

        $bytes = $manager->open($this->source)->resize(40, 20)->output('png');

        $this->assertNotSame('', $bytes);
        $this->assertContains(ImageProcessingEvent::class, $this->eventNames());
    }

    public function testWaitForCacheInsteadOfReprocessingWhenLockNotAcquired(): void
    {
        $source = $this->source;

        // 模拟「他人持锁并在等待期间写出缓存」
        $lock = new TestLock();
        $lock->acquired  = false;
        $lock->onAcquire = function () use ($source): void {
            $other = new ImageManager([
                'driver' => 'gd',
                'cache'  => [
                    'driver' => 'file',
                    'ttl'    => 3600,
                    'path'   => $this->tmpDir . '/cache',
                    'prefix' => 'ss:',
                ],
            ]);
            $other->open($source)->resize(30, 15)->output('png');
        };

        $manager = $this->manager([
            'lock' => ['enabled' => true, 'wait_timeout' => 1.0, 'poll_interval' => 10],
        ]);
        $manager->setCacheLock($lock);

        $bytes = $manager->open($this->source)->resize(30, 15)->output('png');

        $this->assertNotSame('', $bytes);
        $this->assertNotContains(
            ImageProcessingEvent::class,
            $this->eventNames(),
            '未抢到锁且缓存出现时应直接返回，不得重复处理'
        );
        $this->assertContains(ImageCacheHitEvent::class, $this->eventNames());
        $this->assertSame(1, StatsCollector::getInstance()->getStats()['lock_wait_hits']);
        $this->assertSame(0, StatsCollector::getInstance()->getStats()['lock_wait_timeouts']);
    }

    public function testFallsBackToProcessingWhenWaitTimesOut(): void
    {
        $lock = new TestLock();
        $lock->acquired = false;   // 永远抢不到，且缓存始终不出现

        $manager = $this->manager([
            'lock' => ['enabled' => true, 'wait_timeout' => 0.05, 'poll_interval' => 10],
        ]);
        $manager->setCacheLock($lock);

        $bytes = $manager->open($this->source)->resize(30, 15)->output('png');

        $this->assertNotSame('', $bytes);
        $this->assertContains(
            ImageProcessingEvent::class,
            $this->eventNames(),
            '等待超时应降级为自行处理，保证可用性'
        );
        $this->assertSame(1, StatsCollector::getInstance()->getStats()['lock_wait_timeouts']);
    }

    public function testLockIsReleasedEvenWhenProcessingFails(): void
    {
        $lock = new TestLock();
        $lock->acquired = true;

        $manager = $this->manager([
            'lock' => ['enabled' => true, 'wait_timeout' => 0.05, 'poll_interval' => 10],
        ]);
        $manager->setCacheLock($lock);

        $manager->open($this->source)->quality(999);   // 执行期必然抛参数错误

        try {
            $manager->output('png');
            $this->fail('应抛出参数异常');
        } catch (ImageException $e) {
            $this->assertTrue($lock->released, '处理失败时也必须释放锁，否则会死锁到 TTL 结束');
            $this->assertNotSame('', $lock->keys[0] ?? '', '锁键应为缓存键派生');
        }
    }
}

/**
 * 可编程的锁替身：用于精确构造「抢到 / 抢不到 / 释放」等分支。
 */
class TestLock implements LockInterface
{
    public bool $acquired = true;

    public bool $released = false;

    /** @var \Closure|null 获取锁时执行（用于模拟「他人写入缓存」） */
    public ?\Closure $onAcquire = null;

    /** @var string[] */
    public array $keys = [];

    public function acquire(string $key, int $ttl): bool
    {
        $this->keys[] = $key;

        if ($this->onAcquire !== null) {
            ($this->onAcquire)();
        }

        return $this->acquired;
    }

    public function release(string $key): bool
    {
        $this->released = true;

        return true;
    }

    public function isAcquired(string $key): bool
    {
        return $this->acquired;
    }
}
