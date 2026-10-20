<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\cache\CacheManager;
use wise\image\cache\CachePathResolver;
use wise\image\command\CacheClearCommand;
use wise\image\command\CachePruneCommand;
use wise\image\command\StatsCommand;

/**
 * 缓存治理测试（item 7）
 *
 * 覆盖 CacheManager 的统计 / 清空 / 过期裁剪，以及三个命令的名称契约。
 */
class CacheGovernanceTest extends TestCase
{
    private string $tmpDir;
    private string $cacheDir;
    private string $remoteDir;

    protected function setUp(): void
    {
        $this->tmpDir    = sys_get_temp_dir() . '/wise-image-cache-' . bin2hex(random_bytes(4));
        $this->cacheDir  = $this->tmpDir . '/cache';
        $this->remoteDir = $this->tmpDir . '/remote';

        mkdir($this->cacheDir . '/ab', 0755, true);
        mkdir($this->remoteDir, 0755, true);
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

    private function writeCacheFile(string $path, int $bytes = 128, ?int $mtime = null): string
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, str_repeat('x', $bytes));
        if ($mtime !== null) {
            touch($path, $mtime);
        }

        return $path;
    }

    private function manager(): CacheManager
    {
        return new CacheManager(
            ['driver' => 'file', 'path' => $this->cacheDir],
            ['cache' => ['path' => $this->remoteDir]]
        );
    }

    // -----------------------------------------------------------------
    // 驱动与目录
    // -----------------------------------------------------------------

    public function testDriverAndFileSupport(): void
    {
        $this->assertSame('file', $this->manager()->driver());
        $this->assertTrue($this->manager()->supportsFileOperations());

        $redis = new CacheManager(['driver' => 'redis']);
        $this->assertSame('redis', $redis->driver());
        $this->assertFalse($redis->supportsFileOperations());
    }

    public function testResolveAbsolutePathIsNormalized(): void
    {
        $absolute = $this->cacheDir;

        $this->assertTrue(CachePathResolver::isAbsolute($absolute));

        $resolved = CachePathResolver::resolve($absolute);

        $this->assertTrue(CachePathResolver::isAbsolute($resolved));
        $this->assertStringEndsWith('cache', $resolved);

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->assertStringNotContainsString('/', $resolved, 'Windows 下分隔符应统一为 \\');
        }
    }

    public function testResolveRelativePathAgainstRootPath(): void
    {
        $resolved = CachePathResolver::resolve('runtime/wise-image/cache');

        $this->assertTrue(CachePathResolver::isAbsolute($resolved));
        $this->assertStringEndsWith(
            str_replace('/', DIRECTORY_SEPARATOR, 'runtime/wise-image/cache'),
            $resolved
        );
    }

    // -----------------------------------------------------------------
    // 统计
    // -----------------------------------------------------------------

    public function testStatsCountsFilesAndBytes(): void
    {
        $this->writeCacheFile($this->cacheDir . '/ab/hash1', 100);
        $this->writeCacheFile($this->cacheDir . '/ab/hash2', 200);
        $this->writeCacheFile($this->remoteDir . '/abc.img', 50);

        $stats = $this->manager()->stats();

        $this->assertSame('file', $stats['driver']);
        $this->assertSame(2, $stats['main']['files']);
        $this->assertSame(300, $stats['main']['bytes']);
        $this->assertTrue($stats['main']['exists']);
        $this->assertNotNull($stats['main']['oldest']);
        $this->assertNotNull($stats['main']['newest']);

        $this->assertSame(1, $stats['remote']['files']);
        $this->assertSame(50, $stats['remote']['bytes']);
    }

    public function testStatsHandlesMissingDirectory(): void
    {
        $manager = new CacheManager(['driver' => 'file', 'path' => $this->tmpDir . '/nope']);
        $stats = $manager->stats();

        $this->assertFalse($stats['main']['exists']);
        $this->assertSame(0, $stats['main']['files']);
        $this->assertNull($stats['main']['oldest']);
    }

    // -----------------------------------------------------------------
    // 清空
    // -----------------------------------------------------------------

    public function testClearRemovesAllFiles(): void
    {
        $this->writeCacheFile($this->cacheDir . '/ab/hash1', 100);
        $this->writeCacheFile($this->remoteDir . '/abc.img', 50);

        $result = $this->manager()->clear();

        $this->assertSame(2, $result['files']);
        $this->assertSame(150, $result['bytes']);
        $this->assertFalse($result['dry_run']);
        $this->assertFileDoesNotExist($this->cacheDir . '/ab/hash1');
        $this->assertFileDoesNotExist($this->remoteDir . '/abc.img');
    }

    public function testClearDryRunKeepsFiles(): void
    {
        $path = $this->writeCacheFile($this->cacheDir . '/ab/hash1', 100);

        $result = $this->manager()->clear(true);

        $this->assertSame(1, $result['files']);
        $this->assertTrue($result['dry_run']);
        $this->assertFileExists($path);
    }

    // -----------------------------------------------------------------
    // 过期裁剪
    // -----------------------------------------------------------------

    public function testPruneRemovesOnlyExpiredFiles(): void
    {
        $old = $this->writeCacheFile($this->cacheDir . '/ab/old', 100, time() - 40 * 86400);
        $new = $this->writeCacheFile($this->cacheDir . '/ab/new', 100, time() - 2 * 86400);

        $result = $this->manager()->prune(30);

        $this->assertSame(1, $result['files']);
        $this->assertSame(30, $result['days']);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
    }

    public function testPruneDryRunKeepsExpiredFiles(): void
    {
        $old = $this->writeCacheFile($this->cacheDir . '/ab/old', 100, time() - 40 * 86400);

        $result = $this->manager()->prune(30, true);

        $this->assertSame(1, $result['files']);
        $this->assertFileExists($old);
    }

    public function testPruneTreatsNonPositiveDaysAsOne(): void
    {
        $this->writeCacheFile($this->cacheDir . '/ab/today', 10, time() - 2 * 86400);

        $result = $this->manager()->prune(0, true);

        $this->assertSame(1, $result['days']);
        $this->assertSame(1, $result['files'], '2 天前的文件应视为超过 1 天');
    }

    // -----------------------------------------------------------------
    // 命令契约
    // -----------------------------------------------------------------

    /**
     * @dataProvider commandNameProvider
     */
    public function testCommandNames(string $class, string $expectedName): void
    {
        if (!class_exists(\think\console\Command::class)) {
            $this->markTestSkipped('think-console 不可用');
        }

        /** @var \think\console\Command $command */
        $command = new $class();

        $this->assertSame($expectedName, $command->getName());
    }

    public static function commandNameProvider(): array
    {
        return [
            'cache-clear' => [CacheClearCommand::class, 'wise:image:cache-clear'],
            'cache-prune' => [CachePruneCommand::class, 'wise:image:cache-prune'],
            'stats'       => [StatsCommand::class, 'wise:image:stats'],
        ];
    }

    public function testServiceProviderRegistersCommands(): void
    {
        $method = new \ReflectionMethod(\wise\image\ImageServiceProvider::class, 'registerCommands');
        $method->setAccessible(true);

        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(\wise\image\ImageServiceProvider::class))->getFileName()
        );

        $this->assertTrue($method->isProtected(), 'registerCommands 应为 protected');
        foreach (['CacheClearCommand', 'CachePruneCommand', 'StatsCommand'] as $class) {
            $this->assertStringContainsString($class, $source, "ServiceProvider 应注册 {$class}");
        }
    }
}
