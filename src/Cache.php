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
     * 缓存标签
     * @var array
     */
    protected $tag;

    /**
     * 缓存写的次数
     * @var int
     */
    protected $writeTimes = 0;

    /**
     * 缓存读的次数
     * @var int
     */
    protected $readTimes = 0;

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
     * 清理所有缓存,只是标记数据弃用,速度快,其实还存在于内存中
     * @return bool
     */
    public function clear()
    {
        $this->writeTimes++;
        return $this->handler->deleteAll();
    }

    /**
     * 减缓存
     * @param string $key
     * @param int    $value
     * @return bool
     */
    public function dec($key = '', $value = 1)
    {
        if (!$key) {
            return false;
        }
        $this->writeTimes++;
        $key = $this->parseKey($key);
        return $this->handler->contains($key) ?
            $this->handler->save($key, $this->handler->fetch($key) - $value)
            : $this->handler->save($key, -$value);
    }

    /**
     * 删除指定缓存
     * @param string $key
     * @return bool
     */
    public function delete($key)
    {
        $this->writeTimes++;
        return $this->handler->delete($this->parseKey($key));
    }

    /**
     * 删除多个缓存
     * @param iterable $keys
     * @return bool
     */
    public function deleteMultiple($keys)
    {
        if (is_string($keys)) {
            $keys = explode(',', $keys);
        }
        if (!is_array($keys)) {
            return false;
        }
        $this->writeTimes++;
        $this->handler->deleteMultiple(array_map([$this, 'parseKey'], $keys));
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

        $this->readTimes++;
        return $this->handler->contains($key) ? $this->handler->fetch($key) : $default;
    }

    /**
     * 一次返回多个值
     * @param iterable $keys 多个key用,分隔
     * @param null     $default
     * @return array|mixed[]
     */
    public function getMultiple($keys, $default = null)
    {
        if (is_string($keys)) {
            $keys = explode(',', $keys);
        }
        $this->readTimes++;
        return $this->handler->fetchMultiple(array_map([$this, 'parseKey'], $keys)) ?: $default;
    }

    /**
     * 是否有值
     * @param string $key
     * @return bool
     */
    public function has($key): bool
    {
        $this->readTimes++;
        return $this->handler->contains($this->parseKey($key));
    }

    /**
     * 值加1
     * @param string $key
     * @param int    $value
     * @return bool
     */
    public function inc($key = '', $value = 1): bool
    {
        if (!$key) {
            return false;
        }
        $this->writeTimes++;
        $key = $this->parseKey($key);
        return $this->handler->contains($key) ?
            $this->handler->save($key, $this->handler->fetch($key) + $value)
            : $this->handler->save($key, $value);
    }

    /**
     * 设置缓存
     * @param string $key
     * @param mixed  $data
     * @param bool   $lifeTime
     * @return bool
     */
    public function set($key, $data, $lifeTime = false): bool
    {
        if ($lifeTime === false) {
            $lifeTime = $this->cacheConfig['expire'];
        }
        if ($this->tag && !$this->handler->contains($key)) {
            $this->setTagItem($key);
        }
        $this->writeTimes++;
        return $this->handler->save($this->parseKey($key), $data, $lifeTime);
    }

    /**
     * 设置多个值,不支持添加标签
     * @param      $keys
     * @param null $ttl
     * @return bool
     */
    public function setMultiple($keys, $ttl = null)
    {
        if (!is_array($keys)) {
            return false;
        }
        $this->writeTimes++;
        $new = [];
        foreach ($keys as $key => $value) {
            $new[$this->parseKey($key)] = $value;
        }
        //        if ($this->tag && !$this->handler->contains($key)) {
        //            $this->setTagItem($key);
        //        }
        return $this->handler->saveMultiple($new, $ttl);
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
        return str_replace('.', $this->fenge, $key);
    }

    /**
     * 返回驱动句柄
     * @return FilesystemCache|MemcacheCache|RedisCache|null
     */
    public function handler()
    {
        return $this->handler;
    }

    /**
     * 设置或重置缓存标签
     * @access public
     * @param string       $name    标签名
     * @param string|array $keys    新的或追加的缓存key
     * @param bool         $overlay 是否覆盖
     * @return $this
     */
    public function tag($name, $keys = null, $overlay = false): Cache
    {
        $name = $this->parseKey($name);
        if (is_null($keys)) {
            $this->tag = $name;
        }
        else {
            $key = $this->getTagkey($name);
            if (is_string($keys)) {
                $keys = explode(',', $keys);
            }

            $keys = array_map([$this, 'parseKey'], $keys);
            if ($overlay) {
                $value = $keys;
            }
            else {
                $value = array_unique(array_merge($this->getTagItem($name), $keys));
            }

            $this->set($key, implode(',', $value), 0);
        }

        return $this;
    }

    /**
     * 更新标签
     * @access protected
     * @param string $name 缓存标识
     * @return void
     */
    protected function setTagItem($name)
    {
        if ($this->tag) {
            $key       = $this->getTagkey($this->tag);
            $this->tag = null;
            if ($this->handler->contains($key)) {
                $value   = explode(',', $this->get($key));
                $value[] = $name;

                //最多保存1000个
                if (count($value) > 1000) {
                    array_shift($value);
                }

                $value = implode(',', array_unique($value));
            }
            else {
                $value = $name;
            }
            $this->writeTimes++;
            $this->handler->save($key, $value, 0);
        }
    }

    /**
     * 获取标签包含的缓存标识
     * @access protected
     * @param string $tag 缓存标签
     * @return array
     */
    protected function getTagItem($tag)
    {
        $this->readTimes++;
        $value = $this->handler->fetch($this->getTagkey($this->parseKey($tag)));

        if ($value) {
            return array_filter(explode(',', $value));
        }
        else {
            return [];
        }
    }

    /**
     * 返回标签实际key
     * @param $tag
     * @return string
     */
    protected function getTagKey($tag)
    {
        return 'tags:' . md5($tag);
    }

    /**
     * 清除标签缓存
     * @access public
     * @param string $tag 标签名
     * @return boolean
     */
    public function clearTag($tag = null)
    {
        if ($tag) {
            $tag = $this->parseKey($tag);
            // 指定标签清除
            $keys = $this->getTagItem($tag);
            $this->handler->deleteMultiple(array_map([$this, 'parseKey'], $keys));
            $tagName = $this->getTagKey($tag);
            $this->writeTimes++;
            $this->handler->delete($tagName);
            return true;
        }
    }

    /**
     * 清理缓存,数据从内存中真实清理,耗时
     * @return bool
     */
    public function flushAll()
    {
        return $this->handler->flushAll();
    }

    public function setNamespace($namespace)
    {
        $this->handler->setNamespace($namespace);
    }

    /**
     * Retrieves the namespace that prefixes all cache ids.
     * @return string
     */
    public function getNamespace()
    {
        return $this->handler->getNamespace();
    }

    public function getStats()
    {
        return $this->handler->getStats();
    }
}
