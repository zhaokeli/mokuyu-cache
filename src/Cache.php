<?php
declare (strict_types = 1);

namespace mokuyu;

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\MemcacheCache;
use Doctrine\Common\Cache\RedisCache;
use Psr\SimpleCache\CacheInterface;
use Memcache;
use Memcached;
use Redis;

class Cache implements CacheInterface
{
    /**
     * 保存标签和标签中包含的key的键
     * @var string
     */
    protected $allTagKey = 'AllTags';

    /**
     * 当前缓存的所有标签
     * @var array
     */
    protected $allTags = null;

    /**
     * 缓存配置
     * @var array
     */
    protected $cacheConfig = [];

    /**
     * 当前使用的缓存类型
     * @var string
     */
    protected $cacheType = '';

    /**
     * 没有加标签的key默认加到这个标签里
     * @var string
     */
    protected $defaultTag = 'DefaultTag';

    /**
     * 标签key的分隔符
     * @var string
     */
    protected $fenge = ':';

    /**
     * 缓存驱动句柄
     * @var null
     */
    protected $handler = null;

    /**
     * 缓存前缀,一般一个项目对应一个前缀
     * @var string
     */
    protected $prefix = '';

    /**
     * 为啦防止冲突每个模块可以定义一个缓存前缀，这个前缀会加到标签前面
     * @authname [权限名字]     0
     * @DateTime 2019-01-08
     * @Author   mokuyu
     * @param $config
     * @throws CacheException
     */
    public function __construct($config)
    {
        $this->cacheConfig = $config;
        $this->cacheType   = $this->cacheConfig['type'];
        $this->cacheType   = strtolower($this->cacheType ?: 'file');
        $this->prefix      = trim($this->cacheConfig['prefix'], ':');
        $cache_path        = $this->cacheConfig['path'];

        if ($this->cacheType == 'memcache' && extension_loaded('memcache')) {
            $memcache = new Memcache();
            $memcache->connect($this->cacheConfig['memcache']['host'], $this->cacheConfig['memcache']['port']);
            $this->handler = new MemcacheCache();
            $this->handler->setMemcache($memcache);
        }
        elseif ($this->cacheType == 'memcached') {
            $memcached = new Memcached();
            $memcached->addServer($this->cacheConfig['memcached']['host'], $this->cacheConfig['memcached']['port']);
            $this->handler = new MemcacheCache();
            $this->handler->setMemcached($memcached);
        }
        elseif ($this->cacheType == 'redis' && extension_loaded('redis')) {
            $redis = new Redis();

            $redis->connect($this->cacheConfig['redis']['host'], $this->cacheConfig['redis']['port']);
            $pwd = $this->cacheConfig['redis']['password'];
            if ($pwd != '') {
                $redis->auth($pwd);
            }
            $redis->select($this->cacheConfig['redis']['index']);
            // $redis->setOption(\Redis::OPT_PREFIX, $this->prefix . ':');
            $this->handler = new RedisCache();
            $this->handler->setRedis($redis);

        }
        else {
            if (!$cache_path) {
                throw new CacheException('cache path is empty', 1);

            }
            $this->handler = new FilesystemCache($cache_path);
        }
        $this->prefix && $this->handler->setNamespace($this->prefix . ':');

        return $this->handler;
    }

    /**
     * @return bool
     */
    public function clear()
    {
        return $this->handler->deleteAll();
    }

