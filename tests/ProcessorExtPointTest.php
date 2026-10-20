<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\driver\DriverInterface;
use wise\image\exception\ErrorCode;
use wise\image\exception\ImageException;
use wise\image\ImageManager;
use wise\image\processor\ProcessorInterface;
use wise\support\ExtPoint;
use wise\support\ExtPoints;

/**
 * 处理器扩展点测试（item 9 · 处理器部分）
 *
 * 验证 ExtPoints::IMAGE_PROCESSOR 生效：
 *   - 插件可新增自定义操作
 *   - 插件可覆盖内置操作
 *   - 未注册扩展点时回退内置，行为不变
 */
class ProcessorExtPointTest extends TestCase
{
    private string $tmpDir;
    private string $source;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD 扩展不可用');
        }

        ExtPoint::reset();
        ProbeProcessor::reset();
        ImageManager::clearProcessorCache();

        $this->tmpDir = sys_get_temp_dir() . '/wise-image-proc-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);

        $this->source = $this->tmpDir . '/src.png';
        $img = imagecreatetruecolor(80, 40);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 60, 60, 200));
        imagepng($img, $this->source);
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
        ProbeProcessor::reset();
        ImageManager::clearProcessorCache();

        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    private function manager(): ImageManager
    {
        return new ImageManager([
            'driver' => 'gd',
            'cache'  => ['driver' => 'file', 'path' => $this->tmpDir . '/cache', 'prefix' => 'proc:'],
        ]);
    }

    /**
     * 注册一个探针处理器
     */
    private function registerProbe(string $operation): void
    {
        if (!ExtPoint::defined(ExtPoints::IMAGE_PROCESSOR)) {
            ExtPoint::definePoint(
                ExtPoints::IMAGE_PROCESSOR,
                ExtPoint::POLICY_MULTI,
                ProcessorInterface::class
            );
        }

        ProbeProcessor::$name = $operation;
        ExtPoint::register(ExtPoints::IMAGE_PROCESSOR, $operation, ProbeProcessor::class);
    }

    // -----------------------------------------------------------------
    // 兜底：未注册扩展点时行为不变
    // -----------------------------------------------------------------

    public function testBuiltinStillWorksWhenExtPointEmpty(): void
    {
        $out = $this->tmpDir . '/builtin.png';
        $this->manager()->open($this->source)->resize(40, 20)->save($out);

        $info = getimagesize($out);
        $this->assertSame([40, 20], [(int) $info[0], (int) $info[1]]);
        $this->assertSame([], ProbeProcessor::$calls);
    }

    public function testAvailableOperationsListsBuiltins(): void
    {
        $names = $this->manager()->availableOperations();

        foreach (['resize', 'crop', 'watermark', 'format', 'quality', 'rotate', 'blur', 'brightness', 'contrast'] as $name) {
            $this->assertContains($name, $names);
        }
    }

    public function testUnknownOperationStillThrows(): void
    {
        $this->expectException(ImageException::class);
        $this->expectExceptionCode(ErrorCode::PARAM_INVALID->value);

        $this->manager()->open($this->source)->operation('nope')->execute();
    }

    // -----------------------------------------------------------------
    // 新增自定义操作
    // -----------------------------------------------------------------

    public function testCustomProcessorViaUrlParams(): void
    {
        $this->registerProbe('invert');

        $this->manager()->open($this->source)->parseUrlParams('invert,v_7')->execute();

        $this->assertCount(1, ProbeProcessor::$calls);
        $this->assertSame('invert', ProbeProcessor::$calls[0]['operation']);
        $this->assertSame(['v' => '7'], ProbeProcessor::$calls[0]['params']);
    }

    public function testCustomProcessorViaGenericOperationApi(): void
    {
        $this->registerProbe('sharpen');

        $this->manager()->open($this->source)->operation('sharpen', ['r' => 2])->execute();

        $this->assertSame('sharpen', ProbeProcessor::$calls[0]['operation']);
        $this->assertSame(['r' => 2], ProbeProcessor::$calls[0]['params']);
    }

    public function testCustomProcessorViaStylePreset(): void
    {
        $this->registerProbe('label');

        $manager = new ImageManager([
            'driver' => 'gd',
            'cache'  => ['driver' => 'file', 'path' => $this->tmpDir . '/cache', 'prefix' => 'proc:'],
            'styles' => ['tagged' => 'label,text_hello'],
        ]);

        $manager->open($this->source)->style('tagged')->execute();

        $this->assertSame('label', ProbeProcessor::$calls[0]['operation']);
        $this->assertSame(['text' => 'hello'], ProbeProcessor::$calls[0]['params']);
    }

    public function testAvailableOperationsIncludesCustom(): void
    {
        $this->registerProbe('invert');

        $this->assertContains('invert', $this->manager()->availableOperations());
    }

    // -----------------------------------------------------------------
    // 覆盖内置实现
    // -----------------------------------------------------------------

    public function testCustomProcessorOverridesBuiltin(): void
    {
        $this->registerProbe('resize');

        $this->manager()->open($this->source)->operation('resize', ['w' => 10])->execute();

        $this->assertCount(1, ProbeProcessor::$calls, '应使用扩展点注册的实现，而非内置 ResizeProcessor');
        $this->assertSame('resize', ProbeProcessor::$calls[0]['operation']);
        $this->assertSame(['w' => 10], ProbeProcessor::$calls[0]['params']);
    }

    public function testBuiltinIsRestoredAfterOverrideRemoved(): void
    {
        $this->registerProbe('resize');
        $this->manager()->open($this->source)->operation('resize', ['w' => 10])->execute();
        $this->assertCount(1, ProbeProcessor::$calls);

        // 注销扩展 + 清缓存 → 回到内置实现
        ExtPoint::unregister(ExtPoints::IMAGE_PROCESSOR, 'resize');
        ImageManager::clearProcessorCache();

        $out = $this->tmpDir . '/restored.png';
        $this->manager()->open($this->source)->resize(20, 10)->save($out);

        $this->assertCount(1, ProbeProcessor::$calls, '不应再调用探针处理器');
        $info = getimagesize($out);
        $this->assertSame([20, 10], [(int) $info[0], (int) $info[1]]);
    }

    // -----------------------------------------------------------------
    // 异常：注册的实现不合法
    // -----------------------------------------------------------------

    public function testInvalidCustomProcessorImplementationThrows(): void
    {
        if (!ExtPoint::defined(ExtPoints::IMAGE_PROCESSOR)) {
            ExtPoint::definePoint(ExtPoints::IMAGE_PROCESSOR, ExtPoint::POLICY_MULTI);
        }

        // 故意注册一个不实现 ProcessorInterface 的类
        ExtPoint::register(ExtPoints::IMAGE_PROCESSOR, 'broken', \stdClass::class);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(ErrorCode::PARAM_INVALID->value);

        $this->manager()->open($this->source)->operation('broken')->execute();
    }
}

/**
 * 测试用探针处理器：只记录被调用的信息，不操作像素。
 */
class ProbeProcessor implements ProcessorInterface
{
    /** @var string 当前注册的操作名 */
    public static string $name = 'probe';

    /** @var array<int, array{operation: string, params: array<string, mixed>}> */
    public static array $calls = [];

    public function process(ImageManager $manager, DriverInterface $driver, array $params): void
    {
        self::$calls[] = ['operation' => self::$name, 'params' => $params];
    }

    public function getName(): string
    {
        return self::$name;
    }

    /**
     * 重置探针状态（测试隔离）
     */
    public static function reset(): void
    {
        self::$name  = 'probe';
        self::$calls = [];
    }
}
