<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\exception\ErrorCode;
use wise\image\exception\ImageException;
use wise\image\remote\RemoteFetcher;

/**
 * RemoteFetcher 单元测试
 *
 * 覆盖：
 * - 开关与配置深度合并
 * - SSRF 防护（协议 / 白名单 / 黑名单 / 内网与保留地址）
 * - 下载流程（成功 / 超大 / 非图片 / 重定向 / HTTP 错误）
 * - 本地缓存复用与强制刷新
 *
 * 测试通过匿名子类覆写 requestOnce() 注入响应，全程不依赖外网。
 */
class RemoteFetcherTest extends TestCase
{
    /** 1x1 透明 PNG（base64） */
    public const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-image-remote-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    // -----------------------------------------------------------------
    // 辅助方法
    // -----------------------------------------------------------------

    private function pngBytes(): string
    {
        return (string) base64_decode(self::PNG_1X1, true);
    }

    /**
     * 创建测试替身：绕过 DNS 与真实网络
     *
     * @param \Closure|null $responder fn(string $url, array $target, int $maxSize): array{0:int,1:array,2:string}
     */
    private function makeFetcher(array $config, ?\Closure $responder = null): RemoteFetcher
    {
        return new class($config, $responder) extends RemoteFetcher {
            /** @var \Closure|null */
            public $responder;

            /** @var array<int, array<string, mixed>> */
            public array $requested = [];

            /** @var string[] */
            public array $validated = [];

            public function __construct(array $config, ?\Closure $responder = null)
            {
                parent::__construct($config);
                $this->responder = $responder;
            }

            public function validateTarget(string $url): array
            {
                $this->validated[] = $url;

                return ['host' => 'cdn.example.com', 'port' => 443, 'ips' => ['93.184.216.34']];
            }

            protected function requestOnce(string $url, array $target, int $maxSize): array
            {
                $this->requested[] = ['url' => $url, 'target' => $target, 'max_size' => $maxSize];

                if ($this->responder !== null) {
                    return ($this->responder)($url, $target, $maxSize);
                }

                $png = (string) base64_decode(\wise\image\tests\RemoteFetcherTest::PNG_1X1, true);

                return [200, ['content-type' => 'image/png'], $png];
            }
        };
    }

    private function baseConfig(array $extra = []): array
    {
        return array_merge([
            'enabled' => true,
            'cache'   => ['enabled' => true, 'path' => $this->tmpDir, 'ttl' => 3600],
        ], $extra);
    }

    // -----------------------------------------------------------------
    // 开关与配置
    // -----------------------------------------------------------------

    public function testDisabledByDefault(): void
    {
        $fetcher = new RemoteFetcher();
        $this->assertFalse($fetcher->isEnabled());

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(ErrorCode::REMOTE_DISABLED->value);
        $fetcher->fetch('https://cdn.example.com/a.jpg');
    }

    public function testFetchContentsDisabledThrows(): void
    {
        $this->expectExceptionCode(ErrorCode::REMOTE_DISABLED->value);
        (new RemoteFetcher())->fetchContents('https://cdn.example.com/a.jpg');
    }

    public function testConfigDeepMergeKeepsNestedDefaults(): void
    {
        $fetcher = new RemoteFetcher(['enabled' => true, 'timeout' => 3, 'cache' => ['ttl' => 10]]);

        $config = $this->readPrivate($fetcher, 'config');

        $this->assertSame(3, $config['timeout']);
        $this->assertSame(10, $config['cache']['ttl']);
        // 未覆盖的嵌套默认值应保留
        $this->assertTrue($config['cache']['enabled']);
        $this->assertSame(RemoteFetcher::DEFAULTS['cache']['path'], $config['cache']['path']);
        $this->assertSame(RemoteFetcher::DEFAULTS['max_size'], $config['max_size']);
    }

    public function testListConfigReplacedNotIndexMerged(): void
    {
        $fetcher = new RemoteFetcher(['enabled' => true, 'allowed_schemes' => ['https']]);
        $config = $this->readPrivate($fetcher, 'config');

        $this->assertSame(['https'], $config['allowed_schemes']);
    }

