<?php
declare (strict_types = 1);
namespace mokuyu;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

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
     * 缓存驱动实例
     * @var null
     */
    protected $instance = null;

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
                        $this->instance->delete($ck);
                    }
                }
                if (count($tagcopy) == 0) {
                    unset($this->allTags[$tag]);
                } else {
                    $this->allTags[$tag] = $tagcopy;
                }
                $this->set($this->allTagKey, $this->allTags, false);

                return true;
            } else {
                return false;
            }

        } else {
            list($tagkey, $val) = explode($this->fenge, $key);
            unset($this->allTags[$tagkey][$val]);
            //删除掉空的标签
            if (isset($this->allTags[$tagkey]) && count($this->allTags[$tagkey]) == 0) {
                unset($this->allTags[$tagkey]);
            }
            //保存所有标签到缓存
            $this->set($this->allTagKey, $this->allTags, false);
            //有值的话就删除
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
        } elseif ($lifeTime === false) {
            $lifeTime = $this->cacheConfig['expire'];
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
        } else {
            list($tag, $key) = explode($this->fenge, $key);
        }
        if ($this->allTags === null) {
            $this->allTags = $this->instance->fetch($alltagkey);
            $this->allTags = $this->allTags ?: [];
        }

        //判断key是否为真,为真时才往标签里添加key和值，当是article.这种格式时key为空
        //然后判断是否设置的有些标签，并且标签里是否保存有
        if ($key && !(isset($this->allTags[$tag]) && isset($this->allTags[$tag][$key]))) {
            $this->allTags[$tag][$key] = $key;
            $this->instance->save($alltagkey, $this->allTags, false);
        }
        // \ank\App::getInstance()->get('debug')->markEnd('Cache:parse');

        return $tag . $this->fenge . $key;
    }
}
