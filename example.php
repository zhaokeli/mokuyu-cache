<?php
include __dir__ . '/vendor/autoload.php';

$cache = new \mokuyu\Cache([
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
$cache->set('phone_code', 12345, 160);

$cache->clear();
echo $cache->get('phone_code');
$cache->set('test.value1', 'testtest', false);
$cache->set('test.value2', 'testtest', false);
$cache->set('new.value1', 'testtest', false);
$cache->set('new.value2', 'testtest', false);

$cache->delete('test.');

$cache->clear();