    /**
     * @return mixed
     */
    private function readPrivate(object $object, string $property)
    {
        $ref = new \ReflectionProperty(RemoteFetcher::class, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }

    // -----------------------------------------------------------------
    // SSRF 防护
    // -----------------------------------------------------------------

    /**
     * @dataProvider deniedTargetProvider
     */
    public function testValidateTargetRejects(array $config, string $url): void
    {
        $fetcher = new RemoteFetcher(array_merge(['enabled' => true], $config));

        $this->expectException(ImageException::class);
        $fetcher->validateTarget($url);
    }

    public static function deniedTargetProvider(): array
    {
        return [
            '不支持的协议 ftp' => [['allowed_schemes' => ['http', 'https']], 'ftp://cdn.example.com/a.jpg'],
            'host 黑名单命中'  => [['blocked_hosts' => ['*.evil.com']], 'http://img.evil.com/a.jpg'],
            '精确黑名单命中'   => [['blocked_hosts' => ['bad.com']], 'http://bad.com/a.jpg'],
            '不在白名单内'     => [['allowed_hosts' => ['cdn.example.com']], 'http://other.com/a.jpg'],
            '回环地址'         => [[], 'http://127.0.0.1/a.jpg'],
            '内网地址 10 段'   => [[], 'http://10.1.2.3/a.jpg'],
            '云元数据地址'     => [[], 'http://169.254.169.254/latest/meta-data/'],
            'CGNAT 地址'       => [[], 'http://100.64.0.1/a.jpg'],
            'IPv6 回环'        => [[], 'http://[::1]/a.jpg'],
        ];
    }

    public function testValidateTargetRejectsMalformedUrl(): void
    {
        $fetcher = new RemoteFetcher(['enabled' => true]);

        $this->expectExceptionCode(ErrorCode::REMOTE_FETCH_FAILED->value);
        $fetcher->validateTarget('file:///etc/passwd');
    }

    public function testValidateTargetAcceptsPublicIpLiteral(): void
    {
        $fetcher = new RemoteFetcher(['enabled' => true]);
        $target = $fetcher->validateTarget('http://93.184.216.34/a.jpg');

        $this->assertSame('93.184.216.34', $target['host']);
        $this->assertSame(80, $target['port']);
        $this->assertSame(['93.184.216.34'], $target['ips']);
    }

    public function testValidateTargetAllowsPrivateIpWhenEnabled(): void
    {
        $fetcher = new RemoteFetcher(['enabled' => true, 'allow_private_ip' => true]);
        $target = $fetcher->validateTarget('http://127.0.0.1:8080/a.jpg');

        $this->assertSame('127.0.0.1', $target['host']);
        $this->assertSame(8080, $target['port']);
    }

    /**
     * 创建 DNS 打桩实例
     *
     * 白名单命中校验发生在 DNS 解析之后，而单元测试不应依赖外网解析，
     * 因此覆写 resolveHost() 返回固定公网 IP。
     *
     * @param array<string, mixed> $config
     */
    private function dnsStubbed(array $config, string $ip = '93.184.216.34'): RemoteFetcher
    {
        return new class($config, $ip) extends RemoteFetcher {
            public function __construct(array $config, private string $stubIp)
            {
                parent::__construct($config);
            }

            public function resolveHost(string $host): array
            {
                return [$this->stubIp];
            }
        };
    }

    public function testValidateTargetRequiresHostWhitelistMatch(): void
    {
        $fetcher = $this->dnsStubbed([
            'enabled'       => true,
            'allowed_hosts' => ['*.example.com'],
        ]);

        $target = $fetcher->validateTarget('https://cdn.example.com/a.jpg');

        $this->assertSame('cdn.example.com', $target['host']);
        $this->assertSame(443, $target['port']);
        $this->assertSame(['93.184.216.34'], $target['ips']);
    }

    /**
     * @dataProvider publicIpProvider
     */
    public function testIsPublicIp(string $ip, bool $expected): void
    {
        $fetcher = new RemoteFetcher();
        $this->assertSame($expected, $fetcher->isPublicIp($ip), "IP: {$ip}");
    }

    public static function publicIpProvider(): array
    {
        return [
            ['8.8.8.8', true],
            ['1.1.1.1', true],
            ['93.184.216.34', true],
            ['172.32.0.1', true],
            ['127.0.0.1', false],
            ['10.0.0.1', false],
            ['172.16.5.5', false],
            ['192.168.1.1', false],
            ['169.254.169.254', false],
            ['100.64.0.1', false],
            ['0.0.0.0', false],
            ['255.255.255.255', false],
            ['::1', false],
            ['fe80::1', false],
            ['::ffff:127.0.0.1', false],
            ['2002::1', false],
            ['2001:4860:4860::8888', true],
            ['not-an-ip', false],
        ];
    }

    public function testHostInListWildcard(): void
    {
        $fetcher = new RemoteFetcher();

        $this->assertTrue($fetcher->hostInList('cdn.example.com', ['*.example.com']));
        $this->assertTrue($fetcher->hostInList('a.b.example.com', ['*.example.com']));
        $this->assertTrue($fetcher->hostInList('CDN.EXAMPLE.COM', ['*.example.com']));
        $this->assertTrue($fetcher->hostInList('cdn.example.com', ['cdn.example.com']));
        $this->assertFalse($fetcher->hostInList('example.com', ['*.example.com']));
        $this->assertFalse($fetcher->hostInList('cdn.other.com', ['*.example.com']));
    }

    // -----------------------------------------------------------------
    // 下载流程
    // -----------------------------------------------------------------

    public function testDownloadSuccess(): void
    {
        $fetcher = $this->makeFetcher($this->baseConfig());
        [$body, $mime] = $fetcher->download('https://cdn.example.com/a.jpg');

        $this->assertSame($this->pngBytes(), $body);
        $this->assertSame('image/png', $mime);
    }

    public function testDownloadRejectsOversize(): void
    {
        $fetcher = $this->makeFetcher($this->baseConfig(['max_size' => 10]));

        $this->expectExceptionCode(ErrorCode::REMOTE_TOO_LARGE->value);
        $fetcher->download('https://cdn.example.com/a.jpg');
    }

    public function testDownloadRejectsNonImage(): void
    {
        $fetcher = $this->makeFetcher($this->baseConfig(), static function (): array {
            return [200, ['content-type' => 'text/plain'], 'hello world'];
        });

        $this->expectExceptionCode(ErrorCode::REMOTE_INVALID_TYPE->value);
        $fetcher->download('https://cdn.example.com/a.txt');
    }

    public function testDownloadFallsBackToContentSniffing(): void
    {
        // 源站返回错误的 Content-Type，但内容是合法 PNG → 允许通过并纠正 MIME
        $png = $this->pngBytes();
        $fetcher = $this->makeFetcher($this->baseConfig(), static function () use ($png): array {
            return [200, ['content-type' => 'text/plain'], $png];
        });

        [, $mime] = $fetcher->download('https://cdn.example.com/a.jpg');
        $this->assertSame('image/png', $mime);
    }

    public function testDownloadFollowsRedirectAndValidatesEachHop(): void
    {
        $png = $this->pngBytes();
        $fetcher = $this->makeFetcher($this->baseConfig(), static function (string $url) use ($png): array {
            if (str_ends_with($url, 'a.jpg')) {
                return [302, ['location' => '/b.png'], ''];
            }

            return [200, ['content-type' => 'image/png'], $png];
        });

        [$body] = $fetcher->download('https://cdn.example.com/dir/a.jpg');

        $this->assertSame($png, $body);
        $this->assertCount(2, $fetcher->requested);
        $this->assertSame('https://cdn.example.com/b.png', $fetcher->requested[1]['url']);
        // 每一跳都重新做安全校验
        $this->assertSame(
            ['https://cdn.example.com/dir/a.jpg', 'https://cdn.example.com/b.png'],
            $fetcher->validated
        );
    }

    public function testDownloadRejectsExcessiveRedirects(): void
    {
        $fetcher = $this->makeFetcher($this->baseConfig(['max_redirects' => 2]), static function (): array {
            return [302, ['location' => 'https://cdn.example.com/loop.jpg'], ''];
        });

        $this->expectExceptionCode(ErrorCode::REMOTE_FETCH_FAILED->value);
        $fetcher->download('https://cdn.example.com/a.jpg');
    }

    public function testDownloadFailsOnHttpError(): void
    {
        $fetcher = $this->makeFetcher($this->baseConfig(), static function (): array {
            return [404, ['content-type' => 'text/html'], 'not found'];
        });

        $this->expectExceptionCode(ErrorCode::REMOTE_FETCH_FAILED->value);
        $fetcher->download('https://cdn.example.com/missing.jpg');
    }

    // -----------------------------------------------------------------
    // 缓存
    // -----------------------------------------------------------------

    public function testFetchWritesCacheAndReusesIt(): void
    {
        $calls = 0;
        $fetcher = $this->makeFetcher($this->baseConfig(), static function () use (&$calls): array {
            $calls++;
            $png = (string) base64_decode(\wise\image\tests\RemoteFetcherTest::PNG_1X1, true);

            return [200, ['content-type' => 'image/png'], $png];
        });

        $first = $fetcher->fetch('https://cdn.example.com/a.jpg');
        $this->assertFileExists($first);
        $this->assertSame(1, $calls);

        // 命中缓存，不再发起请求
        $second = $fetcher->fetch('https://cdn.example.com/a.jpg');
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);

        // 强制刷新
        $third = $fetcher->fetch('https://cdn.example.com/a.jpg', false);
        $this->assertSame($first, $third);
        $this->assertSame(2, $calls);

        $this->assertSame($this->pngBytes(), (string) file_get_contents($first));
    }

