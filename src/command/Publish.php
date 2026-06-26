<?php

declare(strict_types=1);

namespace wise\image\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\image\trait\CommandTrait;

/**
 * Publish 命令 — 发布配置文件
 *
 * 用法：php think wise:image:publish [--force]
 */
class Publish extends Command
{
    use CommandTrait;

    /**
     * 配置命令
     */
    protected function configure(): void
    {
        $this->setName('wise:image:publish')
            ->setDescription('发布图片处理配置文件')
            ->addOption('force', 'f', Option::VALUE_NONE, '强制覆盖已存在的配置文件');
    }

    /**
     * {@inheritDoc}
     */
    protected function getPackageConfigName(): string
    {
        return 'wise-image';
    }

    /**
     * 执行命令
     *
     * @param Input  $input
     * @param Output $output
     * @return int
     */
    protected function execute(Input $input, Output $output): int
    {
        $force = $input->getOption('force');

        $this->printBanner($output, 'wise-image');

        // 发布配置文件
        $this->publishConfig($output, $force);

        // 发布迁移文件（如有）
        $migrationsPublished = $this->publishMigrations($output);

        $this->printSuccessFooter($output, '图片处理', $migrationsPublished);

        return 0;
    }
}
