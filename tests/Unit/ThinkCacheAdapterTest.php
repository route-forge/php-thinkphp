<?php

declare(strict_types=1);

namespace RouteForge\ThinkPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\ThinkPHP\Adapter\ThinkCacheAdapter;
use think\App;
use think\cache\driver\File as FileDriver;

class ThinkCacheAdapterTest extends TestCase
{
    private function makeAdapter(): ThinkCacheAdapter
    {
        $path = (getenv('RF_TEST_TMP') ?: (is_dir('F:/tmp') ? 'F:/tmp' : sys_get_temp_dir()))
            . '/route-forge-thinkphp-cache-' . bin2hex(random_bytes(4));

        // think 8 缓存驱动构造签名为 (App, options)：这里仅借用其文件读写，
        // 用无根路径 App 占位（不 initialize）
        return new ThinkCacheAdapter(new FileDriver(new App(''), ['path' => $path]));
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        self::assertNull($this->makeAdapter()->get('route-forge:missing'));
    }

    public function testPutAndGetRoundTrip(): void
    {
        $adapter = $this->makeAdapter();

        $adapter->put('route-forge:round', ['level' => 'admin', 'routes' => []], 60);

        self::assertSame(['level' => 'admin', 'routes' => []], $adapter->get('route-forge:round'));
    }

    public function testPutWithNullSecondsStoresForever(): void
    {
        $adapter = $this->makeAdapter();

        // null = 永久缓存（映射 think set($key, $value, 0)，0=永久）
        $adapter->put('route-forge:forever', 'value', null);

        self::assertSame('value', $adapter->get('route-forge:forever'));
    }

    public function testForgetRemovesKey(): void
    {
        $adapter = $this->makeAdapter();

        $adapter->put('route-forge:gone', 'value', 60);
        $adapter->forget('route-forge:gone');

        self::assertNull($adapter->get('route-forge:gone'));
    }
}
