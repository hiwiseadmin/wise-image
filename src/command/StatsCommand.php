<?php

declare(strict_types=1);

namespace wise\image\command;

use think\console\Input;
use think\console\Output;

/**
 * 图片缓存统计命令
 *
 * 用法：
 *   php think wise:image:stats
 */
class StatsCommand extends AbstractCacheCommand
{
    protected function configure(): void
    {
        $this->setName('wise:image:stats')
            ->setDescription('统计 wise-image 缓存目录占用与时间跨度');
    }

    protected function execute(Input $input, Output $output): int
    {
        $manager = $this->cacheManager();
        $stats   = $manager->stats();

        $output->writeln('<info>wise-image 缓存统计</info>');
        $output->writeln('缓存驱动：' . $stats['driver']);

        $this->renderDir($output, '主缓存', $stats['main']);
        $this->renderDir($output, '远程副本', $stats['remote']);

        return 0;
    }

    /**
     * @param array{dir: string, exists: bool, files: int, bytes: int, oldest: int|null, newest: int|null} $info
     */
    private function renderDir(Output $output, string $label, array $info): void
    {
        $output->writeln('');
        $output->writeln(sprintf('<comment>%s</comment>：%s', $label, $info['dir']));

        if (!$info['exists']) {
            $output->writeln('  目录不存在（尚无缓存）');
            return;
        }

        $output->writeln(sprintf('  文件数：%d', $info['files']));
        $output->writeln(sprintf('  占用：%s', $this->humanBytes($info['bytes'])));
        $output->writeln(sprintf('  最早：%s', $this->formatTime($info['oldest'])));
        $output->writeln(sprintf('  最新：%s', $this->formatTime($info['newest'])));
    }
}
