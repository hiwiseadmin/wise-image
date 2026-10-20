<?php

declare(strict_types=1);

namespace wise\image\remote;

use wise\image\cache\CachePathResolver;
use wise\image\exception\ImageException;

/**
 * 远程图片抓取器
 *
 * 负责把 `http(s)://` 远程图片安全地下载到本地，供 {@see \wise\image\ImageManager}
 * 的驱动以「本地文件」方式打开，避免驱动层各自处理网络请求。
 *
 * 安全设计（防 SSRF / 资源耗尽）：
 *   1. 协议白名单（默认仅 http / https），拒绝 file://、gopher://、data:// 等；
 *   2. host 白名单 / 黑名单（支持 `*` 通配符）；
 *   3. DNS 解析结果逐个校验，禁止内网 / 保留 / 回环 / CGNAT / 隧道地址（可配置放行）；
 *   4. 使用 cURL 时通过 CURLOPT_RESOLVE 绑定已校验 IP，规避 DNS Rebinding；
 *   5. 关闭自动重定向，手动逐跳校验 Location（防止 302 跳内网）；
 *   6. 边下边计数，超过 max_size 立即中断（防大文件内存耗尽）；
 *   7. 校验 Content-Type + getimagesizefromstring 双重确认是合法图片。
 *
 * 用法示例：
 *   $fetcher = new RemoteFetcher(['enabled' => true]);
 *   $localPath = $fetcher->fetch('https://cdn.example.com/a.jpg');
 *   Image::open($localPath)->resize(200, 200)->save('/tmp/thumb.jpg');
 */
class RemoteFetcher
{
    /** 默认配置 */
    public const DEFAULTS = [
        // 总开关，为 false 时任何远程抓取都会抛异常（默认关闭，需显式开启）
        'enabled'          => false,
        // 读取超时（秒）
        'timeout'          => 10,
        // 连接超时（秒）
        'connect_timeout'  => 5,
        // 单张图片最大字节数（默认 10MB）
        'max_size'         => 10485760,
        // 最大重定向次数
        'max_redirects'    => 3,
        // 允许的协议
        'allowed_schemes'  => ['http', 'https'],
        // host 白名单（为空表示不限制；仍受内网拦截约束），支持 *.example.com
        'allowed_hosts'    => [],
        // host 黑名单（优先级高于白名单）
        'blocked_hosts'    => [],
        // 是否允许解析到内网 / 保留地址（默认禁止，仅内网可信环境可开）
        'allow_private_ip' => false,
        // 允许的图片 MIME
        'allowed_mime'     => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'],
        // User-Agent
        'user_agent'       => 'WiseImage/1.0 (+https://github.com/hiwiseadmin/wise-image)',
        // 额外请求头（如 ['Referer' => 'https://example.com/']），用于绕过源站防盗链
        'headers'          => [],
        // 是否校验 TLS 证书（生产环境请保持 true）
        'verify_tls'       => true,
        // 本地缓存（下载后的图片副本）
        'cache'            => [
            'enabled' => true,
            'path'    => 'runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'remote',
            'ttl'     => 86400,
        ],
    ];

    /** IPv4 额外禁止网段（PHP 内置过滤未覆盖或不稳定的部分） */
    private const BLOCKED_V4_NETS = [
        ['100.64.0.0', 10],   // CGNAT
        ['192.0.0.0', 24],    // IETF 协议分配
        ['192.0.2.0', 24],    // TEST-NET-1
        ['198.18.0.0', 15],   // 基准测试
        ['198.51.100.0', 24], // TEST-NET-2
        ['203.0.113.0', 24],  // TEST-NET-3
    ];

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config 远程抓取配置（与 config/wise-image.php 的 remote 段一致）
     */
    public function __construct(array $config = [])
    {
        $this->config = self::mergeConfig(self::DEFAULTS, $config);
    }