    public function testFetchCacheDisabledAlwaysRedownloads(): void
    {
        $calls = 0;
        $fetcher = $this->makeFetcher($this->baseConfig(['cache' => ['enabled' => false, 'path' => $this->tmpDir]]), static function () use (&$calls): array {
            $calls++;
            $png = (string) base64_decode(\wise\image\tests\RemoteFetcherTest::PNG_1X1, true);

            return [200, ['content-type' => 'image/png'], $png];
        });

        $fetcher->fetch('https://cdn.example.com/a.jpg');
        $fetcher->fetch('https://cdn.example.com/a.jpg');

        $this->assertSame(2, $calls);
    }

    // -----------------------------------------------------------------
    // 重定向 URL 解析
    // -----------------------------------------------------------------

    /**
     * @dataProvider redirectUrlProvider
     */
    public function testResolveRedirectUrl(string $base, string $location, string $expected): void
    {
        $fetcher = new RemoteFetcher();
        $method = new \ReflectionMethod(RemoteFetcher::class, 'resolveRedirectUrl');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($fetcher, $base, $location));
    }

    public static function redirectUrlProvider(): array
    {
        return [
            '绝对 URL'       => ['https://cdn.example.com/dir/a.jpg', 'http://other.com/x.png', 'http://other.com/x.png'],
            '绝对路径'       => ['https://cdn.example.com/dir/a.jpg', '/b.png', 'https://cdn.example.com/b.png'],
            '相对路径'       => ['https://cdn.example.com/dir/a.jpg', 'b.png', 'https://cdn.example.com/dir/b.png'],
            '协议相对'       => ['https://cdn.example.com/dir/a.jpg', '//cdn.example.com/x.png', 'https://cdn.example.com/x.png'],
            '带端口'         => ['http://cdn.example.com:8080/a.jpg', '/b.png', 'http://cdn.example.com:8080/b.png'],
        ];
    }
}
