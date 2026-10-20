<?php

declare(strict_types=1);

namespace wise\image;


use wise\support\traits\RegistersMigrations;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\traits\ServiceTrait;

/**
 * WiseAdmin 图片处理 — ThinkPHP Service 提供者
 *
 * 注册配置、命令和核心服务到容器。
 */
class ImageServiceProvider extends \think\Service
{

    use RegistersMigrations;
    use ServiceTrait;

    /**
     * 提供的服务映射
     */
    /**
     * 注册服务
     */
    public function register(): void
    {
        // 合并默认配置
        $this->mergeConfig('wise-image');

        // 绑定 ImageManager 到容器
        $this->app->bind(ImageManager::class, function () {
            $config = $this->app->config->get('wise-image', []);
            $manager = new ImageManager($config);
            // 注入框架请求实例，替换超全局变量直接访问
            $manager->setRequest($this->app->request);
            return $manager;
        });
    }

    /**
     * 启动服务
     */
    public function boot(): void
    {
        $this->registerMigrations();

        // 将图片处理驱动注册到统一扩展点
        $this->registerImageExtPoints();

        // 将内置图片处理器注册到统一扩展点
        $this->registerProcessorExtPoints();

        // 注册缓存治理命令
        $this->registerCommands();
    }

    /**
     * 将内置处理器注册到 ExtPoints::IMAGE_PROCESSOR (multi 策略)
     *
     * 注册后插件的自定义处理器与内置处理器处于同一张表：
     *   - 新增操作：`ExtPoint::register(ExtPoints::IMAGE_PROCESSOR, 'sharpen', SharpenProcessor::class)`
     *     即可在 URL 参数 / 样式串 / `ImageManager::operation()` 中使用；
     *   - 覆盖内置：注册同名（如 'blur'）即优先于内置实现。
     *
     * 处理器列表以 {@see ImageManager::OPERATION_MAP} 为唯一来源，避免两处维护。
     */
    protected function registerProcessorExtPoints(): void
    {
        if (!ExtPoint::defined(ExtPoints::IMAGE_PROCESSOR)) {
            ExtPoint::definePoint(
                ExtPoints::IMAGE_PROCESSOR,
                ExtPoint::POLICY_MULTI,
                \wise\image\processor\ProcessorInterface::class
            );
        }

        foreach (ImageManager::OPERATION_MAP as $name => $class) {
            if (class_exists($class)) {
                ExtPoint::register(ExtPoints::IMAGE_PROCESSOR, $name, $class);
            }
        }
    }

    /**
     * 注册缓存治理命令
     *
     * 通过 think\Service::commands() 注册（仅在控制台启动时挂载，HTTP 请求无额外开销）：
     *   - wise:image:cache-clear  清空图片缓存与远程副本
     *   - wise:image:cache-prune  清理超过指定天数的缓存
     *   - wise:image:stats        统计缓存占用
     */
    protected function registerCommands(): void
    {
        $this->commands([
            \wise\image\command\CacheClearCommand::class,
            \wise\image\command\CachePruneCommand::class,
            \wise\image\command\StatsCommand::class,
        ]);
    }

    /**
     * 将图片处理驱动注册到 ExtPoints::IMAGE_DRIVER (single 策略)
     *
     * ImageManager 使用 match 硬编码选择驱动（gd/imagick），
     * 此处将两者注册并以配置中的 driver 值为活跃标识。
     *
     * 注意：setActive() 只影响本进程内存中的活跃标记，不会落库
     * （持久化由能力市场等用户操作入口显式调用 ExtPoint::persistOverrides()）。
     */
    protected function registerImageExtPoints(): void
    {
        if (!ExtPoint::defined(ExtPoints::IMAGE_DRIVER)) {
            ExtPoint::definePoint(ExtPoints::IMAGE_DRIVER, ExtPoint::POLICY_SINGLE);
        }

        // 通过反射读取 ImageManager 中 OPERATION_MAP 或已知驱动列表
        // 当前驱动列表来自 ImageManager::getDriver() 的 match 分支
        $knownDrivers = [
            'gd'      => \wise\image\driver\GdDriver::class,
            'imagick' => \wise\image\driver\ImagickDriver::class,
        ];

        $activeDriver = $this->app->config->get('wise-image.driver', 'gd');

        foreach ($knownDrivers as $name => $class) {
            if (class_exists($class)) {
                ExtPoint::register(ExtPoints::IMAGE_DRIVER, $name, $class);
            }
        }

        // 将配置中的活跃驱动标记为 active（仅内存，不落库）
        ExtPoint::setActive(ExtPoints::IMAGE_DRIVER, $activeDriver, true);
    }
}
