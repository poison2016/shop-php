<?php

namespace app\common\traits;

use Redis;

/**
 * 缓存处理Trait
 * @package app\Traits
 */
trait CacheTrait
{
    /**
     * @var
     */
    private $redisPool;

    /**
     * 获取redis对象
     * @param array $conf
     * @return Redis
     * @author qap <qiuapeng921@163.com>
     * @date 19-5-9 上午11:38
     */
    private function getRedis($conf = [])
    {
        $redis = new Redis();
        if (!$conf) {
            $conf = config('redis_config');
        }
        if ($redis->connect($conf['host'], $conf['port'])) {
            if (!empty($conf['password'])) {
                $redis->auth($conf['password']);
            }
            return $redis;
        } else {
            return null;
        }
    }


    /**
     * 设置缓存
     * @param $key
     * @param $value
     * @param int|null $timeout
     * @param bool $overwrite
     * @return bool
     */
    public function setCache($key, $value, int $timeout = null, bool $overwrite = true)
    {
        if (empty($value)) {
            return false;
        }
        $redis = $this->getRedis();
        if ($timeout) {
            $result = $redis->setex($key, $timeout, $value);
        } elseif (!$overwrite) {
            $result = $redis->setnx($key, $value);
        } else {
            $result = $redis->set($key, $value);
        }
        return $result;
    }

    /**
     * 读取缓存
     * @param $key
     * @return mixed
     */
    public function getCache($key)
    {
        $redis = $this->getRedis();
        $result = $redis->get($key);
        return $result;
    }

    /**
     * 获取key剩余时间
     * @param $key
     *
     * @return int
     *
     * @author qap <qiuapeng921@163.com>
     * @date 19-5-30 上午9:22
     */
    public function getTtl($key)
    {
        $redis = $this->getRedis();
        $result = $redis->ttl($key);
        return $result;
    }

    /**
     * 判断缓存是否存在
     * @param $key
     * @return mixed
     */
    public function existsCache($key)
    {
        $redis = $this->getRedis();
        $result = $redis->exists($key);
        return $result;
    }

    /**
     * 缓存值增1
     * @param $key
     */
    public function incrCache($key)
    {
        $redis = $this->getRedis();
        $redis->incr($key);
    }

    /**
     * 缓存值减1
     * @param $key
     */
    public function decrCache($key)
    {
        $redis = $this->getRedis();
        $redis->decr($key);
    }

    /**
     * 删除缓存
     * @param array ...$key
     * @return mixed
     */
    public function delCache(...$key)
    {
        $redis = $this->getRedis();
        $result = $redis->del(...$key);
        return $result;
    }

    /**
     * 左侧入队
     * @param $key
     * @param array ...$value
     * @return mixed
     */
    public function lPushCache($key, ...$value)
    {
        $redis = $this->getRedis();
        $result = $redis->lpush($key, ...$value);
        return $result;
    }

    /**
     * 将值插入列表头部，key不存在时入队无效
     * @param $key
     * @param $value
     * @return mixed
     */
    public function lPushXCache($key, $value)
    {
        $redis = $this->getRedis();
        $result = $redis->lpushx($key, $value);
        return $result;
    }

    /**
     * 左侧出队
     * @param $key
     * @return mixed
     */
    public function lPopCache($key)
    {
        $redis = $this->getRedis();
        $result = $redis->lpop($key);
        return $result;
    }

    /**
     * 右侧入队
     * @param $key
     * @param array ...$value
     * @return mixed
     */
    public function rPushCache($key, ...$value)
    {
        $redis = $this->getRedis();
        $result = $redis->rpush($key, ...$value);
        return $result;
    }

    /**
     * 将值插入列表尾部，key不存在时入队操作无效
     * @param $key
     * @param $value
     * @return mixed
     */
    public function rPushXCache($key, $value)
    {
        $redis = $this->getRedis();
        $result = $redis->rpushx($key, $value);
        return $result;
    }

