<?php
namespace Redirect301\Utils;

/**
 * 智能集权重定向Redis缓存类
 * 
 * 功能：
 * 1. 缓存URL锁定状态
 * 2. 缓存最后跳转时间
 * 3. 支持三端组统一管理
 * 4. 缓存任务统计数据
 */
class FocusRedirectCache {
    
    private $redis;
    private $prefix = 'focus:';
    
    public function __construct($redis) {
        $this->redis = $redis;
    }
    
    /**
     * 获取URL锁定信息
     * 
     * @param string $url 完整URL（不含协议）
     * @return array|null 锁定信息
     */
    public function getUrlLock($url) {
        // 直接使用URL作为key，与后台保存时的格式一致
        $key = $this->prefix . 'lock:' . $url;
        $data = $this->redis->get($key);
        
        if (!$data) {
            return null;
        }
        
        return json_decode($data, true);
    }
    
    /**
     * 设置URL锁定
     * 
     * @param string $url 完整URL（不含协议）
     * @param array $lockData 锁定数据
     */
    public function setUrlLock($url, $lockData) {
        // 直接使用URL作为key，与后台保存时的格式一致
        $key = $this->prefix . 'lock:' . $url;
        
        // 计算定时间隔（秒）
        $scheduleInterval = 0;
        if (!empty($lockData['schedule_days'])) {
            $scheduleInterval += $lockData['schedule_days'] * 86400;
        }
        if (!empty($lockData['schedule_hours'])) {
            $scheduleInterval += $lockData['schedule_hours'] * 3600;
        }
        if (!empty($lockData['schedule_minutes'])) {
            $scheduleInterval += $lockData['schedule_minutes'] * 60;
        }
        
        $data = [
            'task_id' => $lockData['task_id'],
            'target_url' => $lockData['target_url'],
            'terminal_group' => $lockData['terminal_group'] ?? null,
            'schedule_interval' => $scheduleInterval,
            'locked_at' => time()
        ];
        
        // 缓存7天
        $this->redis->setex($key, 604800, json_encode($data));
    }
    
    /**
     * 批量设置URL锁定（用于任务创建时）
     * 
     * @param array $urls URL数组
     * @param array $lockData 锁定数据
     */
    public function batchSetUrlLock($urls, $lockData) {
        foreach ($urls as $url) {
            $this->setUrlLock($url, $lockData);
        }
    }
    
    /**
     * 删除URL锁定
     * 
     * @param string $url 完整URL（不含协议）
     */
    public function deleteUrlLock($url) {
        // 直接使用URL作为key，与后台保存时的格式一致
        $key = $this->prefix . 'lock:' . $url;
        $this->redis->del($key);
    }
    
    /**
     * 批量删除URL锁定（用于任务删除时）
     * 
     * @param array $urls URL数组
     */
    public function batchDeleteUrlLock($urls) {
        foreach ($urls as $url) {
            $this->deleteUrlLock($url);
        }
    }
    
    /**
     * 检查是否应该执行跳转（单个URL）
     * 
     * @param string $url 完整URL（不含协议）
     * @return bool
     */
    public function shouldRedirect($url) {
        $lock = $this->getUrlLock($url);
        
        if (!$lock || $lock['schedule_interval'] <= 0) {
            return true; // 无定时限制
        }
        
        // 检查是否在有效期内（从锁定时间开始计算）
        $lockedAt = $lock['locked_at'] ?? 0;
        $scheduleInterval = $lock['schedule_interval'] * 60; // 转换为秒（后台存储的是分钟）
        $elapsed = time() - $lockedAt;
        
        // 在有效期内才跳转
        return $elapsed <= $scheduleInterval;
    }
    
