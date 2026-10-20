<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\driver\DriverInterface;
use wise\image\exception\ImageException;
use wise\image\ImageManager;
use wise\image\processor\CropProcessor;
use wise\image\processor\FormatProcessor;
use wise\image\processor\ProcessorInterface;
use wise\image\processor\QualityProcessor;
use wise\image\processor\ResizeProcessor;
use wise\image\processor\WatermarkProcessor;

/**
 * Processor 全面测试
 *
 * 验证所有 5 个处理器的 getName() 返回正确名称，
 * 以及 process() 方法正确调用驱动方法。
 */
class ProcessorTest extends TestCase
{
    // -----------------------------------------------------------------
    // getName() — 处理器名称
    // -----------------------------------------------------------------

    /**
     * @dataProvider processorNameDataProvider
     */
    public function testGetName(ProcessorInterface $processor, string $expectedName): void
    {
        $this->assertSame($expectedName, $processor->getName());
    }

    public static function processorNameDataProvider(): array
    {
        return [
            'ResizeProcessor'     => [new ResizeProcessor(), 'resize'],
            'CropProcessor'       => [new CropProcessor(), 'crop'],
            'WatermarkProcessor'  => [new WatermarkProcessor(), 'watermark'],
            'FormatProcessor'     => [new FormatProcessor(), 'format'],
            'QualityProcessor'    => [new QualityProcessor(), 'quality'],
        ];
    }

    // -----------------------------------------------------------------
    // ResizeProcessor::process()
    // -----------------------------------------------------------------

    public function testResizeProcessorDelegatesToDriver(): void
    {
        $processor = new ResizeProcessor();

        /** @var ImageManager|\PHPUnit\Framework\MockObject\MockObject $manager */
        $manager = $this->createMock(ImageManager::class);
        $manager->method('getConfig')->willReturn(['default_quality' => 85]);

        /** @var DriverInterface|\PHPUnit\Framework\MockObject\MockObject $driver */
        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->once())
            ->method('resize')
            ->with(200, 150, 'lfit');

