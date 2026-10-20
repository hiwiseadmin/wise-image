<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\exception\ErrorCode;
use wise\image\exception\ImageException;

/**
 * ImageException 全面测试
 *
 * 验证 ErrorCode 枚举（号段 120000~129999）与全部静态工厂方法。
 * 注意：错误码统一定义在 {@see ErrorCode} 枚举中，ImageException 不再定义 ERR_* 常量。
 */
class ExceptionTest extends TestCase
{
    // -----------------------------------------------------------------
    // 错误码枚举
    // -----------------------------------------------------------------

    public function testErrorCodeEnumValues(): void
    {
        $this->assertSame(120001, ErrorCode::DRIVER_NOT_FOUND->value);
        $this->assertSame(120002, ErrorCode::FILE_NOT_FOUND->value);
        $this->assertSame(120003, ErrorCode::INVALID_FORMAT->value);
        $this->assertSame(120004, ErrorCode::PROCESS_FAILED->value);
        $this->assertSame(120005, ErrorCode::PARAM_INVALID->value);
        $this->assertSame(120006, ErrorCode::SIGN_INVALID->value);
        $this->assertSame(120007, ErrorCode::REFERER_DENIED->value);
        $this->assertSame(120008, ErrorCode::STYLE_NOT_FOUND->value);
        $this->assertSame(120009, ErrorCode::DRIVER_NOT_READY->value);
        $this->assertSame(120010, ErrorCode::CACHE_FAILED->value);
        $this->assertSame(120011, ErrorCode::REMOTE_DISABLED->value);
        $this->assertSame(120012, ErrorCode::REMOTE_FETCH_FAILED->value);
        $this->assertSame(120013, ErrorCode::REMOTE_HOST_DENIED->value);
        $this->assertSame(120014, ErrorCode::REMOTE_TOO_LARGE->value);
        $this->assertSame(120015, ErrorCode::REMOTE_INVALID_TYPE->value);
        $this->assertSame(120016, ErrorCode::SIGN_NOT_CONFIGURED->value);
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

    public static function factoryDataProvider(): array
    {
        return [
            'driverNotFound' => [
                'method' => 'driverNotFound',
                'args' => ['imagick'],
                'expectedCode' => 120001,
                'expectedMessageContains' => '图片驱动未找到',
            ],
            'fileNotFound' => [
                'method' => 'fileNotFound',
                'args' => ['/nonexistent/image.jpg'],
                'expectedCode' => 120002,
                'expectedMessageContains' => '图片文件未找到',
            ],
            'invalidFormat' => [
                'method' => 'invalidFormat',
                'args' => ['tiff'],
                'expectedCode' => 120003,
                'expectedMessageContains' => '不支持的图片格式',
            ],
            'processFailed' => [
                'method' => 'processFailed',
                'args' => ['resize', '内存不足'],
                'expectedCode' => 120004,
                'expectedMessageContains' => '图片处理失败',
            ],
            'paramInvalid' => [
                'method' => 'paramInvalid',
                'args' => ['宽度必须大于 0'],
                'expectedCode' => 120005,
                'expectedMessageContains' => '参数无效',
            ],
            'signInvalid' => [
                'method' => 'signInvalid',
                'args' => [],
                'expectedCode' => 120006,
                'expectedMessageContains' => '签名验证失败',
            ],
            'refererDenied' => [
                'method' => 'refererDenied',
                'args' => [],
                'expectedCode' => 120007,
                'expectedMessageContains' => '防盗链',
            ],
            'styleNotFound' => [
                'method' => 'styleNotFound',
                'args' => ['xlarge'],
                'expectedCode' => 120008,
                'expectedMessageContains' => '预定义样式未找到',
            ],
            'driverNotReady' => [
                'method' => 'driverNotReady',
                'args' => ['GD 驱动未加载任何图片'],
                'expectedCode' => 120009,
                'expectedMessageContains' => '驱动未就绪',
            ],
            'cacheFailed' => [
                'method' => 'cacheFailed',
                'args' => ['write', '磁盘空间不足'],
                'expectedCode' => 120010,
                'expectedMessageContains' => '缓存操作失败',
            ],
            'remoteDisabled' => [
                'method' => 'remoteDisabled',
                'args' => [],
                'expectedCode' => 120011,
                'expectedMessageContains' => '远程图片抓取功能未启用',
            ],
            'remoteFetchFailed' => [
                'method' => 'remoteFetchFailed',
                'args' => ['https://cdn.example.com/a.jpg', '连接超时'],
                'expectedCode' => 120012,
                'expectedMessageContains' => '远程图片下载失败',
            ],
            'remoteHostDenied' => [
                'method' => 'remoteHostDenied',
                'args' => ['http://127.0.0.1/a.jpg', '目标解析到内网地址'],
                'expectedCode' => 120013,
                'expectedMessageContains' => '远程图片主机被拒绝',
            ],
            'remoteTooLarge' => [
                'method' => 'remoteTooLarge',
                'args' => ['https://cdn.example.com/a.jpg', 2048, 1024],
                'expectedCode' => 120014,
                'expectedMessageContains' => '超出大小限制',
            ],
            'remoteInvalidType' => [
                'method' => 'remoteInvalidType',
                'args' => ['https://cdn.example.com/a.txt', 'text/plain'],
                'expectedCode' => 120015,
                'expectedMessageContains' => '不是受支持的图片类型',
            ],
            'signNotConfigured' => [
                'method' => 'signNotConfigured',
                'args' => [],
                'expectedCode' => 120016,
                'expectedMessageContains' => '签名密钥未配置',
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

    public function testRemoteContexts(): void
    {
        $e = ImageException::remoteHostDenied('http://127.0.0.1/a.jpg', '内网地址');
        $this->assertSame(
            ['url' => 'http://127.0.0.1/a.jpg', 'reason' => '内网地址'],
            $e->getContext()
        );

        $e2 = ImageException::remoteTooLarge('https://cdn.example.com/a.jpg', 2048, 1024);
        $this->assertSame(
            ['url' => 'https://cdn.example.com/a.jpg', 'size' => 2048, 'max_size' => 1024],
            $e2->getContext()
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

    public function testHttpCodes(): void
    {
        $this->assertSame(404, ImageException::fileNotFound('/a.jpg')->getHttpCode());
        $this->assertSame(403, ImageException::refererDenied()->getHttpCode());
        $this->assertSame(403, ImageException::remoteDisabled()->getHttpCode());
        $this->assertSame(502, ImageException::remoteFetchFailed('http://e.com/a.jpg', '超时')->getHttpCode());
        $this->assertSame(413, ImageException::remoteTooLarge('http://e.com/a.jpg', 2, 1)->getHttpCode());
        $this->assertSame(415, ImageException::remoteInvalidType('http://e.com/a.txt', 'text/plain')->getHttpCode());
        $this->assertSame(500, ImageException::signNotConfigured()->getHttpCode());
    }

    public function testConstructorDirect(): void
    {
        $e = new ImageException('自定义错误', 9999, ['custom' => 'data']);
        $this->assertSame('自定义错误', $e->getMessage());
        $this->assertSame(9999, $e->getCode());
        $this->assertSame(['custom' => 'data'], $e->getContext());
    }
}
