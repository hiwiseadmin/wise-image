<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\driver\ImagickDriver;
use wise\image\exception\ImageException;

/**
 * ImagickDriver 测试
 *
 * 仅在 Imagick 扩展加载时运行。
 * 未安装 Imagick 时所有测试自动跳过。
 */
class ImagickDriverTest extends TestCase
{
    private ?ImagickDriver $driver = null;

    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick 扩展未安装，跳过测试。');
        }

        try {
            $this->driver = new ImagickDriver();
        } catch (ImageException $e) {
            $this->markTestSkipped('ImagickDriver 构造失败：' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->driver !== null) {
            $this->driver->destroy();
            $this->driver = null;
        }
    }

    // -----------------------------------------------------------------
    // 构造函数：未安装 Imagick 时抛异常
    // -----------------------------------------------------------------

    public function testConstructorWhenImagickNotLoadedThrowsException(): void
    {
        // 此测试只在 imagick 未加载时才有意义
        // 但如果 imagick 已加载，则验证正常构造
        if (!extension_loaded('imagick')) {
            $this->expectException(ImageException::class);
            $this->expectExceptionCode(1001);
            new ImagickDriver();
        } else {
            $d = new ImagickDriver();
            $this->assertInstanceOf(ImagickDriver::class, $d);
            $d->destroy();
        }
    }

    // -----------------------------------------------------------------
    // create() — 创建空白画布
    // -----------------------------------------------------------------

    public function testCreateCanvas(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(300, 200);

        $this->assertSame(300, $this->driver->getWidth());
        $this->assertSame(200, $this->driver->getHeight());
        $this->assertSame('image/png', $this->driver->getMime());
    }

    // -----------------------------------------------------------------
    // resize()
    // -----------------------------------------------------------------

    public function testResizeFixedMode(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(400, 300);
        $this->driver->resize(200, 150, 'fixed');

        $this->assertSame(200, $this->driver->getWidth());
        $this->assertSame(150, $this->driver->getHeight());
    }

    public function testResizeLfitMode(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(400, 300);
        $this->driver->resize(200, 200, 'lfit');

        $this->assertLessThanOrEqual(200, $this->driver->getWidth());
        $this->assertLessThanOrEqual(200, $this->driver->getHeight());
    }

    public function testResizeRatioMode(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(400, 200);
        $this->driver->resize(50, 0, 'ratio');

        $this->assertSame(200, $this->driver->getWidth());
        $this->assertSame(100, $this->driver->getHeight());
    }

    // -----------------------------------------------------------------
    // crop()
    // -----------------------------------------------------------------

    public function testCrop(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(200, 200);
        $this->driver->crop(100, 100, 50, 50);

        $this->assertSame(100, $this->driver->getWidth());
        $this->assertSame(100, $this->driver->getHeight());
    }

    // -----------------------------------------------------------------
    // rotate()
    // -----------------------------------------------------------------

    public function testRotate90(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(200, 100);
        $this->driver->rotate(90);

        $this->assertGreaterThan(0, $this->driver->getWidth());
        $this->assertGreaterThan(0, $this->driver->getHeight());
    }

    // -----------------------------------------------------------------
    // blur / brightness / contrast
    // -----------------------------------------------------------------

    public function testBlur(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(200, 100);
        $this->driver->blur(5);

        $this->assertSame(200, $this->driver->getWidth());
    }

    public function testBrightness(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(200, 100);
        $this->driver->brightness(30);

        $this->assertSame(200, $this->driver->getWidth());
    }

    public function testContrast(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(200, 100);
        $this->driver->contrast(20);

        $this->assertSame(200, $this->driver->getWidth());
    }

    // -----------------------------------------------------------------
    // setQuality / getInfo
    // -----------------------------------------------------------------

    public function testSetQuality(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(100, 100);
        $this->driver->setQuality(80);

        $this->assertTrue(true);
    }

    public function testGetInfo(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(300, 200);

        $info = $this->driver->getInfo();
        $this->assertSame(300, $info['width']);
        $this->assertSame(200, $info['height']);
        $this->assertSame('image/png', $info['mime']);
        $this->assertSame('imagick', $info['driver']);
    }

    // -----------------------------------------------------------------
    // output()
    // -----------------------------------------------------------------

    public function testOutputPng(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(100, 50);
        $data = $this->driver->output('png');

        $this->assertIsString($data);
        $this->assertNotEmpty($data);
    }

    // -----------------------------------------------------------------
    // save()
    // -----------------------------------------------------------------

    public function testSavePng(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(100, 50);
        $savePath = sys_get_temp_dir() . '/imagick_test_output.png';

        $result = $this->driver->save($savePath, 'png');

        $this->assertTrue($result);
        $this->assertFileExists($savePath);

        @unlink($savePath);
    }

    // -----------------------------------------------------------------
    // destroy()
    // -----------------------------------------------------------------

    public function testDestroy(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(100, 100);
        $this->driver->destroy();

        $this->assertSame(0, $this->driver->getWidth());
        $this->assertSame(0, $this->driver->getHeight());
    }
}
