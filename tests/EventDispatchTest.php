<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\event\ImageCacheHitEvent;
use wise\image\event\ImageProcessedEvent;
use wise\image\event\ImageProcessingEvent;
use wise\image\ImageManager;

/**
 * 领域事件派发测试
 *
 * 通过 setEventDispatcher() 注入收集器，验证事件确实被派发且载荷正确、
 * 监听器异常不会阻断图片处理主流程。
 */
class EventDispatchTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD 扩展不可用');
        }

        $this->tmpDir = sys_get_temp_dir() . '/wise-image-events-' . bin2hex(random_bytes(4));
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

    private function source(): string
    {
        $path = $this->tmpDir . '/src.png';
        if (!is_file($path)) {
            $img = imagecreatetruecolor(200, 100);
            imagefill($img, 0, 0, (int) imagecolorallocate($img, 10, 120, 200));
            imagepng($img, $path);
            imagedestroy($img);
        }

        return $path;
    }

    /**
     * @param array<int, object> $sink
     */
    private function managerWithSink(array &$sink, array $config = []): ImageManager
    {
        $manager = new ImageManager(array_merge([
            'driver' => 'gd',
            'cache'  => ['driver' => 'file', 'ttl' => 3600, 'path' => $this->tmpDir . '/cache', 'prefix' => 'ev:'],
        ], $config));

        $manager->setEventDispatcher(static function (object $event) use (&$sink): void {
            $sink[] = $event;
        });

        return $manager;
    }

    public function testProcessingAndProcessedEventsAreDispatched(): void
    {
        $events = [];
        $manager = $this->managerWithSink($events);

        $manager->open($this->source())->resize(50, 25)->save($this->tmpDir . '/out.png');

        $names = array_map('get_class', $events);

        $this->assertContains(ImageProcessingEvent::class, $names);
        $this->assertContains(ImageProcessedEvent::class, $names);
        $this->assertSame(ImageProcessingEvent::class, $names[0], '处理前事件应先于处理后事件');
    }

    public function testProcessingEventPayload(): void
    {
        $events = [];
        $source = $this->source();

        $this->managerWithSink($events)->open($source)->resize(50, 25)->execute();

        $processing = null;
        foreach ($events as $event) {
            if ($event instanceof ImageProcessingEvent) {
                $processing = $event;
                break;
            }
        }

        $this->assertNotNull($processing);
        $this->assertSame($source, $processing->sourcePath);
        $this->assertSame('resize', $processing->operations[0]['operation']);
        $this->assertSame(50, $processing->operations[0]['params']['w']);
    }

    public function testProcessedEventPayload(): void
    {
        $events = [];
        $source = $this->source();

        $this->managerWithSink($events)->open($source)->resize(50, 25)->format('png')->execute();

        $processed = null;
        foreach ($events as $event) {
            if ($event instanceof ImageProcessedEvent) {
                $processed = $event;
                break;
            }
        }

        $this->assertNotNull($processed);
        $this->assertSame($source, $processed->sourcePath);
        $this->assertSame('png', $processed->targetFormat);
        $this->assertGreaterThanOrEqual(0, $processed->duration);
    }

    public function testCacheHitEventIsDispatched(): void
    {
        $events  = [];
        $manager = $this->managerWithSink($events);

        $manager->open($this->source())->resize(30, 15);
        $manager->output('png');
        $manager->output('png');

        $hits = array_filter($events, static fn ($e): bool => $e instanceof ImageCacheHitEvent);

        $this->assertNotEmpty($hits, '第二次 output 应命中缓存并派发 ImageCacheHitEvent');

        /** @var ImageCacheHitEvent $hit */
        $hit = array_values($hits)[0];
        $this->assertNotSame('', $hit->cacheKey);
        $this->assertSame($this->source(), $hit->sourcePath);
    }

    public function testListenerExceptionDoesNotBreakProcessing(): void
    {
        $manager = new ImageManager([
            'driver' => 'gd',
            'cache'  => ['driver' => 'file', 'path' => $this->tmpDir . '/cache2', 'prefix' => 'boom:'],
        ]);

        $manager->setEventDispatcher(static function (): void {
            throw new \RuntimeException('listener boom');
        });

        $out = $this->tmpDir . '/boom.png';

        $this->assertTrue($manager->open($this->source())->resize(20, 10)->save($out));
        $this->assertFileExists($out);
    }

    public function testMissingEventBusDegradesSilently(): void
    {
        // 未注入派发器时回退 ThinkPHP Event 总线；测试环境无应用容器，
        // 总线调用会抛异常，必须被静默吞掉，不影响图片处理。
        $manager = new ImageManager(['driver' => 'gd']);
        $out     = $this->tmpDir . '/no_bus.png';

        $this->assertTrue($manager->open($this->source())->resize(20, 10)->save($out));
        $this->assertFileExists($out);
    }
}
