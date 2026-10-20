<?php

declare(strict_types=1);

namespace wise\image\command;

use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 清理过期图片缓存命令
 *
 * 用法：
 *   php think wise:image:cache-prune                # 默认按 cache.ttl 折算天数
 *   php think wise:image:cache-prune --days=7
 *   php think wise:image:cache-prune --days=7 --dry-run
 */
class CachePruneCommand extends AbstractCacheCommand
{
    protected function configure(): void
    {
        $this->setName('wise:image:cache-prune')
            ->setDescription('清理超过指定天数的 wise-image 缓存文件（仅 file 驱动）')
            ->addOption('days', 'd', Option::VALUE_REQUIRED, '保留天数（默认取配置 cache.ttl 折算）')
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

        $daysOption = $input->getOption('days');
        if ($daysOption === null || $daysOption === '') {
            $cache = (array) ($this->imageConfig()['cache'] ?? []);
            $ttl   = (int) ($cache['ttl'] ?? 2592000);
            $days  = max(1, (int) ceil($ttl / 86400));
        } else {
            $days = max(1, (int) $daysOption);
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $result = $manager->prune($days, $dryRun);

        $output->writeln(sprintf(
            '<info>%s %d 天前的缓存：%d 个文件，%s</info>',
            $dryRun ? '待清理' : '已清理',
            $result['days'],
            $result['files'],
            $this->humanBytes($result['bytes'])
        ));

        return 0;
    }
}
