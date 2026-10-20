<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\ImageManager;

/**
 * 配置合并测试（item 11）
 *
 * 现状问题：array_merge 浅合并导致宿主只覆盖嵌套配置的单个字段时，
 * 同级的其余默认值全部丢失（如只配 security.max_width 会丢掉 allowed_formats）。
 */
class ConfigMergeTest extends TestCase
{
    public function testSecurityPartialOverrideKeepsDefaults(): void
    {
        $config   = (new ImageManager(['security' => ['max_width' => 100]]))->getConfig();
        $security = $config['security'];

        $this->assertSame(100, $security['max_width']);
        $this->assertSame(4096, $security['max_height'], '未覆盖的默认值必须保留');
        $this->assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], $security['allowed_formats']);
        $this->assertSame('', $security['file_root']);
        $this->assertFalse($security['signature']['enabled']);
    }

    public function testSecurityNestedPartialOverrideKeepsSiblings(): void
    {
        $security = (new ImageManager([
            'security' => ['referer' => ['enabled' => true]],
        ]))->getConfig()['security'];

        $this->assertTrue($security['referer']['enabled']);
        $this->assertTrue($security['referer']['allow_empty'], '同级默认值必须保留');
        $this->assertSame([], $security['referer']['whitelist']);
        $this->assertFalse($security['ip_blacklist']['enabled']);
    }

    public function testCachePartialOverrideKeepsDefaults(): void
    {
        $cache = (new ImageManager(['cache' => ['ttl' => 10]]))->getConfig()['cache'];

        $this->assertSame(10, $cache['ttl']);
        $this->assertSame('file', $cache['driver']);
        $this->assertSame('wise_image:', $cache['prefix']);
        $this->assertNotEmpty($cache['path']);
    }

    public function testRemotePartialOverrideKeepsDefaults(): void
    {
        $remote = (new ImageManager(['remote' => ['timeout' => 3]]))->getConfig()['remote'];

        $this->assertSame(3, $remote['timeout']);
        $this->assertFalse($remote['enabled']);
        $this->assertSame(10485760, $remote['max_size']);
    }

    public function testListValuesAreReplacedNotIndexMerged(): void
    {
        $security = (new ImageManager([
            'security' => ['allowed_formats' => ['png']],
        ]))->getConfig()['security'];

        $this->assertSame(['png'], $security['allowed_formats'], '列表型配置应整体覆盖，不能按索引错位合并');
    }

    public function testResponseDefaultsArePresent(): void
    {
        $response = (new ImageManager())->getConfig()['response'];

        $this->assertTrue($response['cache_control']);
        $this->assertNull($response['max_age']);
        $this->assertTrue($response['etag']);
        $this->assertTrue($response['last_modified']);
        $this->assertSame('public', $response['visibility']);
    }

    public function testResponsePartialOverrideKeepsDefaults(): void
    {
        $response = (new ImageManager(['response' => ['max_age' => 600]]))->getConfig()['response'];

        $this->assertSame(600, $response['max_age']);
        $this->assertTrue($response['cache_control'], '未覆盖的 response 默认值必须保留');
        $this->assertTrue($response['etag']);
        $this->assertSame('public', $response['visibility']);
    }

    public function testStylesStillReplaceEntirely(): void
    {
        // styles 保持「整体覆盖」语义，避免默认样式无法被清空
        $styles = (new ImageManager(['styles' => ['custom' => 'resize,w_10']]))->getConfig()['styles'];

        $this->assertSame(['custom' => 'resize,w_10'], $styles);
    }
}
