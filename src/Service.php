<?php

declare(strict_types=1);

namespace wise\image;

use wise\image\command\Publish;
use wise\image\trait\ServiceTrait;

/**
 * WiseAdmin 图片处理 — ThinkPHP Service 提供者
 *
 * 注册配置、命令和核心服务到容器。
 */
class Service extends \think\Service
{
    use ServiceTrait;

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

        // 注册命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                Publish::class,
            ]);
        }
    }

    /**
     * 启动服务
     */
    public function boot(): void
    {
        // 预留：缓存初始化、事件监听等
    }
}
