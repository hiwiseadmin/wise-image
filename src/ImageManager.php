<?php

declare(strict_types=1);

namespace wise\image;

use wise\image\driver\DriverInterface;
use wise\image\driver\GdDriver;
use wise\image\driver\ImagickDriver;
use wise\image\exception\ImageException;
use wise\image\processor\ProcessorInterface;

/**
 * 图片处理核心管理器
 *
 * wise-image 的入口门面，提供构建器模式的图片处理 API。
 *
 * 核心能力：
 *   1. 打开/创建图片（open / make）
 *   2. 构建处理操作链（addOperation / parseUrlParams / style）
 *   3. 执行处理链（execute）
 *   4. 输出结果（save / output / response）
 *   5. 安全中间件（IP 黑名单 / Referer 防盗链 / 签名验证）
 *   6. 缓存支持（文件 / Redis）
 *
 * 用法示例：
 *   $manager = new ImageManager($config);
 *   $manager->open('/path/to/image.jpg')
 *       ->parseUrlParams('resize,w_200,h_200/quality,q_80')
 *       ->save('/path/to/output.webp', 'webp');
 */
class ImageManager
{
    // -----------------------------------------------------------------
    // 属性
    // -----------------------------------------------------------------

    /** @var array<string, mixed> 完整配置 */
    private array $config;

    /** @var DriverInterface|null 当前驱动实例（懒加载） */
    private ?DriverInterface $driver = null;

    /** @var string 源文件路径（open 时设置） */
    private string $sourcePath = '';

    /** @var string|null FormatProcessor 设置的目标格式 */
    private ?string $targetFormat = null;

    /**
     * 处理操作链
     *
     * 每一项为 [ProcessorInterface, array<string, mixed>]
     *
     * @var array<int, array{0: ProcessorInterface, 1: array<string, mixed>}>
     */
    private array $operations = [];

    /** @var bool 是否已执行处理链 */
    private bool $executed = false;

    /** @var bool make 模式（创建空白画布） */
    private bool $isMake = false;

    /** @var int make 模式宽度 */
    private int $makeWidth = 0;

    /** @var int make 模式高度 */
    private int $makeHeight = 0;

    /** @var \think\Request|null 框架请求实例（用于获取客户端 IP/Referer/参数） */
    private ?\think\Request $request = null;

    // -----------------------------------------------------------------
    // 内置操作名 → 类名映射
    // -----------------------------------------------------------------

    /** @var array<string, class-string<ProcessorInterface>> */
    private const OPERATION_MAP = [
        'resize'     => \wise\image\processor\ResizeProcessor::class,
        'crop'       => \wise\image\processor\CropProcessor::class,
        'watermark'  => \wise\image\processor\WatermarkProcessor::class,
        'format'     => \wise\image\processor\FormatProcessor::class,
        'quality'    => \wise\image\processor\QualityProcessor::class,
        'rotate'     => \wise\image\processor\RotateProcessor::class,
        'blur'       => \wise\image\processor\BlurProcessor::class,
        'brightness' => \wise\image\processor\BrightnessProcessor::class,
        'contrast'   => \wise\image\processor\ContrastProcessor::class,
    ];

    /** @var array<string, ProcessorInterface> 单例缓存 */
    private static array $processorCache = [];