    /**
     * 配置深度合并
     *
     * 关联数组递归合并；列表（索引数组，如 allowed_hosts / headers）整体覆盖，
     * 避免 array_replace_recursive 按索引错位合并的问题。
     *
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function mergeConfig(array $defaults, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                array_key_exists($key, $defaults)
                && is_array($defaults[$key])
                && is_array($value)
                && !array_is_list($defaults[$key])
                && !array_is_list($value)
            ) {
                $defaults[$key] = self::mergeConfig($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    /**
     * 远程抓取是否启用
     */
    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    /**
     * 下载远程图片并返回本地文件路径
     *
     * 优先复用缓存副本；`$useCache = false` 时强制重新下载并覆盖本地副本。
     *
     * @param string $url      远程图片 URL
     * @param bool   $useCache 是否复用本地缓存
     * @return string 本地文件绝对路径
     * @throws ImageException
     */
    public function fetch(string $url, bool $useCache = true): string
    {
        $this->assertEnabled();

        $cache = (array) ($this->config['cache'] ?? []);
        $cacheFile = $this->cacheFilePath($url);

        if ($useCache && !empty($cache['enabled']) && is_file($cacheFile) && $this->isFresh($cacheFile, (int) ($cache['ttl'] ?? 86400))) {
            return $cacheFile;
        }

        [$contents] = $this->download($url);
        $this->writeAtomically($cacheFile, $contents);

        return $cacheFile;
    }

    /**
     * 下载远程图片并返回二进制内容（不落盘）
     *
     * @throws ImageException
     */
    public function fetchContents(string $url): string
    {
        return $this->download($url)[0];
    }

    /**
     * 执行下载（含手动重定向与内容校验）
     *
     * @return array{0: string, 1: string} [二进制内容, MIME]
     * @throws ImageException
     */
    public function download(string $url): array
    {
        $this->assertEnabled();

        $maxSize       = max(1, (int) ($this->config['max_size'] ?? 10485760));
        $maxRedirects  = max(0, (int) ($this->config['max_redirects'] ?? 3));
        $allowedMime   = array_map('strtolower', (array) ($this->config['allowed_mime'] ?? []));
        $current       = $url;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $target = $this->validateTarget($current);
            [$status, $headers, $body] = $this->requestOnce($current, $target, $maxSize);

            // 重定向：手动逐跳校验，防止 302 跳转到内网地址
            if ($status >= 300 && $status < 400) {
                $location = (string) ($headers['location'] ?? '');
                if ($location === '') {
                    throw ImageException::remoteFetchFailed($url, '重定向响应缺少 Location 头');
                }
                if ($hop >= $maxRedirects) {
                    throw ImageException::remoteFetchFailed($url, "重定向次数超过上限 {$maxRedirects}");
                }
                $current = $this->resolveRedirectUrl($current, $location);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw ImageException::remoteFetchFailed($url, "HTTP 状态码异常：{$status}");
            }

            $size = strlen($body);
            if ($size <= 0) {
                throw ImageException::remoteFetchFailed($url, '响应内容为空');
            }
            if ($size > $maxSize) {
                throw ImageException::remoteTooLarge($url, $size, $maxSize);
            }

            // MIME 校验：优先信任响应头，不被信任时回退内容嗅探
            $mime = $this->normalizeMime((string) ($headers['content-type'] ?? ''));
            if ($mime === '' || !in_array($mime, $allowedMime, true)) {
                $sniffed = $this->sniffMime($body);
                if ($sniffed === null || !in_array($sniffed, $allowedMime, true)) {
                    throw ImageException::remoteInvalidType($url, $mime !== '' ? $mime : (string) $sniffed);
                }
                $mime = $sniffed;
            }

            // 二次确认：必须是可解析的真实图片，而非伪造 Content-Type 的任意文件
            if (@getimagesizefromstring($body) === false) {
                throw ImageException::remoteInvalidType($url, $mime);
            }

            return [$body, $mime];
        }

