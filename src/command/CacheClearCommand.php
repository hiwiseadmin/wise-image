<?php

declare(strict_types=1);

namespace wise\image\command;

use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 清空图片缓存命令
 *
 * 用法：
 *   php think wise:image:cache-clear
 *   php think wise:image:cache-clear --dry-run
 */
class CacheClearCommand extends AbstractCacheCommand
{
    protected function configure(): void
    {
        $this->setName('wise:image:cache-clear')
            ->setDescription('清空 wise-image 图片缓存（含远程下载副本，仅 file 驱动）')
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅统计待清理内容，不实际删除');
    }

    protected function execute(Input $input, Output $output): int
    {
        $manager = $this->cacheManager();

        if (!$manager->supportsFileOperations()) {
            $output->writeln(sprintf(
                '<error>当前缓存驱动为 [%s]，磁盘治理命令不适用，请使用框架缓存命令清理。</error>',
                $manager->driver()
            ));
            return 1;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $result = $manager->clear($dryRun);

        $output->writeln(sprintf(
            '<info>%s：%d 个文件，%s</info>',
            $dryRun ? '待清理' : '已清理',
            $result['files'],
            $this->humanBytes($result['bytes'])
        ));
        $output->writeln('主缓存目录：' . $manager->cacheDir());
        $output->writeln('远程副本目录：' . $manager->remoteDir());

        return 0;
    }
}
