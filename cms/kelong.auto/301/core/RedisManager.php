<?php
namespace Redirect301\Core;

/**
 * Redis 管理器
 * 统一管理 Redis 连接和操作
 */
class RedisManager {
    private static $instance = null;
    private $redis = null;
    private $config = [];
    private $prefix = '';
    
    private function __construct($config = []) {
        $this->config = array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 1,
            'timeout' => 3,
            'site_id' => '',
        ], $config);
        
        // 生成前缀（兼容旧系统）
        // 优先使用配置中的 site_id，否则使用 SITE_ID 常量（旧系统），最后才自动生成
        if (!empty($this->config['site_id'])) {
            $siteId = $this->config['site_id'];
        } elseif (defined('SITE_ID')) {
            $siteId = SITE_ID;
        } else {
            $siteId = substr(md5(dirname(__DIR__)), 0, 8);
        }
        
        // 兼容旧系统的前缀格式 'bigsite:xxxxx:'
        $this->prefix = 'bigsite:' . $siteId . ':';
    }
    
    /**
     * 获取单例
     */
    public static function getInstance($config = []) {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }
    
    /**
     * 获取 Redis 连接
     */
    public function getConnection() {
        if ($this->redis === null) {
            if (!class_exists('Redis')) {
                error_log('Redis extension not installed');
                $this->redis = false;
                return null;
            }
            
            try {
                $redis = new \Redis();
                $redis->connect(
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['timeout']
                );
                
                if (!empty($this->config['password'])) {
                    $redis->auth($this->config['password']);
                }
                
                $redis->select($this->config['database']);
                
                $this->redis = $redis;
            } catch (\Exception $e) {
                error_log('Redis connection failed: ' . $e->getMessage());
                $this->redis = false;
            }
        }
        
        return $this->redis ?: null;
    }
    
    /**
     * 检查 Redis 是否可用
     */
    public function isAvailable() {
        return $this->getConnection() !== null;
    }
    
    /**
     * 获取键名前缀
     */
    public function getPrefix() {
        return $this->prefix;
    }
    
    /**
     * 生成完整键名
     */
    public function key($key) {
        return $this->prefix . $key;
    }
    
    /**
     * 获取值
     */
    public function get($key) {
        $redis = $this->getConnection();
        if (!$redis) {
            return null;
        }
        
        try {
            return $redis->get($this->key($key));
        } catch (\Exception $e) {
            error_log('Redis get failed: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 设置值
     */
    public function set($key, $value, $ttl = 0) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            if ($ttl > 0) {
                return $redis->setex($this->key($key), $ttl, $value);
            } else {
                return $redis->set($this->key($key), $value);
            }
        } catch (\Exception $e) {
            error_log('Redis set failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除键
     */
    public function delete($key) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->del($this->key($key)) > 0;
        } catch (\Exception $e) {
            error_log('Redis delete failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查键是否存在
     */
    public function exists($key) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->exists($this->key($key)) > 0;
        } catch (\Exception $e) {
            error_log('Redis exists failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 自增
     */
    public function incr($key, $amount = 1) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            if ($amount === 1) {
                return $redis->incr($this->key($key));
            } else {
                return $redis->incrBy($this->key($key), $amount);
            }
        } catch (\Exception $e) {
            error_log('Redis incr failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 自减
     */
    public function decr($key, $amount = 1) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            if ($amount === 1) {
                return $redis->decr($this->key($key));
            } else {
                return $redis->decrBy($this->key($key), $amount);
            }
        } catch (\Exception $e) {
            error_log('Redis decr failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 哈希表操作
     */
    public function hGet($key, $field) {
        $redis = $this->getConnection();
        if (!$redis) {
            return null;
        }
        
        try {
            return $redis->hGet($this->key($key), $field);
        } catch (\Exception $e) {
            error_log('Redis hGet failed: ' . $e->getMessage());
            return null;
        }
    }
    
    public function hSet($key, $field, $value) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->hSet($this->key($key), $field, $value);
        } catch (\Exception $e) {
            error_log('Redis hSet failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function hGetAll($key) {
        $redis = $this->getConnection();
        if (!$redis) {
            return [];
        }
        
        try {
            return $redis->hGetAll($this->key($key));
        } catch (\Exception $e) {
            error_log('Redis hGetAll failed: ' . $e->getMessage());
            return [];
        }
    }
    
    public function hDel($key, $field) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->hDel($this->key($key), $field) > 0;
        } catch (\Exception $e) {
            error_log('Redis hDel failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function hIncrBy($key, $field, $amount = 1) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->hIncrBy($this->key($key), $field, $amount);
        } catch (\Exception $e) {
            error_log('Redis hIncrBy failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查哈希字段是否存在
     */
    public function hExists($key, $field) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->hExists($this->key($key), $field);
        } catch (\Exception $e) {
            error_log('Redis hExists failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 集合操作
     */
    public function sAdd($key, $member) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->sAdd($this->key($key), $member) > 0;
        } catch (\Exception $e) {
            error_log('Redis sAdd failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function sRem($key, $member) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->sRem($this->key($key), $member) > 0;
        } catch (\Exception $e) {
            error_log('Redis sRem failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function sMembers($key) {
        $redis = $this->getConnection();
        if (!$redis) {
            return [];
        }
        
        try {
            return $redis->sMembers($this->key($key));
        } catch (\Exception $e) {
            error_log('Redis sMembers failed: ' . $e->getMessage());
            return [];
        }
    }
    
    public function sIsMember($key, $member) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->sIsMember($this->key($key), $member);
        } catch (\Exception $e) {
            error_log('Redis sIsMember failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function sCard($key) {
        $redis = $this->getConnection();
        if (!$redis) {
            return 0;
        }
        
        try {
            return $redis->sCard($this->key($key));
        } catch (\Exception $e) {
            error_log('Redis sCard failed: ' . $e->getMessage());
            return 0;
        }
    }
    
    public function sPop($key) {
        $redis = $this->getConnection();
        if (!$redis) {
            return null;
        }
        
        try {
            return $redis->sPop($this->key($key));
        } catch (\Exception $e) {
            error_log('Redis sPop failed: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 列表操作
     */
    public function lPush($key, $value) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->lPush($this->key($key), $value) > 0;
        } catch (\Exception $e) {
            error_log('Redis lPush failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function rPush($key, $value) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->rPush($this->key($key), $value) > 0;
        } catch (\Exception $e) {
            error_log('Redis rPush failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function lPop($key) {
        $redis = $this->getConnection();
        if (!$redis) {
            return null;
        }
        
        try {
            return $redis->lPop($this->key($key));
        } catch (\Exception $e) {
            error_log('Redis lPop failed: ' . $e->getMessage());
            return null;
        }
    }
    
    public function lLen($key) {
        $redis = $this->getConnection();
        if (!$redis) {
            return 0;
        }
        
        try {
            return $redis->lLen($this->key($key));
        } catch (\Exception $e) {
            error_log('Redis lLen failed: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 事务操作
     */
    public function multi() {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->multi();
        } catch (\Exception $e) {
            error_log('Redis multi failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function exec() {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->exec();
        } catch (\Exception $e) {
            error_log('Redis exec failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function watch($key) {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->watch($this->key($key));
        } catch (\Exception $e) {
            error_log('Redis watch failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function unwatch() {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            return $redis->unwatch();
        } catch (\Exception $e) {
            error_log('Redis unwatch failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 清空所有键（危险操作）
     */
    public function flushAll() {
        $redis = $this->getConnection();
        if (!$redis) {
            return false;
        }
        
        try {
            // 只删除带前缀的键
            $keys = $redis->keys($this->prefix . '*');
            if (!empty($keys)) {
                return $redis->del($keys) > 0;
            }
            return true;
        } catch (\Exception $e) {
            error_log('Redis flushAll failed: ' . $e->getMessage());
            return false;
        }
    }
}

