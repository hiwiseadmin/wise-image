<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\exception\ImageException;

/**
 * ImageException 全面测试
 *
 * 验证所有 10 个错误码常量 + 10 个静态工厂方法。
 */
class ExceptionTest extends TestCase
{
    // -----------------------------------------------------------------
    // 错误码常量
    // -----------------------------------------------------------------

    public function testErrorCodeConstants(): void
    {
        $this->assertSame(1001, ImageException::ERR_DRIVER_NOT_FOUND);
        $this->assertSame(1002, ImageException::ERR_FILE_NOT_FOUND);
        $this->assertSame(1003, ImageException::ERR_INVALID_FORMAT);
        $this->assertSame(1004, ImageException::ERR_PROCESS_FAILED);
        $this->assertSame(1005, ImageException::ERR_PARAM_INVALID);
        $this->assertSame(1006, ImageException::ERR_SIGN_INVALID);
        $this->assertSame(1007, ImageException::ERR_REFERER_DENIED);
        $this->assertSame(1008, ImageException::ERR_STYLE_NOT_FOUND);
        $this->assertSame(1009, ImageException::ERR_DRIVER_NOT_READY);
        $this->assertSame(1010, ImageException::ERR_CACHE_FAILED);
    }

    // -----------------------------------------------------------------
    // 静态工厂方法 - 正常创建
    // -----------------------------------------------------------------

    /**
     * @dataProvider factoryDataProvider
     */
    public function testFactoryMethod(
        string $method,
        array $args,
        int $expectedCode,
        string $expectedMessageContains
    ): void {
        /** @var ImageException $exception */
        $exception = call_user_func_array([ImageException::class, $method], $args);

        $this->assertInstanceOf(ImageException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame($expectedCode, $exception->getCode());
        $this->assertStringContainsString($expectedMessageContains, $exception->getMessage());
    }

    public function factoryDataProvider(): array
    {
        return [
            'driverNotFound' => [
                'method' => 'driverNotFound',
                'args' => ['imagick'],
                'expectedCode' => 1001,
                'expectedMessageContains' => '图片驱动未找到',
            ],
            'fileNotFound' => [
                'method' => 'fileNotFound',
                'args' => ['/nonexistent/image.jpg'],
                'expectedCode' => 1002,
                'expectedMessageContains' => '图片文件未找到',
            ],
            'invalidFormat' => [
                'method' => 'invalidFormat',
                'args' => ['tiff'],
                'expectedCode' => 1003,
                'expectedMessageContains' => '不支持的图片格式',
            ],
            'processFailed' => [
                'method' => 'processFailed',
                'args' => ['resize', '内存不足'],
                'expectedCode' => 1004,
                'expectedMessageContains' => '图片处理失败',
            ],
            'paramInvalid' => [
                'method' => 'paramInvalid',
                'args' => ['宽度必须大于 0'],
                'expectedCode' => 1005,
                'expectedMessageContains' => '参数无效',
            ],
            'signInvalid' => [
                'method' => 'signInvalid',
                'args' => [],
                'expectedCode' => 1006,
                'expectedMessageContains' => '签名验证失败',
            ],
            'refererDenied' => [
                'method' => 'refererDenied',
                'args' => [],
                'expectedCode' => 1007,
                'expectedMessageContains' => '防盗链',
            ],
            'styleNotFound' => [
                'method' => 'styleNotFound',
                'args' => ['xlarge'],
                'expectedCode' => 1008,
                'expectedMessageContains' => '预定义样式未找到',
            ],
            'driverNotReady' => [
                'method' => 'driverNotReady',
                'args' => ['GD 驱动未加载任何图片'],
                'expectedCode' => 1009,
                'expectedMessageContains' => '驱动未就绪',
            ],
            'cacheFailed' => [
                'method' => 'cacheFailed',
                'args' => ['write', '磁盘空间不足'],
                'expectedCode' => 1010,
                'expectedMessageContains' => '缓存操作失败',
            ],
        ];
    }

    // -----------------------------------------------------------------
    // 上下文信息 (getContext)
    // -----------------------------------------------------------------

    public function testDriverNotFoundContext(): void
    {
        $e = ImageException::driverNotFound('imagick');
        $this->assertSame(['driver' => 'imagick'], $e->getContext());
    }

    public function testFileNotFoundContext(): void
    {
        $e = ImageException::fileNotFound('/tmp/test.png');
        $this->assertSame(['path' => '/tmp/test.png'], $e->getContext());
    }

    public function testInvalidFormatContext(): void
    {
        $e = ImageException::invalidFormat('tiff');
        $this->assertSame(['format' => 'tiff'], $e->getContext());
    }

    public function testProcessFailedContext(): void
    {
        $e = ImageException::processFailed('open', '无法加载图片文件');
        $this->assertSame(
            ['operation' => 'open', 'reason' => '无法加载图片文件'],
            $e->getContext()
        );
    }

    public function testParamInvalidContext(): void
    {
        $e = ImageException::paramInvalid('宽度必须大于 0');
        $this->assertSame(['detail' => '宽度必须大于 0'], $e->getContext());
    }

    public function testStyleNotFoundContext(): void
    {
        $e = ImageException::styleNotFound('xlarge');
        $this->assertSame(['style_name' => 'xlarge'], $e->getContext());
    }

    public function testDriverNotReadyContext(): void
    {
        $e = ImageException::driverNotReady('GD 驱动未加载任何图片');
        $this->assertSame(['reason' => 'GD 驱动未加载任何图片'], $e->getContext());
    }

    public function testCacheFailedContext(): void
    {
        $e = ImageException::cacheFailed('write', '磁盘空间不足');
        $this->assertSame(
            ['operation' => 'write', 'reason' => '磁盘空间不足'],
            $e->getContext()
        );
    }

    // -----------------------------------------------------------------
    // 边缘场景
    // -----------------------------------------------------------------

    public function testSignInvalidAndRefererDeniedHaveEmptyContext(): void
    {
        $e1 = ImageException::signInvalid();
        $this->assertSame([], $e1->getContext());

        $e2 = ImageException::refererDenied();
        $this->assertSame([], $e2->getContext());
    }

    public function testConstructorDirect(): void
    {
        $e = new ImageException('自定义错误', 9999, ['custom' => 'data']);
        $this->assertSame('自定义错误', $e->getMessage());
        $this->assertSame(9999, $e->getCode());
        $this->assertSame(['custom' => 'data'], $e->getContext());
    }
}
