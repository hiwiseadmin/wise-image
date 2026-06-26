<?php

declare(strict_types=1);

namespace wise\image\trait;

use think\console\Output;

/**
 * WiseAdmin 包 Publish 命令共用 Trait
 *
 * 提取了所有 wise-* 包在 Publish（初始化发布）命令中反复出现的公共逻辑。
 *
 * 使用本 Trait 的类必须继承 think\console\Command，
 * 且必须实现 getPackageConfigName()。
 */
trait CommandTrait
{
    /**
     * 获取包的配置文件名（不含 .php 后缀）
     *
     * @return string
     */
    abstract protected function getPackageConfigName(): string;

    /**
     * 获取包根目录的绝对路径（末尾带分隔符）
     *
     * 默认实现：从当前文件向上两级（src/command/Publish.php → 包根目录）。
     *
     * @return string
     */
    protected function getPackageRootDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    /**
     * 打印标准初始化 Banner
     *
     * @param Output $output
     * @param string $packageName 包名（用于显示），如 'wise-image'
     */
    protected function printBanner(Output $output, string $packageName): void
    {
        $output->writeln('');
        $output->writeln('<info>========================================</info>');
        $output->writeln("<info>wiseadmin/{$packageName} - 初始化</info>");
        $output->writeln('<info>========================================</info>');
        $output->writeln('');
    }

    /**
     * 打印初始化成功的结束语
     *
     * @param Output $output
     * @param string $label           初始化模块名称，如 '图片处理'
     * @param bool   $migrationsPublished 是否有迁移文件被复制
     */
    protected function printSuccessFooter(Output $output, string $label, bool $migrationsPublished = false): void
    {
        $output->writeln('');
        if ($migrationsPublished) {
            $output->writeln('<info>请执行以下命令运行数据库迁移：</info>');
            $output->writeln('<comment>  php think migrate:run</comment>');
        }
        $output->writeln('');
        $output->writeln("<info>{$label}初始化成功！</info>");
    }

    /**
     * 发布配置文件到应用 config 目录
     *
     * @param Output $output
     * @param bool   $force 是否强制覆盖已存在的配置文件
     */
    protected function publishConfig(Output $output, bool $force): void
    {
        $configName = $this->getPackageConfigName();
        $source     = $this->getPackageRootDir() . 'config' . DIRECTORY_SEPARATOR . $configName . '.php';
        $target     = $this->app->getConfigPath() . $configName . '.php';

        if (!file_exists($source)) {
            $output->writeln("<error>包配置文件未找到：{$source}</error>");
            return;
        }

        if (file_exists($target) && !$force) {
            $output->writeln("<comment>配置文件已存在于 {$target}，使用 --force 强制覆盖。</comment>");
            return;
        }

        $targetDir = dirname($target);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        if (copy($source, $target)) {
            $output->writeln("<info>配置已发布到：{$target}</info>");
        } else {
            $output->writeln("<error>配置发布失败：{$target}</error>");
        }
    }

    /**
     * 复制包内迁移文件到应用 database/migrations 目录
     *
     * @param Output $output
     * @return bool 是否成功复制了至少一个文件
     */
    protected function publishMigrations(Output $output): bool
    {
        $sourceDir = $this->getPackageRootDir() . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $targetDir = $this->app->getRootPath() . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        if (!is_dir($sourceDir)) {
            return false;
        }

        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                $output->writeln("<error>无法创建目标目录：{$targetDir}</error>");
                return false;
            }
        }

        $files   = glob($sourceDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        $copied  = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $filename = basename($file);
            $target   = $targetDir . DIRECTORY_SEPARATOR . $filename;

            if (file_exists($target)) {
                $output->writeln("  <comment>[SKIP]</comment> {$filename}（已存在）");
                $skipped++;
                continue;
            }

            if (copy($file, $target)) {
                $output->writeln("  <info>[OK]</info>   {$filename}");
                $copied++;
            } else {
                $output->writeln("  <error>[FAIL]</error> {$filename}");
            }
        }

        if ($copied > 0 || $skipped > 0) {
            $output->writeln('');
            $output->writeln("<info>迁移文件：已复制 {$copied}，已跳过 {$skipped}</info>");
            $output->writeln("<info>目标目录：{$targetDir}</info>");
        }

        return $copied > 0;
    }
}