        $processor->process($manager, $driver, [
            'w' => 200,
            'h' => 150,
            'm' => 'lfit',
        ]);
    }

    public function testResizeProcessorModeFromModeKey(): void
    {
        $processor = new ResizeProcessor();
        /** @var ImageManager|\PHPUnit\Framework\MockObject\MockObject $manager */
        $manager = $this->createMock(ImageManager::class);
        $manager->method('getConfig')->willReturn(['default_quality' => 85]);

        /** @var DriverInterface|\PHPUnit\Framework\MockObject\MockObject $driver */
        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->once())
            ->method('resize')
            ->with(300, 200, 'fill');

        $processor->process($manager, $driver, [
            'w' => 300,
            'h' => 200,
            'mode' => 'fill',
        ]);
    }

    public function testResizeProcessorDefaultModeLfit(): void
    {
        $processor = new ResizeProcessor();
        /** @var ImageManager|\PHPUnit\Framework\MockObject\MockObject $manager */
        $manager = $this->createMock(ImageManager::class);
        $manager->method('getConfig')->willReturn(['default_quality' => 85]);

        /** @var DriverInterface|\PHPUnit\Framework\MockObject\MockObject $driver */
        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->once())
            ->method('resize')
            ->with(200, 150, 'lfit');

        $processor->process($manager, $driver, [
            'w' => 200,
            'h' => 150,
        ]);
    }

    public function testResizeProcessorWidthZeroThrows(): void
    {
        $processor = new ResizeProcessor();
        $manager = $this->createMock(ImageManager::class);
        $driver = $this->createMock(DriverInterface::class);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120005);

        $processor->process($manager, $driver, [
            'w' => 0,
            'h' => 100,
        ]);
    }

    public function testResizeProcessorInvalidModeThrows(): void
    {
        $processor = new ResizeProcessor();
        $manager = $this->createMock(ImageManager::class);
        $driver = $this->createMock(DriverInterface::class);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120005);

        $processor->process($manager, $driver, [
            'w' => 200,
            'h' => 150,
            'm' => 'unknown',
        ]);
    }

    // -----------------------------------------------------------------
    // CropProcessor::process()
    // -----------------------------------------------------------------

    public function testCropProcessorWithExplicitXY(): void
    {
        $processor = new CropProcessor();
        $manager = $this->createMock(ImageManager::class);

        /** @var DriverInterface|\PHPUnit\Framework\MockObject\MockObject $driver */
        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->once())
            ->method('crop')
            ->with(100, 50, 10, 20);

        $processor->process($manager, $driver, [
            'w' => 100,
            'h' => 50,
            'x' => 10,
            'y' => 20,
        ]);
    }

    public function testCropProcessorWithGravityUsesDriverDimensions(): void
    {
        $processor = new CropProcessor();
        $manager = $this->createMock(ImageManager::class);

        /** @var DriverInterface|\PHPUnit\Framework\MockObject\MockObject $driver */
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('getWidth')->willReturn(400);
        $driver->method('getHeight')->willReturn(300);

        $driver->expects($this->once())
            ->method('crop')
            ->with(100, 50, $this->anything(), $this->anything());

        $processor->process($manager, $driver, [
            'w' => 100,
            'h' => 50,
            'g' => 'c',
        ]);
    }

    public function testCropProcessorDimensionsZeroThrows(): void
    {
        $processor = new CropProcessor();
        $manager = $this->createMock(ImageManager::class);
        $driver = $this->createMock(DriverInterface::class);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120005);

        $processor->process($manager, $driver, [
            'w' => 0,
            'h' => 0,
        ]);
    }

    // -----------------------------------------------------------------
    // WatermarkProcessor::process()
    // -----------------------------------------------------------------

    public function testWatermarkProcessorImageMode(): void
    {
        $processor = new WatermarkProcessor();
        $manager = $this->createMock(ImageManager::class);

        /** @var DriverInterface|\PHPUnit\Framework\MockObject\MockObject $driver */
        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->once())
            ->method('watermark')
            ->with('/path/to/wm.png', 'se', 50);

        $processor->process($manager, $driver, [
            'image' => '/path/to/wm.png',
        ]);
    }

    public function testWatermarkProcessorTextMode(): void
    {
        $processor = new WatermarkProcessor();
        $manager = $this->createMock(ImageManager::class);

        /** @var DriverInterface|\PHPUnit\Framework\MockObject\MockObject $driver */
        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->once())
            ->method('textWatermark')
            ->with('Hello Watermark', $this->arrayHasKey('opacity'));

        $processor->process($manager, $driver, [
            'text' => 'Hello Watermark',
        ]);
    }

    public function testWatermarkProcessorNoImageOrTextThrows(): void
    {
        $processor = new WatermarkProcessor();
        $manager = $this->createMock(ImageManager::class);
        $driver = $this->createMock(DriverInterface::class);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120005);

        $processor->process($manager, $driver, []);
    }

    public function testWatermarkProcessorEmptyImagePathThrows(): void
    {
        $processor = new WatermarkProcessor();
        $manager = $this->createMock(ImageManager::class);
        $driver = $this->createMock(DriverInterface::class);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120005);

        $processor->process($manager, $driver, ['image' => '']);
    }

    // -----------------------------------------------------------------
    // FormatProcessor::process()
    // -----------------------------------------------------------------

    public function testFormatProcessorSetsTargetFormatOnManager(): void
    {
        $processor = new FormatProcessor();

        /** @var ImageManager|\PHPUnit\Framework\MockObject\MockObject $manager */
        $manager = $this->createMock(ImageManager::class);
        $manager->method('getConfig')->willReturn(['auto_webp' => false]);
        $manager->expects($this->once())
            ->method('setTargetFormat')
            ->with('webp');

        $driver = $this->createMock(DriverInterface::class);

        $processor->process($manager, $driver, ['f' => 'webp']);
    }

    public function testFormatProcessorInvalidFormatThrows(): void
    {
        $processor = new FormatProcessor();
        $manager = $this->createMock(ImageManager::class);
        $driver = $this->createMock(DriverInterface::class);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120003);

        $processor->process($manager, $driver, ['f' => 'tiff']);
    }

    // -----------------------------------------------------------------
    // QualityProcessor::process()
    // -----------------------------------------------------------------

    public function testQualityProcessorSetsQuality(): void
    {
        $processor = new QualityProcessor();
        /** @var ImageManager|\PHPUnit\Framework\MockObject\MockObject $manager */
        $manager = $this->createMock(ImageManager::class);
        $manager->method('getConfig')->willReturn(['default_quality' => 85]);

        /** @var DriverInterface|\PHPUnit\Framework\MockObject\MockObject $driver */
        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->once())
            ->method('setQuality')
            ->with(90);

        $processor->process($manager, $driver, ['q' => 90]);
    }

    public function testQualityProcessorOutOfRangeThrows(): void
    {
        $processor = new QualityProcessor();
        $manager = $this->createMock(ImageManager::class);
        $driver = $this->createMock(DriverInterface::class);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120005);

        $processor->process($manager, $driver, ['q' => 150]);
    }
}
