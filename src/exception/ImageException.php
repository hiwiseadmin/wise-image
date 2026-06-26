<?php

declare(strict_types=1);

namespace wise\image\exception;

/**
 * 图片处理统一异常类
 *
 * 包含所有错误码常量和对应的静态工厂方法，
 * 每个工厂方法自动关联错误码、消息及上下文信息。
 */
class ImageException extends \RuntimeException
{
    // -----------------------------------------------------------------
    // 错误码常量
    // -----------------------------------------------------------------

    /** 驱动未找到 */
    public const ERR_DRIVER_NOT_FOUND = 1001;

    /** 文件未找到 */
    public const ERR_FILE_NOT_FOUND = 1002;

    /** 无效的图片格式 */
    public const ERR_INVALID_FORMAT = 1003;

    /** 图片处理失败 */
    public const ERR_PROCESS_FAILED = 1004;

    /** 参数无效 */
    public const ERR_PARAM_INVALID = 1005;

    /** 签名验证失败 */
    public const ERR_SIGN_INVALID = 1006;

    /** Referer 防盗链拒绝 */
    public const ERR_REFERER_DENIED = 1007;

    /** 预定义样式未找到 */
    public const ERR_STYLE_NOT_FOUND = 1008;

    /** 驱动未就绪 */
    public const ERR_DRIVER_NOT_READY = 1009;

    /** 缓存操作失败 */
    public const ERR_CACHE_FAILED = 1010;

    // -----------------------------------------------------------------
    // 构造函数
    // -----------------------------------------------------------------

    /**
     * @param string              $message 异常消息
     * @param int                 $code    错误码
     * @param array<string, mixed> $context 额外上下文信息
     */
    public function __construct(string $message = '', int $code = 0, protected array $context = [])
    {
        parent::__construct($message, $code);
    }

    /**
     * 获取上下文信息
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
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
            self::ERR_DRIVER_NOT_FOUND,
            ['driver' => $driver]
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
            self::ERR_FILE_NOT_FOUND,
            ['path' => $path]
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
            self::ERR_INVALID_FORMAT,
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
            self::ERR_PROCESS_FAILED,
            ['operation' => $operation, 'reason' => $reason]
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
            self::ERR_PARAM_INVALID,
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
            self::ERR_SIGN_INVALID
        );
    }

    /**
     * Referer 防盗链拒绝访问
     */
    public static function refererDenied(): self
    {
        return new self(
            '图片请求被防盗链规则拒绝',
            self::ERR_REFERER_DENIED
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
            self::ERR_STYLE_NOT_FOUND,
            ['style_name' => $name]
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
            self::ERR_DRIVER_NOT_READY,
            ['reason' => $reason]
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
            self::ERR_CACHE_FAILED,
            ['operation' => $operation, 'reason' => $reason]
        );
    }
}
