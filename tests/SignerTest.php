<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\security\Signer;

/**
 * Signer 签名器测试
 *
 * 重点覆盖原实现的三个缺陷：
 * - 签名可跨图片重放（签名串缺 path）
 * - 签名永不过期（不传 t 即跳过时效校验）
 * - 参数顺序敏感导致误判
 */
class SignerTest extends TestCase
{
    private const SECRET = 'top-secret';
    private const NOW    = 1700000000;

    private function signer(int $timeout = 3600): Signer
    {
        return new Signer(self::SECRET, $timeout);
    }

    // -----------------------------------------------------------------
    // 正常路径
    // -----------------------------------------------------------------

    public function testSignAndVerify(): void
    {
        $signer = $this->signer();
        $params = ['x-oss-process' => 'image/resize,w_200'];

        $sign = $signer->sign('/image/upload/a.jpg', $params, self::NOW);

        $this->assertTrue($signer->verify(
            '/image/upload/a.jpg',
            $params + ['t' => self::NOW, 'sign' => $sign],
            self::NOW
        ));
    }

    public function testVerifyIsIndependentOfParamOrder(): void
    {
        $signer = $this->signer();
        $sign   = $signer->sign('/image/a.jpg', ['a' => '1', 'b' => '2'], self::NOW);

        $this->assertTrue($signer->verify(
            '/image/a.jpg',
            ['sign' => $sign, 'b' => '2', 't' => self::NOW, 'a' => '1'],
            self::NOW
        ));
    }

    public function testTsAliasIsAccepted(): void
    {
        $signer = $this->signer();
        $sign   = $signer->sign('/image/a.jpg', ['q' => '90'], self::NOW);

        $this->assertTrue($signer->verify(
            '/image/a.jpg',
            ['q' => '90', 'ts' => self::NOW, 'sign' => $sign],
            self::NOW
        ));
    }

    public function testClockSkewIsTolerated(): void
    {
        $signer = $this->signer();

        $this->assertTrue($signer->verify(
            '/image/a.jpg',
            ['t' => self::NOW + 30, 'sign' => $signer->sign('/image/a.jpg', [], self::NOW + 30)],
            self::NOW
        ));
    }

    // -----------------------------------------------------------------
    // 安全：跨图片重放 / 篡改
    // -----------------------------------------------------------------

    public function testSignatureCannotBeReplayedOnAnotherPath(): void
    {
        $signer = $this->signer();
        $sign   = $signer->sign('/image/upload/a.jpg', ['q' => '90'], self::NOW);

        $this->assertFalse($signer->verify(
            '/image/upload/b.jpg',
            ['q' => '90', 't' => self::NOW, 'sign' => $sign],
            self::NOW
        ));
    }

    public function testTamperedParamsAreRejected(): void
    {
        $signer = $this->signer();
        $sign   = $signer->sign('/image/a.jpg', ['q' => '90'], self::NOW);

        $this->assertFalse($signer->verify(
            '/image/a.jpg',
            ['q' => '100', 't' => self::NOW, 'sign' => $sign],
            self::NOW
        ));
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $signer = $this->signer();

        $this->assertFalse($signer->verify(
            '/image/a.jpg',
            ['q' => '90', 't' => self::NOW, 'sign' => str_repeat('a', 64)],
            self::NOW
        ));
    }

    public function testWrongSecretIsRejected(): void
    {
        $sign = (new Signer('other-secret', 3600))->sign('/image/a.jpg', [], self::NOW);

        $this->assertFalse($this->signer()->verify(
            '/image/a.jpg',
            ['t' => self::NOW, 'sign' => $sign],
            self::NOW
        ));
    }

    public function testMissingSignatureIsRejected(): void
    {
        $this->assertFalse($this->signer()->verify('/image/a.jpg', ['t' => self::NOW], self::NOW));
    }

    // -----------------------------------------------------------------
    // 安全：时效
    // -----------------------------------------------------------------

    public function testMissingTimestampIsRejectedWhenTimeoutEnabled(): void
    {
        $signer = $this->signer();
        $sign   = $signer->sign('/image/a.jpg', [], self::NOW);

        // 旧实现在不传 t 时会直接放过，这里必须拒绝
        $this->assertFalse($signer->verify('/image/a.jpg', ['sign' => $sign], self::NOW));
    }

    public function testExpiredSignatureIsRejected(): void
    {
        $signer = $this->signer(60);
        $old    = self::NOW - 120;

        $this->assertFalse($signer->verify(
            '/image/a.jpg',
            ['t' => $old, 'sign' => $signer->sign('/image/a.jpg', [], $old)],
            self::NOW
        ));
    }

    public function testFutureTimestampIsRejected(): void
    {
        $signer = $this->signer(3600);
        $future = self::NOW + 3600;

        $this->assertFalse($signer->verify(
            '/image/a.jpg',
            ['t' => $future, 'sign' => $signer->sign('/image/a.jpg', [], $future)],
            self::NOW
        ));
    }

    public function testTimestampBecomesOptionalWhenTimeoutDisabled(): void
    {
        $signer = $this->signer(0);
        $sign   = $signer->sign('/image/a.jpg', [], 0);

        // timeout <= 0 表示不校验时效，允许无时间戳
        $this->assertTrue($signer->verify('/image/a.jpg', ['sign' => $sign], self::NOW));
    }

    // -----------------------------------------------------------------
    // 规范化
    // -----------------------------------------------------------------

    /**
     * @dataProvider pathProvider
     */
    public function testNormalizePath(string $input, string $expected): void
    {
        $this->assertSame($expected, Signer::normalizePath($input));
    }

    public static function pathProvider(): array
    {
        return [
            '已规范'      => ['/image/a.jpg', '/image/a.jpg'],
            '缺前导斜杠'  => ['image/a.jpg', '/image/a.jpg'],
            '含查询串'    => ['/image/a.jpg?q=1', '/image/a.jpg'],
            '完整 URL'    => ['https://x.com/image/a.jpg?q=1', '/image/a.jpg'],
            '带端口 URL'  => ['http://x.com:8080/image/a.jpg', '/image/a.jpg'],
        ];
    }

    public function testBuildBaseFiltersEmptyValues(): void
    {
        $signer = $this->signer();

        // 空串与 null 不参与签名，避免「未传」与「传空」产生两种签名
        $withEmpty    = $signer->buildBase('/image/a.jpg', ['a' => '1', 'b' => '', 'c' => null], self::NOW);
        $withoutEmpty = $signer->buildBase('/image/a.jpg', ['a' => '1'], self::NOW);

        $this->assertSame($withoutEmpty, $withEmpty);
        $this->assertSame('/image/a.jpg?a=1&t=' . self::NOW, $withEmpty);
    }

    // -----------------------------------------------------------------
    // signUrl
    // -----------------------------------------------------------------

    public function testSignUrlIsVerifiable(): void
    {
        $signer = $this->signer();
        $url    = $signer->signUrl('/image/a.jpg', ['x-oss-process' => 'resize,w_10'], self::NOW);

        $this->assertStringContainsString('sign=', $url);
        $this->assertStringContainsString('t=' . self::NOW, $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertTrue($signer->verify('/image/a.jpg', $query, self::NOW));
    }

    public function testSignUrlAppendsToExistingQuery(): void
    {
        $url = $this->signer()->signUrl('/image/a.jpg?foo=bar', [], self::NOW);

        $this->assertStringStartsWith('/image/a.jpg?foo=bar&', $url);
    }
}
