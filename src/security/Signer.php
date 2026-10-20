<?php

declare(strict_types=1);

namespace wise\image\security;

/**
 * 图片 URL 签名器（HMAC-SHA256）
 *
 * 修复原实现的两个安全问题：
 *   1. **签名可跨图片重放**：原签名串仅由查询参数拼成，不含图片路径，
 *      导致一张图的合法签名可直接套用到任意另一张图；现签名串强制包含 `path`。
 *   2. **签名永不过期**：原实现仅在传入 `t` 时才做超时校验，不传即长期有效；
 *      现在 `timeout > 0` 时**强制要求**时间戳，并校验「过期」与「未来时间」两个方向。
 *
 * 同时改进可用性：参与签名的参数会先 `ksort` 排序，客户端参数顺序变化不再导致验签失败。
 *
 * 待签名字符串（规范化后）：
 * ```
 * /image/upload/foo.jpg?quality=90&resize=w_200&t=1700000000
 * ```
 * 其中 `path` 为请求 pathinfo（含路由前缀），`t` 为 Unix 时间戳。
 *
 * 用法示例：
 * ```php
 * $signer = new Signer('your-secret', 3600);
 *
 * // 服务端/客户端：生成带签名的 URL
 * $url = $signer->signUrl('/image/upload/foo.jpg', ['x-oss-process' => 'image/resize,w_200']);
 *
 * // 服务端：验签
 * if (!$signer->verify('/image/upload/foo.jpg', $_GET)) {
 *     throw new \RuntimeException('签名无效');
 * }
 * ```
 */
class Signer
{
    /** 签名算法 */
    public const ALGO = 'sha256';

    /** 允许的客户端时钟偏移（秒）：容忍时间戳略大于服务端当前时间 */
    private const CLOCK_SKEW = 60;

    /**
     * @param string $secret  签名密钥
     * @param int    $timeout 签名有效期（秒）；<= 0 表示不校验时效（仍拒绝未来时间戳）
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $timeout = 3600
    ) {
    }

    /**
     * 生成签名
     *
     * @param string               $path      图片路径（与验签时保持一致）
     * @param array<string, mixed> $params    参与签名的查询参数（无需包含 sign）
     * @param int|null             $timestamp 时间戳，默认当前时间
     * @return string 十六进制 HMAC 签名
     */
    public function sign(string $path, array $params = [], ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return hash_hmac(self::ALGO, $this->buildBase($path, $params, $timestamp), $this->secret);
    }

    /**
     * 生成带签名的完整 URL
     *
     * @param string               $path      图片路径（可含查询串）
     * @param array<string, mixed> $params    附加查询参数
     * @param int|null             $timestamp 时间戳，默认当前时间
     * @return string
     */
    public function signUrl(string $path, array $params = [], ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();

        $params = $this->normalizeParams($params);
        $params['t']    = $timestamp;
        $params['sign'] = $this->sign($path, $params, $timestamp);

        // path 已自带查询串时追加，否则新起
        $separator = str_contains($path, '?') ? '&' : '?';

        return $path . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 验签
     *
     * @param string               $path  图片路径（与签名时保持一致）
     * @param array<string, mixed> $params 请求查询参数（需包含 sign；timeout > 0 时还需包含 t）
     * @param int|null             $now   当前时间戳（便于测试注入）
     * @return bool
     */
    public function verify(string $path, array $params, ?int $now = null): bool
    {
        $now = $now ?? time();

        $provided = (string) ($params['sign'] ?? '');
        if ($provided === '') {
            return false;
        }

        $timestamp = (int) ($params['t'] ?? $params['ts'] ?? 0);

        // timeout > 0 时强制要求时间戳，杜绝「不传 t 即永不过期」
        if ($this->timeout > 0 && $timestamp <= 0) {
            return false;
        }

        if ($timestamp > 0) {
            // 拒绝明显来自未来的时间戳（容忍 CLOCK_SKEW 秒时钟偏移）
            if ($timestamp > $now + self::CLOCK_SKEW) {
                return false;
            }
            // 已过期
            if ($this->timeout > 0 && ($now - $timestamp) > $this->timeout) {
                return false;
            }
        }

        $expected = $this->sign($path, $params, $timestamp);

        return hash_equals($expected, $provided);
    }

    /**
     * 构造规范化待签名字符串
     *
     * 规则：
     *   - path 统一补前导 `/`；
     *   - 参数剔除 `sign` / `t` / `ts`（这三个由签名协议自身管理）；
     *   - 参数按 key 升序排序，保证与客户端参数顺序无关；
     *   - 过滤 null 与空串，避免「未传」与「传空」产生两种签名。
     *
     * @param array<string, mixed> $params
     */
    public function buildBase(string $path, array $params, int $timestamp): string
    {
        $params = $this->normalizeParams($params);

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return self::normalizePath($path) . '?' . $query . '&t=' . $timestamp;
    }

    /**
     * 规范化路径：去协议/域名与查询串、统一前导 /
     *
     * 静态方法，便于验签方在不持有 Signer 实例时先归一化路径。
     */
    public static function normalizePath(string $path): string
    {
        // 去掉协议+域名，只保留路径部分，容忍调用方直接传完整 URL
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path) === 1) {
            $parsed = parse_url($path);
            $path   = (string) ($parsed['path'] ?? '/');
        }

        $path = explode('?', $path, 2)[0];

        return '/' . ltrim($path, '/');
    }

    /**
     * 规范化参数：剔除协议保留字段、按 key 排序、过滤空值
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function normalizeParams(array $params): array
    {
        unset($params['sign'], $params['t'], $params['ts']);

        $params = array_filter(
            $params,
            static fn ($value): bool => $value !== null && $value !== ''
        );

        ksort($params);

        return $params;
    }
}
