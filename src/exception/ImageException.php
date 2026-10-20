<?php
declare(strict_types=1);

namespace wise\image\exception;

use wise\support\exception\WiseException;

/**
 * 图片处理统一异常类
 *
 * 包含所有静态工厂方法，每个工厂方法自动关联错误码、消息及上下文信息。
 * 错误码统一定义在 {@see ErrorCode} 枚举中（号段 120000~129999），
 * 本类不再重复定义错误码常量。
 */
class ImageException extends WiseException
{
    // -----------------------------------------------------------------
    // 构造函数
    // -----------------------------------------------------------------

    /**
     * @param string               $message 异常消息
     * @param int                  $code    错误码
     * @param array<string, mixed> $context 额外上下文信息
     * @param int                  $httpCode HTTP 状态码
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        protected array $context = [],
        int $httpCode = 400
    ) {
        $code = $code ?: ErrorCode::PROCESS_FAILED->value;
        parent::__construct($code, $message, $httpCode, null, $context);
    }

    // -----------------------------------------------------------------
    // 静态工厂方法
    // -----------------------------------------------------------------

    /**
     * 驱动未找到
     *
     * @param string $driver 驱动名称
     */
    public static function driverNotFound(string $driver): self
    {
        return new self(
            "图片驱动未找到：{$driver}，仅支持 gd / imagick",
            ErrorCode::DRIVER_NOT_FOUND->value,
            ['driver' => $driver],
            500
        );
    }

    /**
     * 文件未找到
     *
     * @param string $path 文件路径
     */
    public static function fileNotFound(string $path): self
    {
        return new self(
            "图片文件未找到：{$path}",
            ErrorCode::FILE_NOT_FOUND->value,
            ['path' => $path],
            404
        );
    }

    /**
     * 无效的图片格式
     *
     * @param string $format 格式名称
     */
    public static function invalidFormat(string $format): self
    {
        return new self(
            "不支持的图片格式：{$format}",
            ErrorCode::INVALID_FORMAT->value,
            ['format' => $format]
        );
    }

    /**
     * 图片处理失败
     *
     * @param string $operation 操作名称
     * @param string $reason    失败原因
     */
    public static function processFailed(string $operation, string $reason): self
    {
        return new self(
            "图片处理失败 [{$operation}]：{$reason}",
            ErrorCode::PROCESS_FAILED->value,
            ['operation' => $operation, 'reason' => $reason],
            500
        );
    }

    /**
     * 参数无效
     *
     * @param string $message 参数错误描述
     */
    public static function paramInvalid(string $message): self
    {
        return new self(
            "参数无效：{$message}",
            ErrorCode::PARAM_INVALID->value,
            ['detail' => $message]
        );
    }

    /**
     * 签名验证失败
     */
    public static function signInvalid(): self
    {
        return new self(
            '图片请求签名验证失败',
            ErrorCode::SIGN_INVALID->value,
            [],
            403
        );
    }

    /**
     * 文件访问被路径约束拒绝（security.file_root）
     *
     * @param string $path 被拒绝的文件路径
     * @param string $root 允许的根目录
     */
    public static function fileAccessDenied(string $path, string $root): self
    {
        return new self(
            "图片文件超出允许的根目录：{$path}（允许：{$root}）",
            ErrorCode::FILE_ACCESS_DENIED->value,
            ['path' => $path, 'file_root' => $root],
            403
        );
    }

    /**
     * 签名密钥未配置
     */
    public static function signNotConfigured(): self
    {
        return new self(
            '图片签名密钥未配置，请设置 config/wise-image.php 的 security.signature.secret',
            ErrorCode::SIGN_NOT_CONFIGURED->value,
            [],
            500
        );
    }

    /**
     * Referer 防盗链拒绝访问
     */
    public static function refererDenied(): self
    {
        return new self(
            '图片请求被防盗链规则拒绝',
            ErrorCode::REFERER_DENIED->value,
            [],
            403
        );
    }

    /**
     * 预定义样式未找到
     *
     * @param string $name 样式名称
     */
    public static function styleNotFound(string $name): self
    {
        return new self(
            "预定义样式未找到：{$name}",
            ErrorCode::STYLE_NOT_FOUND->value,
            ['style_name' => $name],
            404
        );
    }

    /**
     * 驱动未就绪
     *
     * @param string $reason 原因描述
     */
    public static function driverNotReady(string $reason): self
    {
        return new self(
            "图片驱动未就绪：{$reason}",
            ErrorCode::DRIVER_NOT_READY->value,
            ['reason' => $reason],
            500
        );
    }

    /**
     * 缓存操作失败
     *
     * @param string $operation 缓存操作（read/write/delete）
     * @param string $reason    失败原因
     */
    public static function cacheFailed(string $operation, string $reason): self
    {
        return new self(
            "缓存操作失败 [{$operation}]：{$reason}",
            ErrorCode::CACHE_FAILED->value,
            ['operation' => $operation, 'reason' => $reason],
            500
        );
    }

    // -----------------------------------------------------------------
    // 远程图片抓取（remote）
    // -----------------------------------------------------------------

    /**
     * 远程图片抓取功能未启用
     */
    public static function remoteDisabled(): self
    {
        return new self(
            '远程图片抓取功能未启用，请在 config/wise-image.php 的 remote.enabled 中开启',
            ErrorCode::REMOTE_DISABLED->value,
            [],
            403
        );
    }

    /**
     * 远程图片下载失败
     *
     * @param string $url    目标 URL
     * @param string $reason 失败原因
     */
    public static function remoteFetchFailed(string $url, string $reason): self
    {
        return new self(
            "远程图片下载失败：{$reason}",
            ErrorCode::REMOTE_FETCH_FAILED->value,
            ['url' => $url, 'reason' => $reason],
            502
        );
    }

    /**
     * 远程主机被拒绝（协议 / 白名单 / 内网地址）
     *
     * @param string $url    目标 URL
     * @param string $reason 拒绝原因
     */
    public static function remoteHostDenied(string $url, string $reason): self
    {
        return new self(
            "远程图片主机被拒绝：{$reason}",
            ErrorCode::REMOTE_HOST_DENIED->value,
            ['url' => $url, 'reason' => $reason],
            403
        );
    }

    /**
     * 远程图片超出大小限制
     *
     * @param string $url     目标 URL
     * @param int    $size    实际大小（字节）
     * @param int    $maxSize 允许上限（字节）
     */
    public static function remoteTooLarge(string $url, int $size, int $maxSize): self
    {
        return new self(
            "远程图片超出大小限制：{$size} > {$maxSize} 字节",
            ErrorCode::REMOTE_TOO_LARGE->value,
            ['url' => $url, 'size' => $size, 'max_size' => $maxSize],
            413
        );
    }

    /**
     * 远程资源不是受支持的图片类型
     *
     * @param string $url  目标 URL
     * @param string $mime 实际 MIME
     */
    public static function remoteInvalidType(string $url, string $mime): self
    {
        return new self(
            "远程资源不是受支持的图片类型：{$mime}",
            ErrorCode::REMOTE_INVALID_TYPE->value,
            ['url' => $url, 'mime' => $mime],
            415
        );
    }
}
