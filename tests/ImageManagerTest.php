<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * ImageManager 全面测试
 *
 * 覆盖：
 * - 构建器模式（open / make / addOperation / parseUrlParams / style）
 * - 执行引擎（execute）
 * - 输出（save / output）
 * - URL 参数解析
 * - 预定义样式
 * - 安全中间件（IP 黑名单 / Referer / 签名）
 * - 缓存
 * - 错误处理（未知操作 / 不存在的样式 / 文件不存在）
 */
class ImageManagerTest extends TestCase
{
    private string $fixturesDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
        $this->tmpDir = sys_get_temp_dir() . '/wise-image-tests';

        if (!is_dir($this->fixturesDir)) {
            mkdir($this->fixturesDir, 0755, true);
        }
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0755, true);
        }
    }

    // -----------------------------------------------------------------
    // 辅助方法
    // -----------------------------------------------------------------

    private function createTestImage(int $width = 200, int $height = 100): string
    {
        $path = $this->fixturesDir . '/imgmgr_' . $width . 'x' . $height . '.png';
        if (!file_exists($path)) {
            $img = imagecreatetruecolor($width, $height);
            if ($img === false) {
                $this->markTestSkipped('GD 无法创建测试图片');
            }
            $red = imagecolorallocate($img, 255, 0, 0);
            if ($red !== false) {
                imagefill($img, 0, 0, $red);
            }
            $blue = imagecolorallocate($img, 0, 0, 255);
            if ($blue !== false) {
                imagefilledrectangle($img, 30, 15, 170, 85, $blue);
            }
            imagepng($img, $path);
            imagedestroy($img);
        }
        return $path;
    }

    /**
     * Create a default ImageManager with safe config
     */
    private function createManager(array $extraConfig = []): ImageManager
    {
        return new ImageManager(array_merge([
            'driver' => 'gd',
            'default_quality' => 85,
            'auto_webp' => false,
            'cache' => [
                'driver' => 'file',
                'ttl' => 2592000,
                'path' => $this->tmpDir . '/cache',
                'prefix' => 'wise_image:',
            ],
            'security' => [
                'ip_blacklist' => ['enabled' => false, 'ips' => []],
                'referer' => ['enabled' => false, 'allow_empty' => true, 'whitelist' => [], 'blacklist' => []],
                'signature' => ['enabled' => false, 'secret' => '', 'timeout' => 3600],
                'origin_protect' => ['enabled' => false],
                'max_width' => 4096,
                'max_height' => 4096,
                'allowed_formats' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
            ],
            'styles' => [
                'thumb' => 'resize,w_200,h_200/quality,q_80',
                'medium' => 'resize,w_800,h_600/quality,q_85',
                'large' => 'resize,w_1920,h_1080/quality,q_90',
            ],
            'font_path' => null,
            'route_prefix' => '/image',
        ], $extraConfig));
    }

    // -----------------------------------------------------------------
    // 构建器：open()
    // -----------------------------------------------------------------

    public function testOpenReturnsSelf(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();
        $result = $manager->open($path);

        $this->assertSame($manager, $result);
    }

    public function testOpenFileNotFoundThrows(): void
    {
        $manager = $this->createManager();

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120002);

        $manager->open('/nonexistent/path.jpg');
    }

    // -----------------------------------------------------------------
    // 构建器：make()
    // -----------------------------------------------------------------

    public function testMakeReturnsSelf(): void
    {
        $manager = $this->createManager();
        $result = $manager->make(300, 200);

        $this->assertSame($manager, $result);
    }

    public function testMakeCreatesCanvasAndCanOutput(): void
    {
        $manager = $this->createManager();
        $data = $manager->make(100, 60)->output('png');

        $this->assertNotEmpty($data);
        $this->assertStringStartsWith("\x89PNG", $data);
    }

    // -----------------------------------------------------------------
    // 构建器：parseUrlParams()
    // -----------------------------------------------------------------

    public function testParseUrlParamsReturnsSelf(): void
    {
        $manager = $this->createManager();
        $result = $manager->parseUrlParams('resize,w_200,h_200');

        $this->assertSame($manager, $result);
    }

    public function testParseUrlParamsSingleOperation(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();

        $data = $manager
            ->open($path)
            ->parseUrlParams('resize,w_100,h_50')
            ->output('png');

        $this->assertNotEmpty($data);
        $this->assertStringStartsWith("\x89PNG", $data);
    }

    public function testParseUrlParamsMultipleOperations(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();

        // 三个操作：resize + quality + format
        $data = $manager
            ->open($path)
            ->parseUrlParams('resize,w_100,h_50/quality,q_90/format,f_png')
            ->output();

        $this->assertNotEmpty($data);
    }

    public function testParseUrlParamsResizeFillMode(): void
    {
        $path = $this->createTestImage(400, 200);
        $manager = $this->createManager();

        $manager->open($path)
            ->parseUrlParams('resize,w_100,h_100,m_fill')
            ->execute();

        $info = $manager->getInfo();
        $this->assertSame(100, $info['width']);
        $this->assertSame(100, $info['height']);
    }

    public function testParseUrlParamsEmptyString(): void
    {
        $manager = $this->createManager();
        $result = $manager->parseUrlParams('');

        $this->assertSame($manager, $result);
    }

    public function testParseUrlParamsEmptyStringOnlySlashes(): void
    {
        $manager = $this->createManager();
        $result = $manager->parseUrlParams('///');

        $this->assertSame($manager, $result);
    }

    public function testParseUrlParamsUnknownOperationThrows(): void
    {
        $manager = $this->createManager();

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120005);

        $manager->parseUrlParams('nonexist,w_90');
    }

    // -----------------------------------------------------------------
    // 构建器：style()
    // -----------------------------------------------------------------

    public function testStyleReturnsSelf(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();
        $result = $manager->open($path)->style('thumb');

        $this->assertSame($manager, $result);
    }

    public function testStyleThumbAppliesResizeAndQuality(): void
    {
        $path = $this->createTestImage(400, 300);
        $manager = $this->createManager();

        $manager->open($path)->style('thumb')->execute();

        // thumb = resize,w_200,h_200/quality,q_80
        $info = $manager->getInfo();
        $this->assertLessThanOrEqual(200, $info['width']);
        $this->assertLessThanOrEqual(200, $info['height']);
    }

    public function testStyleNotFoundThrows(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120008);

        $manager->open($path)->style('nonexistent');
    }

    // -----------------------------------------------------------------
    // 执行与输出
    // -----------------------------------------------------------------

    public function testSaveAutoExecutes(): void
    {
        $path = $this->createTestImage(200, 100);
        $outputPath = $this->tmpDir . '/save_test.png';
        $manager = $this->createManager();

        $result = $manager
            ->open($path)
            ->parseUrlParams('resize,w_50,h_25')
            ->save($outputPath, 'png');

        $this->assertTrue($result);
        $this->assertFileExists($outputPath);

        $info = getimagesize($outputPath);
        $this->assertNotFalse($info);
        $this->assertSame(50, $info[0]);

        @unlink($outputPath);
    }

    public function testOutputAutoExecutes(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();

        $data = $manager
            ->open($path)
            ->parseUrlParams('resize,w_50,h_25')
            ->output('png');

        $this->assertNotEmpty($data);
        $this->assertStringStartsWith("\x89PNG", $data);
    }

    public function testGetInfoAfterExecute(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();

        $info = $manager
            ->open($path)
            ->parseUrlParams('resize,w_100,h_50')
            ->getInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('width', $info);
        $this->assertArrayHasKey('height', $info);
        $this->assertArrayHasKey('mime', $info);
        $this->assertArrayHasKey('format', $info);
        $this->assertArrayHasKey('driver', $info);
        $this->assertSame(100, $info['width']);
        $this->assertSame(50, $info['height']);
    }

    // -----------------------------------------------------------------
    // 格式转换
    // -----------------------------------------------------------------

    public function testParseUrlParamsFormatConvertsToJpeg(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();

        $data = $manager
            ->open($path)
            ->parseUrlParams('format,f_jpeg')
            ->output();

        $this->assertStringStartsWith("\xFF\xD8", $data);
    }

    // -----------------------------------------------------------------
    // addOperation() 手动添加处理器
    // -----------------------------------------------------------------

    public function testAddOperationReturnsSelf(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();

        $processor = new \wise\image\processor\ResizeProcessor();
        $result = $manager->open($path)->addOperation($processor, ['w' => 100, 'h' => 50]);

        $this->assertSame($manager, $result);
    }

    // -----------------------------------------------------------------
    // 安全：IP 黑名单
    // -----------------------------------------------------------------

    public function testIpBlacklistBlocksMatchedIp(): void
    {
        $manager = $this->createManager([
            'security' => [
                'ip_blacklist' => [
                    'enabled' => true,
                    'ips' => ['192.168.1.100'],
                ],
                'referer' => ['enabled' => false],
                'signature' => ['enabled' => false],
            ],
        ]);

        // 模拟客户端 IP 在黑名单中
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $path = $this->createTestImage(200, 100);

        $this->expectException(ImageException::class);

        try {
            $manager->open($path)->execute();
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
        }
    }

    public function testIpBlacklistAllowsDifferentIp(): void
    {
        $manager = $this->createManager([
            'security' => [
                'ip_blacklist' => [
                    'enabled' => true,
                    'ips' => ['192.168.1.100'],
                ],
                'referer' => ['enabled' => false],
                'signature' => ['enabled' => false],
            ],
        ]);

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $path = $this->createTestImage(200, 100);

        try {
            // 应不抛异常（IP 不在黑名单中）
            $manager->open($path)->execute();
            $this->assertTrue(true);
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
        }
    }

    // -----------------------------------------------------------------
    // 安全：签名验证
    // -----------------------------------------------------------------

    public function testSignatureMissingSignThrows(): void
    {
        $manager = $this->createManager([
            'security' => [
                'ip_blacklist' => ['enabled' => false],
                'referer' => ['enabled' => false],
                'signature' => [
                    'enabled' => true,
                    'secret' => 'test_secret_key',
                    'timeout' => 3600,
                ],
            ],
        ]);

        $_GET = [];
        $path = $this->createTestImage(200, 100);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120006);

        try {
            $manager->open($path)->execute();
        } finally {
            $_GET = [];
        }
    }

    public function testSignatureInvalidThrows(): void
    {
        $manager = $this->createManager([
            'security' => [
                'ip_blacklist' => ['enabled' => false],
                'referer' => ['enabled' => false],
                'signature' => [
                    'enabled' => true,
                    'secret' => 'test_secret_key',
                    'timeout' => 3600,
                ],
            ],
        ]);

        $_GET = ['sign' => 'wrong_signature'];
        $path = $this->createTestImage(200, 100);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120006);

        try {
            $manager->open($path)->execute();
        } finally {
            $_GET = [];
        }
    }

    // -----------------------------------------------------------------
    // 安全：Referer 防盗链
    // -----------------------------------------------------------------

    public function testRefererAllowsEmptyWhenConfigured(): void
    {
        $manager = $this->createManager([
            'security' => [
                'ip_blacklist' => ['enabled' => false],
                'referer' => [
                    'enabled' => true,
                    'allow_empty' => true,
                    'whitelist' => ['*.example.com'],
                    'blacklist' => [],
                ],
                'signature' => ['enabled' => false],
            ],
        ]);

        unset($_SERVER['HTTP_REFERER']);
        $path = $this->createTestImage(200, 100);

        try {
            $manager->open($path)->execute();
            $this->assertTrue(true);
        } finally {
            unset($_SERVER['HTTP_REFERER']);
        }
    }

    // -----------------------------------------------------------------
    // 缓存
    // -----------------------------------------------------------------

    public function testFileCacheHitOnRepeatRequest(): void
    {
        $path = $this->createTestImage(200, 100);

        // 第一次请求：生成并缓存
        $manager1 = $this->createManager();
        $data1 = $manager1
            ->open($path)
            ->parseUrlParams('resize,w_50,h_25')
            ->output('png');

        // 第二次请求：应从缓存命中
        $manager2 = $this->createManager();
        $data2 = $manager2
            ->open($path)
            ->parseUrlParams('resize,w_50,h_25')
            ->output('png');

        $this->assertSame($data1, $data2, '缓存命中时两次输出应相同');
    }

    // -----------------------------------------------------------------
    // 边缘场景
    // -----------------------------------------------------------------

    public function testStyleNotFoundThrowsCorrectCode(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();

        try {
            $manager->open($path)->style('xlarge');
            $this->fail('应抛出异常');
        } catch (ImageException $e) {
            $this->assertSame(120008, $e->getCode());
            $this->assertStringContainsString('xlarge', $e->getMessage());
        }
    }

    public function testUnknownOperationInUrlThrows(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120005);

        $manager->open($path)->parseUrlParams('nonexist,w_90');
    }

    public function testDestroyAndReopen(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager();

        $manager->open($path)->execute();
        $manager->destroy();

        // 重新打开
        $manager->open($path)->execute();

        $info = $manager->getInfo();
        $this->assertSame(200, $info['width']);
    }

    // -----------------------------------------------------------------
    // getConfig() / getTargetFormat() / setTargetFormat()
    // -----------------------------------------------------------------

    public function testGetConfigReturnsMergedConfig(): void
    {
        $manager = $this->createManager();
        $config = $manager->getConfig();

        $this->assertIsArray($config);
        $this->assertSame('gd', $config['driver']);
    }

    public function testSetAndGetTargetFormat(): void
    {
        $manager = $this->createManager();

        $this->assertNull($manager->getTargetFormat());

        $manager->setTargetFormat('webp');
        $this->assertSame('webp', $manager->getTargetFormat());
    }

    // -----------------------------------------------------------------
    // 驱动切换
    // -----------------------------------------------------------------

    public function testImagickDriverNotFoundThrows(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager(['driver' => 'imagick']);

        if (!extension_loaded('imagick')) {
            $this->expectException(ImageException::class);
            $this->expectExceptionCode(120001);
        }

        $manager->open($path)->execute();
    }

    public function testUnknownDriverThrows(): void
    {
        $path = $this->createTestImage(200, 100);
        $manager = $this->createManager(['driver' => 'nonexistent_driver']);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120001);

        $manager->open($path)->execute();
    }
}
