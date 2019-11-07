<?php
declare (strict_types = 1);
namespace mokuyu;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

class Cache implements CacheInterface
{
    const NOT_SET_VALUE = '___noset___';

    protected $allTagKey = 'AllTags';

    protected $cacheConfig = [];

    protected $cacheType = '';

    //默认所有缓存数据加到这个标签里
    protected $defaultTag = 'DefaultTag';

    protected $fenge = ':';

    protected $instance = null;

    protected $prefix = '';

    /**
     * 为啦防止冲突每个模块可以定义一个缓存前缀，这个前缀会加到标签前面
     * @authname [权限名字]     0
     * @DateTime 2019-01-08
     * @Author   mokuyu
     *
     * @return [type]
     */
    public function __construct($config)
    {
        $this->cacheConfig = $config;
        $this->cacheType   = $this->cacheConfig['type'];
        $this->cacheType   = strtolower($this->cacheType ?: 'file');
        $this->prefix      = $this->cacheConfig['prefix'];
        $cache_path        = $this->cacheConfig['path'];

        if ($this->cacheType == 'memcache' && class_exists('Memcache')) {
            $memcache = new \Memcache();
            $memcache->connect($this->cacheConfig['memcache']['host'], $this->cacheConfig['memcache']['port']);
            $this->instance = new \Doctrine\Common\Cache\MemcacheCache();
            $this->instance->setMemcache($memcache);
        } elseif ($this->cacheType == 'memcached') {
            $memcached = new \Memcached();
            $memcached->addServer($this->cacheConfig['memcached']['host'], $this->cacheConfig['memcached']['port']);
            $this->instance = new \Doctrine\Common\Cache\MemcacheCache();
            $this->instance->setMemcached($memcached);
        } elseif ($this->cacheType == 'redis' && class_exists('Redis')) {
            $redis = new \Redis();

            $redis->connect($this->cacheConfig['redis']['host'], $this->cacheConfig['redis']['port']);
            $pwd = $this->cacheConfig['redis']['password'];
            if ($pwd != '') {
                $redis->auth($pwd);
            }
            $redis->select($this->cacheConfig['redis']['index']);
            // $redis->setOption(\Redis::OPT_PREFIX, $this->prefix . ':');
            $this->instance = new \Doctrine\Common\Cache\RedisCache();
            $this->instance->setRedis($redis);

        } else {
            if (!$cache_path) {
                throw new Exception('cache path is empty', 1);

            }
            $this->instance = new \Doctrine\Common\Cache\FilesystemCache($cache_path);
        }
        $this->instance->setNamespace($this->prefix . ':');

        return $this->instance;
    }

    /**
     * 自动操作缓存
     * @DateTime 2019-11-07
     * @Author   mokuyu
     *
     * @param  [type]   $name     缓存键名
     * @param  string   $value    默认为一个自定义常量,因为传null的话识别为删除缓存
     * @param  boolean  $lifeTime 默认为(false)永久保存,true/null为系统默认存活时长，int为时长
     * @return [type]
     */
    public function action($name, $value = self::NOT_SET_VALUE, $lifeTime = false)
    {
        if (null === $name) {
            return $this->clear();
        } elseif ($name && null === $value) {
            return $this->delete($name);
        } elseif ($name && self::NOT_SET_VALUE === $value) {
            return $this->has($name) ? $this->get($name) : false;
        } else {
            return $this->set($name, $value, $lifeTime);
        }
    }

    public function clear()
    {
        return $this->instance->deleteAll() ? true : false;
    }

    public function dec($key = '', $value = 1)
    {
        if (!$key) {
            return false;
        }
        $this->has($key) ? $this->set($key, $this->get($key) - $value) : $this->set($key, -$value);
    }

    public function delete($key)
    {
        $key  = $this->parseKey($key);
        $tags = $this->get($this->allTagKey, []);
        //如果最后一个为分隔符,则清空这个标签
        if (substr($key, -1) == $this->fenge) {
            $tag = substr($key, 0, -1);
            if (in_array($tag, array_keys($tags))) {
                $tagarr  = $tags[$tag];
                $tagcopy = $tagarr;
                foreach ($tagarr as $key => $value) {
                    unset($tagcopy[$value]);
                    $ck = $tag . $this->fenge . $value;
                    if ($this->has($ck)) {
                        $this->instance->delete($ck);
                    }
                }
                if (count($tagcopy) == 0) {
                    unset($tags[$tag]);
                } else {
                    $tags[$tag] = $tagcopy;
                }
                $this->set($this->allTagKey, $tags, false);
            }

            return true;
        } else {
            list($tagkey, $val) = explode($this->fenge, $key);
            unset($tags[$tagkey][$val]);
            if (isset($tags[$tagkey]) && count($tags[$tagkey]) == 0) {
                unset($tags[$tagkey]);
            }
            $this->set($this->allTagKey, $tags, false);
            if ($this->has($key)) {
                return $this->instance->delete($key);
            } else {
                return false;
            }
        }
    }

    public function deleteMultiple($keys)
    {
        if (!is_array($values)) {
            throw new InvalidArgumentException('keys array format is valid!', 1);
        }
        foreach ($keys as $key => $value) {
            if (!$this->delete($this->parseKey($value))) {
                return false;
            }
        }

        return true;
    }

    public function get($key, $default = null)
    {
        $key = $this->parseKey($key);

        return $this->has($key) ? $this->instance->fetch($key) : $default;
    }

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

    public function has($key)
    {
        return $this->instance->contains($this->parseKey($key));
    }

    public function inc($key = '', $value = 1)
    {
        if (!$key) {
            return false;
        }

        return $this->has($key) ? $this->set($key, $this->get($key) + $value) : $this->set($key, $value);
    }

    public function set($key, $data, $lifeTime = null)
    {
        if ($lifeTime === true || null === $lifeTime) {
            $lifeTime = $this->cacheConfig['temp_time'];
        }

        return $this->instance->save($this->parseKey($key), $data, $lifeTime) ? true : false;
    }

    public function setMultiple($values, $ttl = null)
    {
        if (!is_array($values)) {
            throw new InvalidArgumentException('values array format is valid!', 1);
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
     *
     * @param  [type]   $key [description]
     * @return [type]
     */
    private function parseKey(string $key): string
    {
        //已经解析过的直接返回
        if (strpos($this->fenge, $key) !== false) {
            return $key;
        }
        $key = str_replace('.', $this->fenge, $key);
        //加载缓存的前缀
        $key = strtolower($key);
        if (strpos($key, $this->fenge) === false) {
            $tag = $this->defaultTag;
        } else {
            list($tag, $key) = explode($this->fenge, $key);
        }
        $tags = (array) $this->instance->fetch($this->allTagKey);
        if (!($tags && isset($tags[$tag]) && isset(array_flip($tags[$tag])[$key]))) {
            $tags[$tag][$key] = $key;
            $this->instance->save($this->allTagKey, $tags, false);
        }

        return $tag . $this->fenge . $key;
    }
}