    /**
     * 右侧出队
     * @param $key
     * @return mixed
     */
    public function rPopCache($key)
    {
        $redis = $this->getRedis();
        $result = $redis->rpop($key);
        return $result;
    }

    /**
     * 获取列表长度
     * @param $key
     * @return mixed
     */
    public function lLenCache($key)
    {
        $redis = $this->getRedis();
        $result = $redis->llen($key);
        return $result;
    }

    /**
     * 根据索引获取列表中元素
     * @param $key
     * @param $index
     * @return mixed
     */
    public function lIndexCache($key, $index)
    {
        $redis = $this->getRedis();
        $result = $redis->lindex($key, $index);
        return $result;
    }

    /**
     * 获取列表指定范围内的元素
     * @param $key
     * @param $start
     * @param $end
     * @return mixed
     */
    public function lRange($key, $start, $end)
    {
        $redis = $this->getRedis();
        $result = $redis->lrange($key, $start, $end);
        return $result;
    }

    /**
     * 根据count的值移除列表中与给定值相等的元素
     * @param $key
     * @param $count
     * @param $value
     * @return mixed
     */
    public function lRemCache($key, $count, $value)
    {
        $redis = $this->getRedis();
        $result = $redis->lrem($key, $count, $value);
        return $result;
    }

    /**
     * 读取缓存中的满足条件的Key值
     * @param string $pattern
     * @return mixed
     */
    public function keysCache($pattern = '*')
    {
        $redis = $this->getRedis();
        $result = $redis->keys($pattern);
        return $result;
    }


    /**
     * 设置哈希缓存
     * @param $key
     * @param $value
     * @return bool
     */
    public function hMSetCache($key, $value)
    {
        $redis = $this->getRedis();
        $result = $redis->hMSet($key, $value);
        return $result;
    }


    /**
     * 获取哈希缓存
     * @param $key
     * @param $value
     * @return array
     */
    public function hMGetCache($key, $value)
    {
        $redis = $this->getRedis();
        $result = $redis->hMGet($key, $value);
        return $result;
    }


    /**
     * 删除哈希缓存
     * @param $key
     * @param $field1
     * @param mixed ...$otherFields
     * @return bool|int
     */
    public function hDelCache($key, $field1, ...$otherFields)
    {
        $redis = $this->getRedis();
        $result = $redis->hDel($key, $field1, ...$otherFields);
        return $result;
    }


    /**
     * 获取哈希缓存中所有key
     * @return array
     */
    public function hKeysCache($key)
    {
        $redis = $this->getRedis();
        $result = $redis->hKeys($key);
        return $result;
    }


    /**
     * 验证某个哈希key是否存在
     * @param $key
     * @param $hashKey
     * @return bool
     */
    public function hExistsCache($key, $hashKey)
    {
        $redis = $this->getRedis();
        $result = $redis->hExists($key, $hashKey);
        return $result;
    }


    /**
     * 哈希缓存值自增
     * @param $key
     * @param $hashKey
     * @param $value
     * @return int
     */
    public function hIncrbyCache($key, $hashKey, $value)
    {
        $redis = $this->getRedis();
        $result = $redis->hIncrBy($key, $hashKey, $value);
        return $result;
    }


    /**
     * 获取所有hash key的值
     * @param $key
     * @return array
     * @author yangliang
     * @date 2021/2/2 9:14
     */
    public function hGetallCache($key)
    {
        $redis = $this->getRedis();
        $result = $redis->hGetAll($key);
        return $result;
    }


    /**
     * 添加集合
     * @param $key
     * @param mixed ...$value
     * @return bool|int
     * @author: yangliang
     * @date: 2021/9/23 11:39
     */
    public function sAdd($key, ...$value){
        $redis = $this->getRedis();
        return $redis->sAdd($key, ...$value);
    }


    /**
     * 获取集合所有成员
     * @param $key
     * @return array
     * @author: yangliang
     * @date: 2021/9/23 11:41
     */
    public function sMembers($key){
        $redis = $this->getRedis();
        return $redis->sMembers($key);
    }
}
