<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\driver\DriverInterface;
use wise\image\driver\GdDriver;
use wise\image\exception\ErrorCode;
use wise\image\exception\ImageException;
use wise\image\ImageManager;
use wise\support\ExtPoint;
use wise\support\ExtPoints;

/**
 * 驱动扩展点测试（item 9）
 *
 * ImageManager::getDriver() 原先用 match 硬编码 gd/imagick，
 * 导致 ImageServiceProvider 注册的 ExtPoints::IMAGE_DRIVER 形同虚设。
 */
class DriverExtPointTest extends TestCase
{
    private string $tmpDir;
    private string $source;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD 扩展不可用');
        }

        ExtPointProbeDriver::$constructed = 0;
        ExtPoint::reset();

        $this->tmpDir = sys_get_temp_dir() . '/wise-image-ext-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);

        $this->source = $this->tmpDir . '/src.png';
        $img = imagecreatetruecolor(60, 30);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 200, 0));
        imagepng($img, $this->source);
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
        ExtPointProbeDriver::$constructed = 0;

        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    private function manager(string $driver): ImageManager
    {
        return new ImageManager([
            'driver' => $driver,
            'cache'  => ['driver' => 'file', 'path' => $this->tmpDir . '/cache', 'prefix' => 'ext:'],
        ]);
    }

    private function registerDriver(string $name, string $class): void
    {
        if (!ExtPoint::defined(ExtPoints::IMAGE_DRIVER)) {
            ExtPoint::definePoint(ExtPoints::IMAGE_DRIVER, ExtPoint::POLICY_SINGLE, DriverInterface::class);
        }

        ExtPoint::register(ExtPoints::IMAGE_DRIVER, $name, $class);
    }

    // -----------------------------------------------------------------
    // 兜底
    // -----------------------------------------------------------------

    public function testFallsBackToBuiltinDriverWhenExtPointEmpty(): void
    {
        $this->assertTrue(ExtPoint::isEmpty(ExtPoints::IMAGE_DRIVER));

        $info = $this->manager('gd')->open($this->source)->getInfo();

        $this->assertSame(60, $info['width']);
        $this->assertSame(0, ExtPointProbeDriver::$constructed);
    }

    public function testUnknownDriverStillThrows(): void
    {
        $this->expectException(ImageException::class);
        $this->expectExceptionCode(ErrorCode::DRIVER_NOT_FOUND->value);

        $this->manager('nonexistent')->open($this->source)->getInfo();
    }

    // -----------------------------------------------------------------
    // 扩展点生效
    // -----------------------------------------------------------------

    public function testRegisteredDriverOverridesBuiltin(): void
    {
        $this->registerDriver('gd', ExtPointProbeDriver::class);

        $info = $this->manager('gd')->open($this->source)->getInfo();

        $this->assertSame(60, $info['width']);
        $this->assertSame(1, ExtPointProbeDriver::$constructed, '应实例化扩展点注册的驱动');
    }

    public function testCustomNamedDriverIsResolvable(): void
    {
        $this->registerDriver('probe', ExtPointProbeDriver::class);

        $this->manager('probe')->open($this->source)->getInfo();

        $this->assertSame(1, ExtPointProbeDriver::$constructed);
    }
}

/**
 * 探针驱动：继承 GdDriver 并记录构造次数，用于验证扩展点是否被真正采用。
 */
class ExtPointProbeDriver extends GdDriver
{
    public static int $constructed = 0;

    public function __construct()
    {
        parent::__construct();
        self::$constructed++;
    }
}
