<?php

namespace mokuyu\cache\tests;

use PHPUnit\Framework\TestCase;
use PDO;

class CacheTest extends TestCase
{
    protected $cache = null;

    public function setUp(): void
    {
        $this->cache = MyCache::getInstance();
    }


    /**
     * @param
     */
    public function testSet()
    {
        //添加单个
        $this->cache->set('phone_code', 12345, 160);
        $this->assertEquals('12345', $this->cache->get('phone_code'));

    }

    public function testTag()
    {
        $this->cache->tag('newtag')->set('test.value1.tj.log', 1, false);
        $this->cache->tag('newtag')->set('test.value1.tj.log2', 2, false);
        $this->cache->tag('newtag', 'test1,test2,test3');
        $this->assertEquals(1, $this->cache->get('test.value1.tj.log'));
        $this->cache->clearTag('newtag');
        $this->assertNull($this->cache->get('test.value1.tj.log'));

        //重置标签后清理标签,数据不应该被清理掉
        $this->cache->tag('newtag')->set('test.value1.tj.log', 1, false);
        $this->cache->tag('newtag', 'test1,test2,test3,test4', true);
        $this->cache->clearTag('newtag');
        $this->assertNotNull($this->cache->get('test.value1.tj.log'));


        $this->cache->set('times', 0);
        $this->cache->inc('times', 1);
        $this->assertEquals(1, $this->cache->get('times'));
        $this->cache->inc('times', 1);
        $this->assertEquals(2, $this->cache->get('times'));
        $this->cache->dec('times', 1);
        $this->assertEquals(1, $this->cache->get('times'));

    }

    public function testExpire()
    {
        $this->cache->set('time', 1, 1);
        $this->assertTrue($this->cache->has('time'));
        $this->assertEquals(1, $this->cache->get('time'));
        sleep(1);
        $this->assertNull($this->cache->get('time'));
    }

    public function testMultiple()
    {
        $arr = ['test1' => 1, 'test2' => 2, 'test3' => 3];
        $this->assertTrue($this->cache->setMultiple($arr, 120));
        $this->assertEquals(1, $this->cache->get('test1'));
        $this->assertEquals(2, $this->cache->get('test2'));
        $this->assertEquals(3, $this->cache->get('test3'));
        $this->assertEquals($arr, $this->cache->getMultiple(['test1', 'test2', 'test3']));
        $this->assertTrue($this->cache->deleteMultiple(['test1', 'test2', 'test3']));
        $this->assertNull($this->cache->get('test1'));
        $this->assertNull($this->cache->get('test2'));
        $this->assertNull($this->cache->get('test3'));
    }

    public function testFlush()
    {
        $this->cache->set('suibian.log.tj', 1, false);
        $this->cache->delete('suibian.log.tj');
        $this->cache->set('suibian.log.tj', 2);
        $this->cache->delete('suibian.log.tj');
        $this->cache->set('suibian.log.tj', 3);
        $this->cache->delete('suibian.log.tj');
        $this->cache->set('suibian.log.tj', 4);
        $this->assertTrue($this->cache->clear());
        $this->assertTrue($this->cache->flushAll());
    }

}