        throw ImageException::remoteFetchFailed($url, '重定向处理异常');
    }

    /**
     * 校验目标 URL 并返回连接信息
     *
     * @return array{host: string, port: int, ips: string[]}
     * @throws ImageException
     */
    public function validateTarget(string $url): array
    {
        $parts = parse_url(trim($url));
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw ImageException::remoteFetchFailed($url, 'URL 格式非法');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $allowedSchemes = array_map('strtolower', (array) ($this->config['allowed_schemes'] ?? ['http', 'https']));
        if (!in_array($scheme, $allowedSchemes, true)) {
            throw ImageException::remoteHostDenied($url, "不允许的协议：{$scheme}");
        }

        // 去掉 IPv6 字面量的方括号
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if ($this->hostInList($host, (array) ($this->config['blocked_hosts'] ?? []))) {
            throw ImageException::remoteHostDenied($url, 'host 命中黑名单');
        }

        $allowedHosts = (array) ($this->config['allowed_hosts'] ?? []);
        if ($allowedHosts !== [] && !$this->hostInList($host, $allowedHosts)) {
            throw ImageException::remoteHostDenied($url, 'host 不在白名单内');
        }

        $ips = $this->resolveHost($host);
        if ($ips === []) {
            throw ImageException::remoteFetchFailed($url, 'DNS 解析失败');
        }

        if (empty($this->config['allow_private_ip'])) {
            foreach ($ips as $ip) {
                if (!$this->isPublicIp($ip)) {
                    throw ImageException::remoteHostDenied($url, "目标解析到内网/保留地址：{$ip}");
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /**
     * 判断 IP 是否公网可路由（SSRF 核心判定）
     */
    public function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // PHP 内置私有段 + 保留段过滤（覆盖 10/8、172.16/12、192.168/16、127/8、169.254/16 等）
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long === false) {
                return false;
            }
            $long = $long & 0xFFFFFFFF;

            foreach (self::BLOCKED_V4_NETS as [$net, $bits]) {
                $netLong = ip2long($net);
                if ($netLong === false) {
                    continue;
                }
                $mask = $bits === 0 ? 0 : ((~0 << (32 - $bits)) & 0xFFFFFFFF);
                if (($long & $mask) === (($netLong & 0xFFFFFFFF) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        // IPv6：还原 IPv4-mapped 地址后重新判定；6to4 / Teredo 隧道地址直接拒绝
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m) === 1) {
            return $this->isPublicIp($m[1]);
        }
        if (preg_match('/^(2002|2001:0):/i', $ip) === 1) {
            return false;
        }

        return true;
    }

    /**
     * 解析 host 对应的 IP 列表
     *
     * @return string[]
     */
    public function resolveHost(string $host): array
    {
        // 已是 IP 字面量
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                } elseif (!empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        // 回退：仅 IPv4
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * host 是否命中匹配列表（支持 `*` 通配符）
     *
     * @param string[] $patterns
     */
    public function hostInList(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $host) === 1) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------
    // 内部实现
    // -----------------------------------------------------------------

    /**
     * @throws ImageException
     */
    protected function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw ImageException::remoteDisabled();
        }
    }

    /**
     * 单次 HTTP 请求（含已校验 IP 绑定）
     *
     * @param array{host: string, port: int, ips: string[]} $target
     * @return array{0: int, 1: array<string, string>, 2: string} [状态码, 响应头, 响应体]
     * @throws ImageException
     */
    protected function requestOnce(string $url, array $target, int $maxSize): array
    {
        if (function_exists('curl_init')) {
            return $this->requestWithCurl($url, $target, $maxSize);
        }

        return $this->requestWithStream($url, $maxSize);
    }

    /**
     * cURL 实现：通过 CURLOPT_RESOLVE 绑定已校验 IP，防止 DNS Rebinding
     *
     * @param array{host: string, port: int, ips: string[]} $target
     * @return array{0: int, 1: array<string, string>, 2: string}
     * @throws ImageException
     */
    private function requestWithCurl(string $url, array $target, int $maxSize): array
    {
        $responseHeaders = [];
        $body            = '';
        $tooLarge        = false;

        $addresses = [];
        foreach ($target['ips'] as $ip) {
            $addresses[] = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        }
        $resolveHost = str_contains($target['host'], ':') ? '[' . $target['host'] . ']' : $target['host'];
        $resolveEntry = $resolveHost . ':' . $target['port'] . ':' . implode(',', $addresses);

        $verifyTls = (bool) ($this->config['verify_tls'] ?? true);

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_CONNECTTIMEOUT => max(1, (int) ($this->config['connect_timeout'] ?? 5)),
            CURLOPT_TIMEOUT        => max(1, (int) ($this->config['timeout'] ?? 10)),
            CURLOPT_USERAGENT      => (string) ($this->config['user_agent'] ?? 'WiseImage/1.0'),
            CURLOPT_HTTPHEADER     => $this->buildHeaderLines(),
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_ENCODING       => '',
            CURLOPT_RESOLVE        => [$resolveEntry],
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$key, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$body, &$tooLarge, $maxSize): int {
                $body .= $chunk;
                if (strlen($body) > $maxSize) {
                    $tooLarge = true;
                    return 0; // 主动中断传输
                }
                return strlen($chunk);
            },
        ];

        // PHP 8.4 起 CURLOPT_PROTOCOLS / CURLOPT_REDIR_PROTOCOLS 被废弃，优先使用 *_STR 版本
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        $ch = curl_init();
        if ($ch === false) {
            return $this->requestWithStream($url, $maxSize);
        }

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($tooLarge) {
            throw ImageException::remoteTooLarge($url, strlen($body), $maxSize);
        }

        if ($result === false || $errno !== 0) {
            throw ImageException::remoteFetchFailed($url, $error !== '' ? $error : "cURL 错误码 {$errno}");
        }

        return [$status, $responseHeaders, $body];
    }

    /**
     * 流实现（cURL 不可用时的降级方案，仍保留协议 / 大小 / 超时限制）
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     * @throws ImageException
     */
    private function requestWithStream(string $url, int $maxSize): array
    {
        if (filter_var((string) ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) === false) {
            throw ImageException::remoteFetchFailed($url, 'cURL 扩展不可用且 allow_url_fopen 已关闭');
        }

        $verifyTls = (bool) ($this->config['verify_tls'] ?? true);

        $context = stream_context_create([
            'http' => [
                'method'           => 'GET',
                'header'           => implode("\r\n", $this->buildHeaderLines()),
                'timeout'          => max(1, (int) ($this->config['timeout'] ?? 10)),
                'follow_location'  => 0,
                'max_redirects'    => 0,
                'ignore_errors'    => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer'      => $verifyTls,
                'verify_peer_name' => $verifyTls,
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            throw ImageException::remoteFetchFailed($url, '无法建立连接（stream 降级模式）');
        }

        $body = '';
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > $maxSize) {
                fclose($handle);
                throw ImageException::remoteTooLarge($url, strlen($body), $maxSize);
            }
        }

        $meta = stream_get_meta_data($handle);
        fclose($handle);

        $status  = 0;
        $headers = [];
        foreach ((array) ($meta['wrapper_data'] ?? []) as $index => $line) {
            $line = trim((string) $line);
            if ($index === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m) === 1) {
                $status = (int) $m[1];
                continue;
            }
            if ($line !== '' && str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        return [$status, $headers, $body];
    }

    /**
     * 构造请求头
     *
     * @return string[]
     */
    private function buildHeaderLines(): array
    {
        $lines = [];
        foreach ((array) ($this->config['headers'] ?? []) as $key => $value) {
            if (is_string($key) && $key !== '') {
                $lines[] = $key . ': ' . $value;
            }
        }
        $lines[] = 'Accept: image/*,*/*;q=0.8';

        return $lines;
    }

    /**
     * 解析重定向目标为绝对 URL
     */
    private function resolveRedirectUrl(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return $base;
        }

        // 已是绝对 URL
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $location;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        // 协议相对：//host/path
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }

        // 绝对路径
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        // 相对路径：基于当前目录解析
        $dir = rtrim(dirname((string) ($parts['path'] ?? '/')), '/');

        return $origin . ($dir === '' ? '' : $dir) . '/' . $location;
    }

    /**
     * 规范化 MIME（去掉 `;charset=...` 等参数）
     */
    private function normalizeMime(string $contentType): string
    {
        $contentType = strtolower(trim($contentType));
        if ($contentType === '') {
            return '';
        }

        return trim(explode(';', $contentType)[0]);
    }

    /**
     * 通过文件内容嗅探 MIME
     */
    private function sniffMime(string $contents): ?string
    {
        $info = @getimagesizefromstring($contents);
        if (is_array($info) && !empty($info['mime'])) {
            return strtolower((string) $info['mime']);
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_buffer($finfo, $contents);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }

        return null;
    }

    /**
     * 判断缓存文件是否仍在有效期内
     */
    private function isFresh(string $file, int $ttl): bool
    {
        if ($ttl <= 0) {
            return true;
        }

        $mtime = @filemtime($file);
        if ($mtime === false) {
            return false;
        }

        return (time() - $mtime) < $ttl;
    }

    /**
     * 计算本地缓存文件路径
     */
    private function cacheFilePath(string $url): string
    {
        $dir = CachePathResolver::resolve(
            isset($this->config['cache']['path']) ? (string) $this->config['cache']['path'] : null,
            (string) self::DEFAULTS['cache']['path']
        );

        return $dir . DIRECTORY_SEPARATOR . sha1($url) . '.img';
    }

    /**
     * 原子写入（先写临时文件再 rename，避免并发读到半截内容）
     *
     * @throws ImageException
     */
    private function writeAtomically(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw ImageException::remoteFetchFailed($path, "无法创建缓存目录：{$dir}");
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $contents) === false) {
            throw ImageException::remoteFetchFailed($path, '本地缓存写入失败');
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw ImageException::remoteFetchFailed($path, '本地缓存重命名失败');
        }
    }
}
