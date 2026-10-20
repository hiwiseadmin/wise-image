<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * 链式处理 API 测试
 *
 * 覆盖 README / PRD 中主推的构建器写法：
 *   Image::open($path)->resize(800, 600)->watermark(...)->quality(90)->save($out);
 */
class ChainApiTest extends TestCase
{
    private string $tmpDir;

    /** @var array<string, mixed> */
    private array $baseConfig;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD 扩展不可用');
        }

        $this->tmpDir = sys_get_temp_dir() . '/wise-image-chain-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);

        $this->baseConfig = [
            'driver' => 'gd',
            'cache'  => ['driver' => 'file', 'path' => $this->tmpDir . '/cache', 'prefix' => 'chain:'],
        ];
    }

    protected function tearDown(): void
    {
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
    // 辅助方法
    // -----------------------------------------------------------------

    private function manager(array $config = []): ImageManager
    {
        return new ImageManager(array_merge($this->baseConfig, $config));
    }

    private function source(int $width = 200, int $height = 100): string
    {
        $path = $this->tmpDir . "/src_{$width}x{$height}.png";
        if (is_file($path)) {
            return $path;
        }

        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 30, 30));
        imagerectangle($img, 5, 5, max(6, $width - 10), max(6, $height - 10), (int) imagecolorallocate($img, 0, 0, 255));
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    private function dims(string $path): array
    {
        $info = getimagesize($path);
        $this->assertIsArray($info, "无法读取输出图片：{$path}");

        return [(int) $info[0], (int) $info[1], (string) $info['mime']];
    }

    // -----------------------------------------------------------------
    // 缩放
    // -----------------------------------------------------------------

    public function testResize(): void
    {
        $out = $this->tmpDir . '/resize.png';
        $this->manager()->open($this->source())->resize(100, 50)->save($out);

        $this->assertSame([100, 50], array_slice($this->dims($out), 0, 2));
    }

    public function testResizeWidthOnlyKeepsAspectRatio(): void
    {
        $out = $this->tmpDir . '/width.png';
        $this->manager()->open($this->source())->width(80)->save($out);

        $this->assertSame([80, 40], array_slice($this->dims($out), 0, 2));
    }

    public function testResizeHeightOnlyKeepsAspectRatio(): void
    {
        $out = $this->tmpDir . '/height.png';
        $this->manager()->open($this->source())->height(40)->save($out);

        $this->assertSame([80, 40], array_slice($this->dims($out), 0, 2));
    }

    /**
     * @dataProvider resizeModeAliasProvider
     */
    public function testResizeModeAlias(string $mode, array $expected): void
    {
        $out = $this->tmpDir . "/mode_{$mode}.png";
        $this->manager()->open($this->source())->resize(100, 100, $mode)->save($out);

        $this->assertSame($expected, array_slice($this->dims($out), 0, 2));
    }

    public static function resizeModeAliasProvider(): array
    {
        return [
            'lfit'    => ['lfit', [100, 50]],
            'contain' => ['contain', [100, 50]],
            'fit'     => ['fit', [100, 50]],
            'fill'    => ['fill', [100, 100]],
            'cover'   => ['cover', [100, 100]],
            'fixed'   => ['fixed', [100, 100]],
        ];
    }

    // -----------------------------------------------------------------
    // 裁剪 / 旋转
    // -----------------------------------------------------------------

    public function testCrop(): void
    {
        $out = $this->tmpDir . '/crop.png';
        $this->manager()->open($this->source())->crop(50, 50, 10, 10)->save($out);

        $this->assertSame([50, 50], array_slice($this->dims($out), 0, 2));
    }

    public function testCropByGravity(): void
    {
        $out = $this->tmpDir . '/crop_gravity.png';
        $this->manager()->open($this->source())->cropByGravity(50, 50, 'center')->save($out);

        $this->assertSame([50, 50], array_slice($this->dims($out), 0, 2));
    }

    public function testRotateSwapsDimensions(): void
    {
        $out = $this->tmpDir . '/rotate.png';
        $this->manager()->open($this->source())->rotate(90)->save($out);

        $this->assertSame([100, 200], array_slice($this->dims($out), 0, 2));
    }

    // -----------------------------------------------------------------
    // 效果与格式
    // -----------------------------------------------------------------

    public function testBlurBrightnessContrast(): void
    {
        $out = $this->tmpDir . '/effects.png';
        $this->manager()->open($this->source())
            ->blur(3)
            ->brightness(10)
            ->contrast(5)
            ->save($out);

        $this->assertSame([200, 100], array_slice($this->dims($out), 0, 2));
    }

    public function testFormatAndQuality(): void
    {
        $out = $this->tmpDir . '/format.jpg';
        $this->manager()->open($this->source())->format('jpg')->quality(90)->save($out);

        $this->assertSame('image/jpeg', $this->dims($out)[2]);
    }

    public function testWatermarkAcceptsLongPositionName(): void
    {
        $out = $this->tmpDir . '/watermark.png';
        $logo = __DIR__ . '/fixtures/watermark_60x40.png';

        $this->manager()->open($this->source())
            ->watermark($logo, 'southeast', 50)
            ->save($out);

        $this->assertSame([200, 100], array_slice($this->dims($out), 0, 2));
    }

    // -----------------------------------------------------------------
    // 组合与兼容性
    // -----------------------------------------------------------------

    public function testFullChainFromReadme(): void
    {
        $out  = $this->tmpDir . '/full.png';
        $logo = __DIR__ . '/fixtures/watermark_60x40.png';

        $this->manager()->open($this->source())
            ->resize(120, 80)
            ->watermark($logo, 'southeast', 50)
            ->blur(3)
            ->brightness(10)
            ->contrast(5)
            ->quality(80)
            ->save($out);

        $this->assertFileExists($out);
        // 200x100 按 lfit 缩放进 120x80 的框 → 取更小缩放比 0.6 → 120x60
        $this->assertSame([120, 60], array_slice($this->dims($out), 0, 2));
    }

    public function testStyleStillWorks(): void
    {
        $out = $this->tmpDir . '/style.png';
        $this->manager(['styles' => ['thumb' => 'resize,w_50,h_50']])
            ->open($this->source())
            ->style('thumb')
            ->save($out);

        $this->assertSame([50, 25], array_slice($this->dims($out), 0, 2));
    }

    public function testParseUrlParamsStillWorks(): void
    {
        $out = $this->tmpDir . '/parse.png';
        $this->manager()->open($this->source())
            ->parseUrlParams('resize,w_60,h_30/quality,q_80')
            ->save($out);

        $this->assertSame([60, 30], array_slice($this->dims($out), 0, 2));
    }

    // -----------------------------------------------------------------
    // 参数校验仍生效
    // -----------------------------------------------------------------

    public function testInvalidResizeModeThrows(): void
    {
        $this->expectException(ImageException::class);

        $this->manager()->open($this->source())->resize(10, 10, 'bogus')->execute();
    }

    public function testQualityOutOfRangeThrows(): void
    {
        $this->expectException(ImageException::class);

        $this->manager()->open($this->source())->quality(200)->execute();
    }

    public function testBlurOutOfRangeThrows(): void
    {
        $this->expectException(ImageException::class);

        $this->manager()->open($this->source())->blur(0)->execute();
    }
}
