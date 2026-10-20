<?php
declare(strict_types=1);

namespace wise\image\exception;

use wise\support\exception\ErrorCodeInterface;

/**
 * wise-image 业务错误码（号段 120000~129999）
 *
 *  120000~120099  图片处理
 *
 * @package wise\image\exception
 */
enum ErrorCode: int implements ErrorCodeInterface
{
    /** 驱动未找到 */
    case DRIVER_NOT_FOUND = 120001;

    /** 文件未找到 */
    case FILE_NOT_FOUND   = 120002;

    /** 无效的图片格式 */
    case INVALID_FORMAT   = 120003;

    /** 图片处理失败 */
    case PROCESS_FAILED   = 120004;

    /** 参数无效 */
    case PARAM_INVALID    = 120005;

    /** 签名验证失败 */
    case SIGN_INVALID     = 120006;

    /** Referer 防盗链拒绝 */
    case REFERER_DENIED   = 120007;

    /** 预定义样式未找到 */
    case STYLE_NOT_FOUND  = 120008;

    /** 驱动未就绪 */
    case DRIVER_NOT_READY = 120009;

    /** 缓存操作失败 */
    case CACHE_FAILED     = 120010;

    /** 远程图片源功能未启用 */
    case REMOTE_DISABLED     = 120011;

    /** 远程图片下载失败 */
    case REMOTE_FETCH_FAILED = 120012;

    /** 远程图片主机被拒绝（SSRF 防护） */
    case REMOTE_HOST_DENIED  = 120013;

    /** 远程图片超出大小限制 */
    case REMOTE_TOO_LARGE    = 120014;

    /** 远程资源不是受支持的图片类型 */
    case REMOTE_INVALID_TYPE = 120015;

    /** 签名密钥未配置 */
    case SIGN_NOT_CONFIGURED = 120016;

    /** 文件访问被路径约束拒绝 */
    case FILE_ACCESS_DENIED = 120017;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::DRIVER_NOT_FOUND     => '图片驱动未找到',
            self::FILE_NOT_FOUND       => '图片文件未找到',
            self::INVALID_FORMAT       => '不支持的图片格式',
            self::PROCESS_FAILED       => '图片处理失败',
            self::PARAM_INVALID        => '参数无效',
            self::SIGN_INVALID         => '图片请求签名验证失败',
            self::REFERER_DENIED       => '图片请求被防盗链规则拒绝',
            self::STYLE_NOT_FOUND      => '预定义样式未找到',
            self::DRIVER_NOT_READY     => '图片驱动未就绪',
            self::CACHE_FAILED         => '缓存操作失败',
            self::REMOTE_DISABLED      => '远程图片抓取功能未启用',
            self::REMOTE_FETCH_FAILED  => '远程图片下载失败',
            self::REMOTE_HOST_DENIED   => '远程图片主机被拒绝',
            self::REMOTE_TOO_LARGE     => '远程图片超出大小限制',
            self::REMOTE_INVALID_TYPE  => '远程资源不是受支持的图片类型',
            self::SIGN_NOT_CONFIGURED  => '图片签名密钥未配置',
            self::FILE_ACCESS_DENIED   => '图片文件访问被路径约束拒绝',
        };
    }
}
