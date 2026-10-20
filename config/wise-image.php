<?php

declare(strict_types=1);

/**
 * WiseAdmin 图片处理包 — 默认配置
 *
 * 所有配置项均可被宿主应用的 config/wise-image.php 覆盖。
 */
return [
    // -----------------------------------------------------------------
    // 驱动配置
    // -----------------------------------------------------------------
    'driver' => 'gd',  // gd | imagick

    // -----------------------------------------------------------------
    // 缓存配置
    // -----------------------------------------------------------------
    'cache' => [
        'driver'    => 'file',                      // file | redis
        'ttl'       => 2592000,                     // 30 天
        'path'      => 'runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'cache',
        'prefix'    => 'wise_image:',

        // 单飞锁：同一 cache key 并发首访只允许一个请求真正处理（削峰）
        'lock' => [
            // 默认关闭，不改变现有时序；高并发场景建议开启
            'enabled'       => false,

            // 复用 wise-support DistributedLock：file（单机零依赖）| redis（多机）| database
            'driver'        => 'file',

            // 锁最长持有（秒），防死锁
            'ttl'           => 30,

            // 未抢到锁时等待缓存出现的最长时间（秒），超时降级自行处理
            'wait_timeout'  => 3.0,

            // 等待轮询间隔（毫秒）
            'poll_interval' => 50,

            // redis 驱动使用的缓存 store 名
            'redis_store'   => 'redis',

            // file 驱动的锁文件目录
            'path'          => 'runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'locks',
        ],
    ],

    // -----------------------------------------------------------------
    // 安全配置
    // -----------------------------------------------------------------
    'security' => [
        // Referer 防盗链
        'referer' => [
            'enabled'      => false,
            'allow_empty'  => true,
            'whitelist'    => [],
            'blacklist'    => [],
        ],

        // URL 签名验证
        'signature' => [
            'enabled'  => false,
            'secret'   => '',
            'timeout'  => 3600,
        ],

        // IP 黑名单
        'ip_blacklist' => [
            'enabled' => false,
            'ips'     => [],
        ],

        // 原图保护（禁止直接访问原图）
        'origin_protect' => [
            'enabled' => false,
        ],

        // 尺寸限制
        'max_width'       => 4096,
        'max_height'      => 4096,

        // 允许的图片格式
        'allowed_formats' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],

        // 允许访问的图片根目录：非空时禁止访问该目录之外的图片（防目录穿越），空 = 不限制
        'file_root' => '',
    ],

    // -----------------------------------------------------------------
    // 远程图片抓取（防 SSRF 设计，默认关闭）
    // -----------------------------------------------------------------
    'remote' => [
        // 总开关；未开启时 open('http://...') / openRemote() 会抛异常
        'enabled'          => false,

        // 读取 / 连接超时（秒）
        'timeout'          => 10,
        'connect_timeout'  => 5,

        // 单张图片最大字节数（默认 10MB）
        'max_size'         => 10485760,

        // 最大重定向次数（逐跳校验目标地址）
        'max_redirects'    => 3,

        // 允许的协议
        'allowed_schemes'  => ['http', 'https'],

        // host 白名单（空 = 不限制），支持 *.example.com；仍受内网拦截约束
        'allowed_hosts'    => [],

        // host 黑名单（优先级高于白名单）
        'blocked_hosts'    => [],

        // 是否允许解析到内网 / 保留地址（默认禁止；仅内网可信环境可开）
        'allow_private_ip' => false,

        // 允许的图片 MIME
        'allowed_mime'     => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'],

        // User-Agent 与额外请求头（如绕过源站防盗链：['Referer' => 'https://example.com/']）
        'user_agent'       => 'WiseImage/1.0 (+https://github.com/hiwiseadmin/wise-image)',
        'headers'          => [],

        // 是否校验 TLS 证书（生产环境请保持 true）
        'verify_tls'       => true,

        // 下载副本缓存
        'cache'            => [
            'enabled' => true,
            'path'    => 'runtime' . DIRECTORY_SEPARATOR . 'wise-image' . DIRECTORY_SEPARATOR . 'remote',
            'ttl'     => 86400,
        ],
    ],

    // -----------------------------------------------------------------
    // HTTP 响应缓存（协商缓存）
    // -----------------------------------------------------------------
    'response' => [
        // 是否输出 Cache-Control
        'cache_control' => true,

        // max-age（秒）；null = 取 cache.ttl
        'max_age'       => null,

        // 是否追加 immutable
        'immutable'     => false,

        // 输出 ETag，并支持 If-None-Match 命中返回 304
        'etag'          => true,

        // 输出 Last-Modified，并支持 If-Modified-Since 命中返回 304
        'last_modified' => true,

        // public | private
        'visibility'    => 'public',
    ],

    // -----------------------------------------------------------------
    // 预定义样式（阿里云 OSS 风格参数）
    // -----------------------------------------------------------------
    'styles' => [
        'thumb'  => 'resize,w_200,h_200/quality,q_80',
        'medium' => 'resize,w_800,h_600/quality,q_85',
        'large'  => 'resize,w_1920,h_1080/quality,q_90',
    ],

    // -----------------------------------------------------------------
    // 自动 WebP（浏览器支持时自动转换）
    // -----------------------------------------------------------------
    'auto_webp' => true,

    // -----------------------------------------------------------------
    // 默认图片质量 (0-100)
    // -----------------------------------------------------------------
    'default_quality' => 85,

];
