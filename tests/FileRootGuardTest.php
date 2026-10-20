<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\exception\ErrorCode;
use wise\image\exception\ImageException;
use wise\image\ImageManager;

/**
 * 路径约束测试（item 12）
 *
 * security.file_root 非空时，本地图片必须落在该目录内，阻断目录穿越。
 */
class FileRootGuardTest extends TestCase
{
    private string $tmpDir;
    private string $rootDir;
    private string $inside;
    private string $outside;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD 扩展不可用');
        }

        $this->tmpDir  = sys_get_temp_dir() . '/wise-image-root-' . bin2hex(random_bytes(4));
        $this->rootDir = $this->tmpDir . '/allowed';
        mkdir($this->rootDir, 0755, true);

        $this->inside  = $this->makeImage($this->rootDir . '/inside.png');
        $this->outside = $this->makeImage($this->tmpDir . '/outside.png');
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

    private function makeImage(string $path): string
    {
        $img = imagecreatetruecolor(40, 20);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 10, 10, 10));
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function manager(string $fileRoot): ImageManager
    {
        return new ImageManager([
            'driver'   => 'gd',
            'cache'    => ['driver' => 'file', 'path' => $this->tmpDir . '/cache', 'prefix' => 'root:'],
            'security' => ['file_root' => $fileRoot],
        ]);
    }

    public function testAllowsImageInsideRoot(): void
    {
        $info = $this->manager($this->rootDir)->open($this->inside)->getInfo();

        $this->assertSame(40, $info['width']);
    }

    public function testRejectsImageOutsideRoot(): void
    {
        $this->expectException(ImageException::class);
        $this->expectExceptionCode(ErrorCode::FILE_ACCESS_DENIED->value);

        $this->manager($this->rootDir)->open($this->outside);
    }

    public function testRejectsTraversalEscapingRoot(): void
    {
        $this->expectException(ImageException::class);
        $this->expectExceptionCode(ErrorCode::FILE_ACCESS_DENIED->value);

        // allowed/../outside.png → realpath 落在根目录之外
        $this->manager($this->rootDir)->open($this->rootDir . '/../outside.png');
    }

    public function testRejectsWhenRootDoesNotExist(): void
    {
        $this->expectException(ImageException::class);
        $this->expectExceptionCode(ErrorCode::FILE_ACCESS_DENIED->value);

        $this->manager($this->tmpDir . '/not-exists')->open($this->inside);
    }

    public function testEmptyRootKeepsBackwardCompatibility(): void
    {
        $info = $this->manager('')->open($this->outside)->getInfo();

        $this->assertSame(40, $info['width']);
    }

    public function testRootItselfIsAllowed(): void
    {
        // file_root 指向的目录本身（realpath 相等）不应被判定为越权
        $manager = $this->manager($this->rootDir);

        $this->expectException(ImageException::class);
        // 目录不是文件 → 仍由 fileNotFound 拦下，而不是 FILE_ACCESS_DENIED
        $this->expectExceptionCode(ErrorCode::FILE_NOT_FOUND->value);

        $manager->open($this->rootDir);
    }
}
