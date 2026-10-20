# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- **链式处理 API**：为 9 个处理器补齐同名链式方法，README / PRD 示例终于可运行。
  - 新增 `resize()` / `width()` / `height()` / `crop()` / `cropByGravity()` / `watermark()` / `textWatermark()`
    / `format()` / `autoFormat()` / `quality()` / `rotate()` / `blur()` / `brightness()` / `contrast()`。
  - `ResizeProcessor` 支持单边等比缩放（只给宽或高），并支持 `contain` / `cover` / `stretch` / `percent` 等模式别名。
  - `PositionHelper::normalize()` 支持 `southeast` / `bottom-right` / `pos_southeast` 等位置写法，裁剪与水印共用。
- **领域事件派发**：`ImageProcessingEvent` / `ImageProcessedEvent` / `ImageCacheHitEvent` 现在会被真实派发。
  - 新增 `ImageManager::setEventDispatcher()`，支持注入自定义总线（便于测试与解耦）。
  - 监听器异常与事件总线缺失均静默降级，不阻断图片处理。
- **签名器 `wise\image\security\Signer`**：统一的签名生成与校验实现。
  - 新增 `ImageManager::getSigner()` / `ImageManager::signUrl()`，可直接生成带签名的图片 URL。
  - 新增错误码 120016（`SIGN_NOT_CONFIGURED`）。
- **远程图片抓取**：新增 `wise\image\remote\RemoteFetcher`，支持将 `http(s)://` 远程图片下载到本地后再处理。
  - `ImageManager::openRemote()` / `ImageManager::download()` / `ImageManager::isRemoteUrl()`，并支持 `open()` 自动识别远程 URL。
  - Facade 同步提供 `Image::openRemote()` / `Image::download()`。
  - 新增 `remote` 配置段（默认关闭）：超时、最大体积、重定向次数、协议白名单、host 黑白名单、MIME 白名单、TLS 校验、本地缓存等。
  - 安全防护：DNS 解析结果校验内网/回环/保留/CGNAT/隧道地址、逐跳校验重定向、`CURLOPT_RESOLVE` 绑定 IP 防 DNS Rebinding、边下边计数限制体积、`Content-Type` + `getimagesizefromstring` 双重校验。
  - 新增错误码 120011~120015 及对应 `ImageException` 工厂方法。
  - `StatsCollector` 新增 `remote_fetches` 统计项。

- **缓存治理命令**：新增 `wise:image:cache-clear` / `wise:image:cache-prune` / `wise:image:stats`，
  由 `ImageServiceProvider::registerCommands()` 通过 `think\Service::commands()` 注册。
  配套新增 `wise\image\cache\CacheManager`（统计 / 清空 / 过期裁剪）与 `CachePathResolver`（缓存目录解析，消除三处重复实现）。
- **HTTP 协商缓存**：新增 `response` 配置段（`max_age` / `immutable` / `visibility` / `etag` / `last_modified` / `cache_control`），
  以及 `ImageManager::httpCacheState()`，支持 `If-None-Match` / `If-Modified-Since` 命中返回 304。
- **驱动扩展点生效**：`getDriver()` 改为优先从 `ExtPoints::IMAGE_DRIVER` 解析驱动类（未注册时回退内置 `gd` / `imagick`），
  宿主与插件可注册自定义驱动。
- **路径约束**：新增 `security.file_root`（默认空 = 不限制），非空时禁止访问根目录之外的图片文件。
- 新增错误码 120017（`FILE_ACCESS_DENIED`）。

- **处理器扩展点**：新增 `ExtPoints::IMAGE_PROCESSOR`（`multi` 策略，wis-support 中声明），
  `ImageServiceProvider` 启动时把 9 个内置处理器注册进去；`ImageManager::resolveProcessor()` 改为
  「扩展点优先 → 内置兜底」，插件可新增自定义操作（`sharpen` / `qrcode` / `label` …）或覆盖内置实现。
  - 新增通用链式入口 `ImageManager::operation(string $name, array $params = [])`。
  - 新增 `ImageManager::availableOperations()` 与 `ImageManager::clearProcessorCache()`。
  - `ImageManager::OPERATION_MAP` 由 `private` 改为 `public`，作为内置处理器单一来源。
