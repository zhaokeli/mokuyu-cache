<?php

namespace mokuyu\cache\tests;


use mokuyu\Cache;
use mokuyu\CacheException;

class MyCache
{
    protected static $instance    = null;
    protected static $pdoInstance = null;
    protected static $initialized = false;

    public static function getInstance()
    {
        if (self::$instance === null) {
            try {
                self::$instance = new Cache([
                    //目前支持file和memcache redis
                    'type'      => 'redis',
                    'memcache'  => [
                        'host' => '127.0.0.1',
                        'port' => 11211,
                    ],
                    'redis'     => [
                        'host'     => '127.0.0.1',
                        'port'     => 6379,
                        'index'    => 0,
                        'password' => '',
                    ],
                    // 全局缓存有效期（0为永久有效）
                    'expire'    => 0,
                    'temp_time' => 60, //单位秒
                    // 缓存前缀
                    'prefix'    => 'mokuyu',
                    // 文件缓存目录
                    'path'      => __dir__ . '/datacache',
                ]);
            } catch (CacheException $e) {
                return null;
            }
        }
        return self::$instance;
    }
}