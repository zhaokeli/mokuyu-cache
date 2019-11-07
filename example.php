<?php
include __dir__ . '/vendor/autoload.php';

$cache = new \mokuyu\Cache([
    //目前支持file和memcache redis
    'type'      => 'file',
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
    'prefix'    => 'ank_',
    // 文件缓存目录
    'path'      => __dir__ . '/datacache',
]);