    // -----------------------------------------------------------------
    // 构造函数
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $config 完整配置（与 config/wise-image.php 结构一致）
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'driver'         => 'gd',
            'default_quality' => 85,
            'auto_webp'      => true,
            'cache'          => [
                'driver' => 'file',
                'ttl'    => 2592000,
                'path'   => 'runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'cache',
                'prefix' => 'wise_image:',
            ],
            'security'       => [
                'ip_blacklist'  => ['enabled' => false, 'ips' => []],
                'referer'       => ['enabled' => false, 'allow_empty' => true, 'whitelist' => [], 'blacklist' => []],
                'signature'     => ['enabled' => false, 'secret' => '', 'timeout' => 3600],
                'origin_protect' => ['enabled' => false],
                'max_width'     => 4096,
                'max_height'    => 4096,
                'allowed_formats' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
            ],
            'styles'         => [],
            'font_path'      => null,
            'route_prefix'   => '/image',
        ], $config);
    }

    /**
     * 注入框架请求实例
     *
     * 设置后，安全检查将使用框架的 IP/Referer/参数获取方法，
     * 而非直接读取超全局变量。未设置时自动回退到 $_SERVER/$_GET。
     */
    public function setRequest(\think\Request $request): void
    {
        $this->request = $request;
    }

    public function __destruct()
    {
        $this->destroy();
    }

    // -----------------------------------------------------------------
    // 构建器方法（返回 $this 实现链式调用）
    // -----------------------------------------------------------------

    /**
     * 打开已有图片
     *
     * @param string $path 图片文件路径
     * @return $this
     * @throws ImageException 当文件不存在时
     */
    public function open(string $path): self
    {
        if (!is_file($path)) {
            throw ImageException::fileNotFound($path);
        }

        $this->reset();
        $this->sourcePath = $path;
        $this->isMake     = false;

        return $this;
    }

    /**
     * 创建空白画布
     *
     * @param int $width  画布宽度
     * @param int $height 画布高度
     * @return $this
     */
    public function make(int $width, int $height): self
    {
        $this->reset();
        $this->isMake     = true;
        $this->makeWidth  = $width;
        $this->makeHeight = $height;

        return $this;
    }

    /**
     * 添加处理操作到链
     *
     * @param ProcessorInterface    $processor 处理器实例
     * @param array<string, mixed>  $params    处理器参数
     * @return $this
     */
    public function addOperation(ProcessorInterface $processor, array $params): self
    {
        $this->operations[] = [$processor, $params];
        return $this;
    }

    /**
     * 从 OSS 风格 URL 参数字符串解析操作链
     *
     * 格式：/resize,w_200,h_200,m_lfit/quality,q_80/format,f_webp/crop,w_300,h_300,g_center
     *
     * @param string $paramString 参数字符串（不含前导路由前缀）
     * @return $this
     * @throws ImageException 当操作名不存在时
     */
    public function parseUrlParams(string $paramString): self
    {
        // 去除收尾斜杠
        $paramString = trim($paramString, '/');

        if ($paramString === '') {
            return $this;
        }

        // 按 / 分割为操作分段
        $segments = explode('/', $paramString);

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            // 按 , 分割为操作名 + k_v 参数对
            $parts = explode(',', $segment);
            $operationName = strtolower(trim($parts[0]));

            $params = [];
            for ($i = 1, $len = count($parts); $i < $len; $i++) {
                $kv = trim($parts[$i]);
                if ($kv === '') {
                    continue;
                }
                $pos = strpos($kv, '_');
                if ($pos !== false && $pos > 0 && $pos < strlen($kv) - 1) {
                    $key          = substr($kv, 0, $pos);
                    $value        = substr($kv, $pos + 1);
                    $params[$key] = $value;
                }
            }

            // 查找并创建对应 Processor
            $processor = $this->resolveProcessor($operationName);
            $this->operations[] = [$processor, $params];
        }

        return $this;
    }

    /**
     * 应用预定义样式
     *
     * 从 `config.styles` 中查找样式名对应的参数字符串，
     * 并委托给 parseUrlParams 解析。
     *
     * @param string $name 样式名称
     * @return $this
     * @throws ImageException 当样式未找到时
     */
    public function style(string $name): self
    {
        $styles = $this->config['styles'] ?? [];

        if (!isset($styles[$name])) {
            throw ImageException::styleNotFound($name);
        }

        return $this->parseUrlParams((string) $styles[$name]);
    }

    // -----------------------------------------------------------------
    // 上下文方法（供 Processor 使用）
    // -----------------------------------------------------------------

    /**
     * 获取完整配置
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 设置目标输出格式（由 FormatProcessor 调用）
     *
     * @param string $format 目标格式名（jpg/png/gif/webp/bmp）
     */
    public function setTargetFormat(string $format): void
    {
        $this->targetFormat = strtolower($format);
    }

    /**
     * 获取目标输出格式
     *
     * @return string|null
     */
    public function getTargetFormat(): ?string
    {
        return $this->targetFormat;
    }

    // -----------------------------------------------------------------
    // 执行引擎
    // -----------------------------------------------------------------

    /**
     * 执行处理链
     *
     * 依次：
     *   1. 安全中间件检查
     *   2. 获取/创建驱动
     *   3. 依次执行所有 Processor
     *   4. 标记已执行
     *
     * @return $this
     */
    public function execute(): self
    {
        $this->checkSecurity();
        $driver = $this->getDriver();

        // 图像尺寸与格式安全检查（5）
        $this->checkImageSecurity($driver);

        $startTime = microtime(true);

        foreach ($this->operations as [$processor, $params]) {
            $processor->process($this, $driver, $params);
        }

        $this->executed = true;
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        StatsCollector::getInstance()->recordProcess($durationMs);

        return $this;
    }

    // -----------------------------------------------------------------
    // 输出方法
    // -----------------------------------------------------------------

    /**
     * 保存图片到文件
     *
     * 若尚未执行，先调用 execute()。
     *
     * @param string       $path   目标文件路径
     * @param string|null  $format 输出格式（null 则按 targetFormat → 源格式）
     * @return bool 是否成功
     */
    public function save(string $path, ?string $format = null): bool
    {
        if (!$this->executed) {
            $this->execute();
        }

        $format = $format ?? $this->targetFormat;

        return $this->getDriver()->save($path, $format);
    }

    /**
     * 输出图片二进制字符串
     *
     * 若尚未执行，先调用 execute()。
     *
     * @param string|null $format 输出格式
     * @return string 图片二进制数据
     */
    public function output(?string $format = null): string
    {
        if (!$this->executed) {
            $this->execute();
        }

        $format = $format ?? $this->targetFormat;

        // 检查缓存
        $cached = $this->getCachedResult();
        if ($cached !== null) {
            StatsCollector::getInstance()->recordCacheHit();
            return $cached;
        }
        StatsCollector::getInstance()->recordCacheMiss();

        $result = $this->getDriver()->output($format);

        // 写入缓存
        $this->setCachedResult($result);

        return $result;
    }

    /**
     * HTTP 响应输出
     *
     * 设置 Content-Type 头后输出图片数据。
     *
     * @param string|null $format 输出格式
     */
    public function response(?string $format = null): void
    {
        if (!$this->executed) {
            $this->execute();
        }

        $format = $format ?? $this->targetFormat;
        $driver = $this->getDriver();

        // 确定 MIME 类型
        if ($format !== null) {
            $mimeMap = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'bmp'  => 'image/bmp',
            ];
            $mime = $mimeMap[strtolower($format)] ?? 'application/octet-stream';
        } else {
            $mime = $driver->getMime();
        }

        // 设置响应头
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=2592000');
        }

        echo $this->output($format);
    }

    /**
     * 获取当前图片信息
     *
     * @return array{width: int, height: int, mime: string, format: string, driver: string}
     */
    public function getInfo(): array
    {
        if (!$this->executed) {
            $this->execute();
        }
        return $this->getDriver()->getInfo();
    }

    /**
     * 销毁驱动资源
     */
    public function destroy(): void
    {
        if ($this->driver !== null) {
            $this->driver->destroy();
            $this->driver = null;
        }
        $this->executed = false;
    }

    // -----------------------------------------------------------------
    // 驱动工厂（内部）
    // -----------------------------------------------------------------

    /**
     * 获取或创建驱动实例
     *
     * 懒加载策略：首次调用时根据 config.driver 创建。
     *
     * @return DriverInterface
     * @throws ImageException 当驱动不存在或不可用时
     */
    private function getDriver(): DriverInterface
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $driverName = strtolower($this->config['driver'] ?? 'gd');

        $this->driver = match ($driverName) {
            'gd'      => new GdDriver(),
            'imagick' => new ImagickDriver(), // ImagickDriver.__construct 内检查扩展
            default   => throw ImageException::driverNotFound($driverName),
        };

        // 打开或创建
        if ($this->isMake) {
            $this->driver->create($this->makeWidth, $this->makeHeight);
        } elseif ($this->sourcePath !== '') {
            $this->driver->open($this->sourcePath);
        }

        return $this->driver;
    }

    // -----------------------------------------------------------------
    // 安全中间件（内部）
    // -----------------------------------------------------------------

    /**
     * 安全检查
     *
     * 依次检查 IP 黑名单 → Referer 防盗链 → 签名验证。
     * 仅当对应 config 项 enabled = true 时检查。
     *
     * @throws ImageException 安全检查未通过时
     */
    private function checkSecurity(): void
    {
        $security = $this->config['security'] ?? [];

        // 1. IP 黑名单
        if (!empty($security['ip_blacklist']['enabled'])) {
            $this->checkIpBlacklist($security['ip_blacklist']['ips'] ?? []);
        }

        // 2. Referer 防盗链
        if (!empty($security['referer']['enabled'])) {
            $this->checkReferer($security['referer']);
        }

        // 3. URL 签名验证
        if (!empty($security['signature']['enabled'])) {
            $this->checkSignature($security['signature']);
        }

        // 4. 原图保护（P2）
        if (!empty($security['origin_protect']['enabled'])) {
            $this->checkOriginProtect();
        }
    }

    /**
     * 图片尺寸与格式安全检查
     *
     * 防止 image bomb 攻击：超大尺寸图片可能导致内存溢出，
     * 非白名单格式可能存在解析漏洞。
     */
    private function checkImageSecurity(DriverInterface $driver): void
    {
        $security = $this->config['security'] ?? [];
        $maxWidth  = (int) ($security['max_width'] ?? 4096);
        $maxHeight = (int) ($security['max_height'] ?? 4096);
        $allowedFormats = (array) ($security['allowed_formats'] ?? []);

        // 尺寸检查
        if ($maxWidth > 0 && $maxHeight > 0) {
            $w = $driver->getWidth();
            $h = $driver->getHeight();
            if ($w > $maxWidth || $h > $maxHeight) {
                throw ImageException::processFailed('open', sprintf(
                    '图片尺寸 %dx%d 超过最大限制 %dx%d',
                    $w, $h, $maxWidth, $maxHeight
                ));
            }
        }

        // 格式检查
        if (!empty($allowedFormats)) {
            $mime   = $driver->getMime();
            $extMap = [
                'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
                'image/png'  => 'png', 'image/gif' => 'gif',
                'image/webp' => 'webp', 'image/bmp' => 'bmp', 'image/x-bmp' => 'bmp',
            ];
            $ext = $extMap[$mime] ?? null;
            if ($ext === null || !in_array($ext, $allowedFormats, true)) {
                throw ImageException::processFailed('open', sprintf(
                    '不支持图片格式: %s（允许: %s）',
                    $mime, implode(', ', $allowedFormats)
                ));
            }
        }
    }

    /**
     * IP 黑名单检查
     *
     * @param string[] $blacklistedIps 黑名单 IP 列表
     * @throws ImageException
     */
    private function checkIpBlacklist(array $blacklistedIps): void
    {
        if (empty($blacklistedIps)) {
            return;
        }

        // 使用框架请求实例（如果有），回退到超全局变量
        $clientIp = $this->request ? $this->request->ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        foreach ($blacklistedIps as $ip) {
            if ($this->ipMatches($clientIp, $ip)) {
                throw new ImageException(
                    'IP 在黑名单中，访问被拒绝',
                    ImageException::ERR_REFERER_DENIED,
                    ['client_ip' => $clientIp, 'rule' => $ip]
                );
            }
        }
    }

    /**
     * Referer 防盗链检查
     *
     * @param array<string, mixed> $rules Referer 规则配置
     * @throws ImageException
     */
    private function checkReferer(array $rules): void
    {
        // 使用框架请求实例，回退到超全局变量
        $referer = $this->request ? ($this->request->server('HTTP_REFERER', '')) : ($_SERVER['HTTP_REFERER'] ?? '');

        // 允许空 Referer
        if ($referer === '' && !empty($rules['allow_empty'])) {
            return;
        }

        // 检查黑名单（优先）
        if (!empty($rules['blacklist'])) {
            foreach ((array) $rules['blacklist'] as $pattern) {
                if ($this->refererMatches($referer, $pattern)) {
                    throw ImageException::refererDenied();
                }
            }
        }

        // 检查白名单
        if (!empty($rules['whitelist'])) {
            foreach ((array) $rules['whitelist'] as $pattern) {
                if ($this->refererMatches($referer, $pattern)) {
                    return; // 白名单匹配，放行
                }
            }
            throw ImageException::refererDenied();
        }
    }

    /**
     * URL 签名验证
     *
     * @param array<string, mixed> $sigConfig 签名配置
     * @throws ImageException
     */
    private function checkSignature(array $sigConfig): void
    {
        $secret = $sigConfig['secret'] ?? '';
        if ($secret === '') {
            return;
        }

        // 获取请求参数（使用框架请求回退到超全局）
        $queryParams = $this->request ? $this->request->get() : $_GET;
        $providedSign = $queryParams['sign'] ?? '';
        if ($providedSign === '') {
            throw ImageException::signInvalid();
        }

        // 构造待签名内容（去掉 sign 参数后的 URL + secret + timestamp）
        unset($queryParams['sign']);
        $signBase = http_build_query($queryParams) . $secret;

        // 检查超时
        $timeout = (int) ($sigConfig['timeout'] ?? 3600);
        if ($timeout > 0) {
            $ts = (int) ($queryParams['t'] ?? $queryParams['ts'] ?? 0);
            if ($ts > 0 && (time() - $ts) > $timeout) {
                throw ImageException::signInvalid();
            }
        }

        // 使用 HMAC-SHA256 替代 MD5，增强签名安全性
        $expectedSign = hash_hmac('sha256', $signBase, $secret);

        if (!hash_equals($expectedSign, $providedSign)) {
            throw ImageException::signInvalid();
        }
    }

    /**
     * 原图保护检查（P2）
     *
     * 当 origin_protect.enabled = true 时，禁止直接访问原始文件。
     * 必须通过 style 参数或 URL 参数指定处理操作，否则拒绝访问。
     */
    private function checkOriginProtect(): void
    {
        if (!empty($this->operations)) {
            return;
        }

        throw new ImageException(
            '原图保护已启用，禁止直接访问原始图片。请使用 style 参数或图片处理参数。',
            ImageException::ERR_REFERER_DENIED,
            ['source_path' => $this->sourcePath]
        );
    }

    // -----------------------------------------------------------------
    // 缓存支持（内部）
    // -----------------------------------------------------------------

    /**
     * 生成缓存键
     *
     * 基于源路径 + 操作链 + 关键配置项生成唯一 MD5 键。
     *
     * @return string
     */
    private function getCacheKey(): string
    {
        $seed = $this->sourcePath . '|';

        foreach ($this->operations as [$processor, $params]) {
            $seed .= $processor->getName() . ':' . json_encode($params) . ';';
        }

        $seed .= ($this->targetFormat ?? 'auto') . '|' . ($this->config['default_quality'] ?? 85);

        $prefix = $this->config['cache']['prefix'] ?? 'wise_image:';
        return $prefix . md5($seed);
    }

    /**
     * 尝试读取缓存结果
     *
     * @return string|null 缓存的图片数据，未命中返回 null
     */
    private function getCachedResult(): ?string
    {
        $cacheConfig = $this->config['cache'] ?? [];

        // 文件缓存驱动
        if (($cacheConfig['driver'] ?? 'file') === 'file') {
            $cachePath = $this->getCacheFilePath();

            if ($cachePath !== null && file_exists($cachePath)) {
                $ttl = (int) ($cacheConfig['ttl'] ?? 2592000);
                if ((time() - filemtime($cachePath)) < $ttl) {
                    $content = @file_get_contents($cachePath);
                    return $content !== false ? $content : null;
                }
            }

            return null;
        }

        // Redis 缓存驱动（依赖 think\cache）
        if (($cacheConfig['driver'] ?? '') === 'redis') {
            try {
                if (class_exists(\think\facade\Cache::class)) {
                    $key = $this->getCacheKey();
                    $result = \think\facade\Cache::get($key);
                    return is_string($result) ? $result : null;
                }
            } catch (\Throwable $e) {
                // 缓存不可用时静默降级
            }
        }

        return null;
    }

    /**
     * 写入缓存
     *
     * @param string $content 图片二进制数据
     */
    private function setCachedResult(string $content): void
    {
        $cacheConfig = $this->config['cache'] ?? [];

        // 文件缓存驱动
        if (($cacheConfig['driver'] ?? 'file') === 'file') {
            $cachePath = $this->getCacheFilePath();
            if ($cachePath !== null) {
                $dir = dirname($cachePath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @file_put_contents($cachePath, $content);
            }
            return;
        }

        // Redis 缓存驱动
        if (($cacheConfig['driver'] ?? '') === 'redis') {
            try {
                if (class_exists(\think\facade\Cache::class)) {
                    $key = $this->getCacheKey();
                    $ttl = (int) ($cacheConfig['ttl'] ?? 2592000);
                    \think\facade\Cache::set($key, $content, $ttl);
                }
            } catch (\Throwable $e) {
                // 缓存不可用时静默降级
            }
        }
    }

    /**
     * 获取文件缓存的完整路径
     *
     * @return string|null
     */
    private function getCacheFilePath(): ?string
    {
        $key = $this->getCacheKey();
        // 去掉前缀后的 MD5 部分
        $hash = str_replace($this->config['cache']['prefix'] ?? 'wise_image:', '', $key);

        $cacheDir = $this->config['cache']['path']
            ?? ('runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'cache');

        // 支持 ThinkPHP 运行时路径
        if (!str_starts_with($cacheDir, '/') && !preg_match('/^[A-Z]:/i', $cacheDir)) {
            $cacheDir = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . $cacheDir;
        }

        // 按 hash 前 2 位分片避免单目录文件过多
        $subDir = substr($hash, 0, 2);

        return $cacheDir . DIRECTORY_SEPARATOR . $subDir . DIRECTORY_SEPARATOR . $hash;
    }

    // -----------------------------------------------------------------
    // 内部工具方法
    // -----------------------------------------------------------------

    /**
     * 根据操作名解析并返回 Processor 实例（单例缓存）
     *
     * @param string $operationName 操作名
     * @return ProcessorInterface
     * @throws ImageException 当操作名未注册时
     */
    private function resolveProcessor(string $operationName): ProcessorInterface
    {
        if (isset(self::$processorCache[$operationName])) {
            return self::$processorCache[$operationName];
        }

        $className = self::OPERATION_MAP[$operationName] ?? null;

        if ($className === null) {
            throw ImageException::paramInvalid(
                "未知的图片操作：{$operationName}，支持：" . implode(' / ', array_keys(self::OPERATION_MAP))
            );
        }

        /** @var ProcessorInterface */
        $instance = new $className();
        self::$processorCache[$operationName] = $instance;

        return $instance;
    }

    /**
     * 检查客户端 IP 是否匹配规则（支持 CIDR 简写）
     *
     * @param string $clientIp 客户端 IP
     * @param string $rule     匹配规则（精确 IP 或 CIDR）
     * @return bool
     */
    private function ipMatches(string $clientIp, string $rule): bool
    {
        // 精确匹配
        if ($clientIp === $rule) {
            return true;
        }

        // CIDR 匹配
        if (str_contains($rule, '/')) {
            $parts = explode('/', $rule);
            $subnet  = $parts[0];
            $mask    = (int) ($parts[1] ?? 32);

            if ($mask < 0 || $mask > 32) {
                return false;
            }

            $clientLong = ip2long($clientIp);
            $subnetLong = ip2long($subnet);

            if ($clientLong === false || $subnetLong === false) {
                return false;
            }

            $netmask = -1 << (32 - $mask);

            return ($clientLong & $netmask) === ($subnetLong & $netmask);
        }

        return false;
    }

    /**
     * 检查 Referer 是否匹配规则（支持通配符 *）
     *
     * @param string $referer Referer URL
     * @param string $pattern 匹配模式
     * @return bool
     */
    private function refererMatches(string $referer, string $pattern): bool
    {
        // 将通配符 * 转为正则
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
        return preg_match($regex, $referer) === 1;
    }

    /**
     * 重置内部状态
     */
    private function reset(): void
    {
        $this->destroy();
        $this->sourcePath   = '';
        $this->targetFormat = null;
        $this->operations   = [];
        $this->executed     = false;
        $this->isMake       = false;
        $this->makeWidth    = 0;
        $this->makeHeight   = 0;
    }
}