- **缓存击穿治理（单飞锁）**：新增 `cache.lock` 配置段（默认关闭），复用 `wise-support` 的
  `DistributedLock`（file / redis / database 三驱动）；新增 `ImageManager::cacheLock()` 与
  `setCacheLock()`。命中锁竞争时等待缓存，超时降级自行处理。
  `StatsCollector` 新增 `lock_wait_hits` / `lock_wait_timeouts` 统计项。
- `FormatProcessor::predict()`：新增静态输出格式预测方法，供缓存键提前计算复用。

### Fixed
- **修复签名可跨图片重放**：原签名串不含图片路径，一张图的合法签名可套用到任意另一张图。
  现在签名串强制包含 `path`，并支持传入完整 URL（自动提取路径）。
- **修复签名永不过期**：原实现仅在传入 `t` 时才校验超时，不传即长期有效。
  现在 `timeout > 0` 时强制要求时间戳，并同时校验「已过期」与「未来时间」。
- **修复链式 API 缺失**：`Image::open(...)->resize(...)` 等文档示例此前会抛
  `Call to undefined method`，属于主推 API 不可用。
- 修复签名的参数顺序敏感性：参与签名的参数会先 `ksort` 排序，客户端换序不再导致误判。
- 修复 `ImageException` 中 `self::ERR_PROCESS_FAILED` / `ERR_REFERER_DENIED` / `ERR_DRIVER_NOT_READY`
  与 `ImageManager` 中 `ImageException::ERR_REFERER_DENIED` 引用未定义常量导致的运行时错误，
  统一改用 `ErrorCode` 枚举（错误码随之由 1xxx 变更为 12xxxx 号段）。
- 修复 `remote` 嵌套配置使用浅合并导致部分覆盖时丢失默认值的问题（改为关联数组递归合并、列表整体覆盖）。

### Changed
- **破坏性变更**：签名算法与规则升级，旧的签名 URL 将失效，需按新规则重新生成。
  新规则：`hash_hmac('sha256', path + '?' + 排序参数 + '&t=' + 时间戳, secret)`。
- `tests/ExceptionTest.php` 断言更新为 `ErrorCode` 枚举号段，并补充远程抓取、签名相关用例。
- 新增测试 `tests/ChainApiTest.php`、`tests/EventDispatchTest.php`、`tests/SignerTest.php`。
- `README.md` 补充远程抓取、签名防盗链章节，处理器表补充链式方法与模式/位置别名。
- **缓存键加入源文件指纹**：`filemtime + filesize` 与驱动名纳入缓存键，原图被替换后旧缓存自动失效，无需手动清理。
- `security` / `cache` / `remote` / `response` 嵌套配置改为递归合并，部分覆盖不再丢失同级默认值；
  `styles` 仍保持「整体覆盖」语义。
- **ImagickDriver 不再直接放行远程 URL**：`open('http(s)://...')` 改为委托 `RemoteFetcher` 抓取，
  获得 SSRF / 体积 / 超时 / 类型校验；`ImagickDriver::__construct()` 新增可选 `$remoteConfig` 参数。
- **破坏性变更**：`ImagickDriver::open()` 不再接受非 `http(s)` 的 URL（如 `file://`），一律按本地文件处理。
- `response()` 的 `Cache-Control` 由硬编码 30 天改为读 `response` 配置（`max_age` 缺省时仍取 `cache.ttl`）。
- 新增测试 `CacheFingerprintTest` / `CacheGovernanceTest` / `HttpCacheTest` / `ConfigMergeTest` /
  `FileRootGuardTest` / `DriverExtPointTest`，并修正既有测试中残留的旧错误码断言。
- **缓存查询前移（重要性能修复）**：`output()` / `response()` 原先「先执行处理链、再查缓存」，
  导致缓存命中时解码 / 缩放 / 滤镜等成本照付，缓存仅省下最终编码一步；
  现改为「先查缓存，命中则完全跳过处理链」。为此新增 `resolveOutputFormat()` /
  `resolveOutputMime()`（MIME 只读文件头，不再为取 MIME 而完整解码）。
  **缓存键算法未变**，不会造成额外缓存失效。
- 新增测试 `CacheStampedeTest` / `ProcessorExtPointTest`。

## [1.0.0] - 2024-01-01

### Added
- Initial release

[1.0.0]: https://github.com/wiseadmin/wise-image/releases/tag/v1.0.0