    /**
     * @param string $key
     * @param int    $value
     * @return bool
     */
    public function dec($key = '', $value = 1)
    {
        if (!$key) {
            return false;
        }
        return $this->has($key) ? $this->set($key, $this->get($key) - $value) : $this->set($key, -$value);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete($key)
    {
        $key = $this->parseKey($key);
        // $this->allTags = $this->get($this->allTagKey, []);
        //如果最后一个为分隔符,则清空这个标签
        if (substr($key, -1) == $this->fenge) {

            $tag = substr($key, 0, -1);
            // if (in_array($tag, array_keys($this->allTags))) {
            if (isset($this->allTags[$tag])) {
                $tagarr  = $this->allTags[$tag];
                $tagcopy = $tagarr;
                foreach ($tagarr as $key => $value) {
                    unset($tagcopy[$value]);
                    $ck = $tag . $this->fenge . $value;
                    if ($this->has($ck)) {
                        $this->handler->delete($ck);
                    }
                }
                if (count($tagcopy) == 0) {
                    unset($this->allTags[$tag]);
                }
                else {
                    $this->allTags[$tag] = $tagcopy;
                }
                $this->set($this->allTagKey, $this->allTags, false);

                return true;
            }
            else {
                return false;
            }

        }
        else {
            [$tagkey, $val] = explode($this->fenge, $key);
            unset($this->allTags[$tagkey][$val]);
            //删除掉空的标签
            if (isset($this->allTags[$tagkey]) && count($this->allTags[$tagkey]) == 0) {
                unset($this->allTags[$tagkey]);
            }
            //保存所有标签到缓存
            $this->set($this->allTagKey, $this->allTags, false);
            //有值的话就删除
            if ($this->has($key)) {
                return $this->handler->delete($key);
            }
            else {
                return false;
            }
        }
    }

    /**
     * @param iterable $keys
     * @return bool
     */
    public function deleteMultiple($keys)
    {
        if (!is_array($keys)) {
            return false;
        }
        foreach ($keys as $key => $value) {
            if (!$this->delete($this->parseKey($value))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $key
     * @param null   $default
     * @return false|mixed|null
     */
    public function get($key, $default = null)
    {
        $key = $this->parseKey($key);

        return $this->has($key) ? $this->handler->fetch($key) : $default;
    }

    /**
     * @param iterable $keys
     * @param null     $default
     * @return array|iterable
     */
    public function getMultiple($keys, $default = null)
    {
        if (is_string($keys)) {
            $keys = explode(',', $keys);
        }
        $data = [];
        foreach ($keys as $key => $value) {
            $da           = $this->get($this->parseKey($value));
            $data[$value] = $da ?: $default;
        }

        return $data;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has($key)
    {
        return $this->handler->contains($this->parseKey($key));
    }

    /**
     * @param string $key
     * @param int    $value
     * @return bool
     */
    public function inc($key = '', $value = 1)
    {
        if (!$key) {
            return false;
        }
        return $this->has($key) ? $this->set($key, $this->get($key) + $value) : $this->set($key, $value);
    }

    /**
     * @param string $key
     * @param mixed  $data
     * @param bool   $lifeTime
     * @return bool
     */
    public function set($key, $data, $lifeTime = false)
    {
        if ($lifeTime === false) {
            $lifeTime = $this->cacheConfig['expire'];
        }

        return $this->handler->save($this->parseKey($key), $data, $lifeTime);
    }

    /**
     * @param iterable $values
     * @param null     $ttl
     * @return bool
     */
    public function setMultiple($values, $ttl = null)
    {
        if (!is_array($values)) {
            return false;
        }
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * 解析成完成的带标签和键的名字
     * @authname [权限名字]     0
     * @DateTime 2018-12-23
     * @Author   mokuyu
     * @param string $key
     * @return string [type]
     */
    private function parseKey(string $key): string
    {
        // \ank\App::getInstance()->get('debug')->markStart('Cache:parse');
        //all标签key名字
        $alltagkey = $this->defaultTag . $this->fenge . $this->allTagKey;
        //已经解析过的直接返回
        if (strpos($this->fenge, $key) !== false) {
            return $key;
        }
        $key = str_replace('.', $this->fenge, $key);
        //加载缓存的前缀
        // $key = strtolower($key);
        if (strpos($key, $this->fenge) === false) {
            $tag = $this->defaultTag;
        }
        else {
            [$tag, $key] = explode($this->fenge, $key);
        }
        if ($this->allTags === null) {
            $this->allTags = $this->handler->fetch($alltagkey);
            $this->allTags = $this->allTags ?: [];
        }

        //判断key是否为真,为真时才往标签里添加key和值，当是article.这种格式时key为空
        //然后判断是否设置的有些标签，并且标签里是否保存有
        if ($key && !(isset($this->allTags[$tag]) && isset($this->allTags[$tag][$key]))) {
            $this->allTags[$tag][$key] = $key;
            $this->handler->save($alltagkey, $this->allTags, $this->cacheConfig['expire']);
        }
        // \ank\App::getInstance()->get('debug')->markEnd('Cache:parse');

        return $tag . $this->fenge . $key;
    }

    /**
     * 返回驱动句柄
     * @return FilesystemCache|MemcacheCache|RedisCache|null
     */
    public function handler()
    {
        return $this->handler;
    }
}