    /**
     * 检查三端组是否应该执行跳转
     * 
     * @param string $terminalGroup 三端组标识
     * @param int $scheduleInterval 定时间隔（秒）
     * @return bool
     */
    public function shouldRedirectGroup($terminalGroup, $scheduleInterval) {
        if ($scheduleInterval <= 0) {
            return true; // 无定时限制
        }
        
        // 获取三端组的锁定时间
        $groupLockKey = $this->prefix . 'group_lock:' . $terminalGroup;
        $groupLock = $this->redis->get($groupLockKey);
        
        if (!$groupLock) {
            return false; // 三端组未锁定
        }
        
        $lockData = json_decode($groupLock, true);
        $lockedAt = $lockData['locked_at'] ?? 0;
        $scheduleIntervalSeconds = $scheduleInterval * 60; // 转换为秒（传入的是分钟）
        $elapsed = time() - $lockedAt;
        
        // 在有效期内才跳转
        return $elapsed <= $scheduleIntervalSeconds;
    }
    
    /**
     * 更新URL最后跳转时间
     * 
     * @param string $url 完整URL（不含协议）
     */
    public function updateLastRedirectTime($url) {
        // 直接使用URL作为key，与后台保存时的格式一致
        $key = $this->prefix . 'last_redirect:' . $url;
        // 缓存7天
        $this->redis->setex($key, 604800, time());
    }
    
    /**
     * 更新三端组最后跳转时间
     * 
     * @param string $terminalGroup 三端组标识
     */
    public function updateGroupLastRedirectTime($terminalGroup) {
        $key = $this->prefix . 'group_last_redirect:' . md5($terminalGroup);
        // 缓存7天
        $this->redis->setex($key, 604800, time());
    }
    
    /**
     * 增加任务统计
     * 
     * @param string $taskId 任务ID
     */
    public function incrementTaskStats($taskId) {
        $key = $this->prefix . 'stats:' . $taskId;
        
        // 增加总跳转次数
        $this->redis->hincrby($key, 'total_redirects', 1);
        
        // 更新最后跳转时间
        $this->redis->hset($key, 'last_redirect_at', date('Y-m-d H:i:s'));
        
        // 设置过期时间（30天）
        $this->redis->expire($key, 2592000);
    }
    
    /**
     * 获取任务统计
     * 
     * @param string $taskId 任务ID
     * @return array
     */
    public function getTaskStats($taskId) {
        $key = $this->prefix . 'stats:' . $taskId;
        $stats = $this->redis->hgetall($key);
        
        return [
            'total_redirects' => intval($stats['total_redirects'] ?? 0),
            'last_redirect_at' => $stats['last_redirect_at'] ?? null
        ];
    }
    
    /**
     * 重置任务统计
     * 
     * @param string $taskId 任务ID
     */
    public function resetTaskStats($taskId) {
        $key = $this->prefix . 'stats:' . $taskId;
        $this->redis->del($key);
    }
    
    /**
     * 清除任务所有缓存（用于任务删除时）
     * 
     * @param string $taskId 任务ID
     */
    public function clearTaskCache($taskId) {
        // 删除任务配置缓存
        $this->redis->del($this->prefix . 'task:' . $taskId);
        
        // 删除任务统计
        $this->resetTaskStats($taskId);
    }
    
    /**
     * 获取所有锁定的URL（用于任务管理）
     * 
     * @param string $taskId 任务ID
     * @return array
     */
    public function getTaskLockedUrls($taskId) {
        $pattern = $this->prefix . 'lock:*';
        $keys = [];
        $cursor = null;
        
        // 使用SCAN遍历所有锁定key
        do {
            $result = $this->redis->scan($cursor, $pattern, 100);
            if ($result !== false) {
                list($cursor, $keys_batch) = $result;
                $keys = array_merge($keys, $keys_batch);
            }
        } while ($cursor > 0);
        
        $urls = [];
        foreach ($keys as $key) {
            $data = $this->redis->get($key);
            if ($data) {
                $lock = json_decode($data, true);
                if ($lock['task_id'] === $taskId) {
                    $urls[] = $lock;
                }
            }
        }
        
        return $urls;
    }
}
