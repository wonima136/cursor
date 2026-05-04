<?php
namespace Redirect301\Modules;

use Redirect301\Core\Logger;
use Redirect301\Core\Config;
use Redirect301\Core\RedisManager;
use Redirect301\Utils\FocusRedirectCache;
use Redirect301\Utils\SpiderDetector;

/**
 * 智能集权重定向模块
 * 
 * 功能：
 * 1. 基于网站数据的智能SEO集权管理
 * 2. 支持三端统一处理（@/www/m）
 * 3. 支持定时跳转控制
 * 4. 支持链接锁定机制
 */
class FocusRedirect extends RedirectModule {
    
    private $cache;
    
    public function __construct(Logger $logger, Config $config, RedisManager $redis) {
        parent::__construct($logger, $config, $redis);
        // 使用直接的Redis连接，而不是RedisManager（避免key前缀问题）
        require_once dirname(__DIR__) . '/admin/redis_config.php';
        $redisConn = getRedis();
        $this->cache = new FocusRedirectCache($redisConn);
        // 覆盖父类的redis，使用直接连接
        $this->redis = $redisConn;
    }
    
    public function getName() {
        return 'focus';
    }
    
    public function getPriority() {
        return 1; // 优先级1（在整站重定向之后，大站池之前）
    }
    
    /**
     * 检查是否需要执行集权重定向
     */
    public function check() {
        // 构建完整URL（不包含协议）
        $currentUrl = $_SERVER['HTTP_HOST'];
        if (!empty($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] !== '/') {
            $currentUrl .= $_SERVER['REQUEST_URI'];
        }
        
        // 从Redis快速查询锁定状态
        $lock = $this->cache->getUrlLock($currentUrl);
        
        if (!$lock) {
            return false; // 未锁定
        }
        
        // 【关键检查】防止集权目标URL跳转到自己
        // 提取目标URL的域名部分
        $targetHost = parse_url($lock['target_url'], PHP_URL_HOST);
        if ($targetHost && $_SERVER['HTTP_HOST'] === $targetHost) {
            // 当前访问的就是集权目标，不跳转
            error_log("FocusRedirect: 跳过集权目标自身 - {$currentUrl}");
            return false;
        }
        
        // 如果是三端之一，检查整个三端组的锁定状态
        if (!empty($lock['terminal_group'])) {
            return $this->handleTerminalGroup($currentUrl, $lock);
        }
        
        // 普通二级域名处理
        return $this->handleSingleUrl($currentUrl, $lock);
    }
    
    /**
     * 处理三端组跳转
     */
    private function handleTerminalGroup($currentUrl, $lock) {
        // 获取任务配置
        $task = $this->getTaskFromCache($lock['task_id']);
        
        if (!$task || !$task['enabled']) {
            return false;
        }
        
        // 验证蜘蛛筛选
        if (!$this->validateSpider($task['spider_filter'] ?? [])) {
            return false;
        }
        
        // 检查三端组的定时条件
        if (!$this->cache->shouldRedirectGroup($lock['terminal_group'], $lock['schedule_interval'])) {
            return false;
        }
        
        // 检查概率
        if (!$this->checkProbability($task['probability'])) {
            return false;
        }
        
        // 执行跳转
        $this->executeRedirect($currentUrl, $lock, $task);
        
        // 更新整个三端组的最后跳转时间
        $this->cache->updateGroupLastRedirectTime($lock['terminal_group']);
        
        return true;
    }
    
    /**
     * 处理单个URL跳转
     */
    private function handleSingleUrl($currentUrl, $lock) {
        // 获取任务配置
        $task = $this->getTaskFromCache($lock['task_id']);
        
        if (!$task || !$task['enabled']) {
            return false;
        }
        
        // 验证蜘蛛筛选
        if (!$this->validateSpider($task['spider_filter'] ?? [])) {
            return false;
        }
        
        // 检查定时条件
        if (!$this->cache->shouldRedirect($currentUrl)) {
            return false;
        }
        
        // 检查概率
        if (!$this->checkProbability($task['probability'])) {
            return false;
        }
        
        // 执行跳转
        $this->executeRedirect($currentUrl, $lock, $task);
        
        // 更新最后跳转时间
        $this->cache->updateLastRedirectTime($currentUrl);
        
        return true;
    }
    
    /**
     * 从缓存获取任务配置
     */
    private function getTaskFromCache($taskId) {
        // 先从Redis缓存获取
        $cacheKey = 'focus:task:' . $taskId;
        
        error_log("FocusRedirect::getTaskFromCache - taskId=$taskId, key=$cacheKey");
        
        $cached = $this->redis->get($cacheKey);
        
        error_log("FocusRedirect::getTaskFromCache - Redis返回: " . ($cached ? 'found' : 'not found'));
        
        if ($cached) {
            $task = json_decode($cached, true);
            error_log("FocusRedirect::getTaskFromCache - 从Redis加载, spider_filter=" . json_encode($task['spider_filter'] ?? null));
            return $task;
        }
        
        // 从SQLite加载
        error_log("FocusRedirect::getTaskFromCache - 从SQLite加载");
        $task = $this->loadTaskFromSQLite($taskId);
        
        if ($task) {
            error_log("FocusRedirect::getTaskFromCache - SQLite加载成功, spider_filter=" . json_encode($task['spider_filter'] ?? null));
            // 永久缓存，直到任务删除时清除
            $this->redis->set($cacheKey, json_encode($task));
        }
        
        return $task;
    }
    
    /**
     * 从SQLite加载任务
     */
    private function loadTaskFromSQLite($taskId) {
        $dbFile = dirname(__DIR__) . '/admin/data/focus.db';
        
        if (!file_exists($dbFile)) {
            return null;
        }
        
        try {
            $db = new \SQLite3($dbFile);
            $db->busyTimeout(5000);
            
            $stmt = $db->prepare("
                SELECT * FROM focus_tasks WHERE id = ? AND enabled = 1
            ");
            $stmt->bindValue(1, $taskId, SQLITE3_TEXT);
            $result = $stmt->execute();
            $task = $result->fetchArray(SQLITE3_ASSOC);
            
            $db->close();
            
            if ($task) {
                // 解析JSON字段
                $task['spider_filter'] = json_decode($task['spider_filter'] ?? '{}', true);
                $task['filter_keywords'] = json_decode($task['filter_keywords'] ?? '[]', true);
                
                // 解析目标URL（可能是JSON数组）
                $targetUrl = $task['target_url'] ?? '';
                if (!empty($targetUrl) && $targetUrl[0] === '[') {
                    $task['target_urls'] = json_decode($targetUrl, true) ?: [$targetUrl];
                } else {
                    $task['target_urls'] = [$targetUrl];
                }
            }
            
            return $task;
            
        } catch (\Exception $e) {
            error_log("FocusRedirect: 加载任务失败 - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 检查概率
     */
    private function checkProbability($probability) {
        if ($probability >= 100) {
            return true;
        }
        
        if ($probability <= 0) {
            return false;
        }
        
        return (mt_rand(1, 100) <= $probability);
    }
    
    /**
     * 执行重定向
     */
    private function executeRedirect($sourceUrl, $lock, $task) {
        // 检查是否已有目标URL
        if (empty($lock['target_url'])) {
            // 第一次跳转：从任务的目标URL列表中随机选择一个
            $targetUrls = $task['target_urls'] ?? [];
            if (empty($targetUrls)) {
                error_log("FocusRedirect: 任务 {$task['id']} 没有配置目标URL");
                return;
            }
            
            // 随机选择一个目标URL
            $targetUrl = $targetUrls[array_rand($targetUrls)];
            
            // 更新Redis中的锁定记录，保存选择的目标URL
            $lock['target_url'] = $targetUrl;
            $this->cache->setUrlLock($sourceUrl, $lock);
            
            error_log("FocusRedirect: 第一次跳转，随机选择目标: {$sourceUrl} -> {$targetUrl}");
        } else {
            // 后续跳转：使用锁定时保存的目标URL（保证每次跳转到同一个目标）
            $targetUrl = $lock['target_url'];
        }
        
        // 1. 记录日志到SQLite（已注释，使用全局日志系统）
        // $this->logRedirect($sourceUrl, $lock, $task, $targetUrl);
        
        // 2. 增加任务统计（异步）
        $this->cache->incrementTaskStats($task['id']);
        
        // 3. 执行301/302跳转（父类redirect方法会自动记录日志）
        $this->redirect($targetUrl, $task['name'], $task['redirect_type']);
    }
    
    /**
     * 记录跳转日志到SQLite
     */
    private function logRedirect($sourceUrl, $lock, $task, $targetUrl) {
        $dbFile = dirname(__DIR__) . '/admin/data/focus.db';
        
        if (!file_exists($dbFile)) {
            return;
        }
        
        try {
            $db = new \SQLite3($dbFile);
            $db->busyTimeout(5000);
            
            // 获取url_keyword_id
            $stmt = $db->prepare("SELECT id FROM url_keywords WHERE full_url = ?");
            $stmt->bindValue(1, $sourceUrl, SQLITE3_TEXT);
            $result = $stmt->execute();
            $urlKeyword = $result->fetchArray(SQLITE3_ASSOC);
            
            if ($urlKeyword) {
                $urlKeywordId = $urlKeyword['id'];
                
                // 插入日志
                $spiderInfo = SpiderDetector::detect($_SERVER['HTTP_USER_AGENT'] ?? '');
                
                $stmt = $db->prepare("
                    INSERT INTO focus_redirect_logs 
                    (task_id, url_keyword_id, source_url, target_url, client_ip, user_agent, spider_type, redirect_type)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bindValue(1, $task['id'], SQLITE3_TEXT);
                $stmt->bindValue(2, $urlKeywordId, SQLITE3_INTEGER);
                $stmt->bindValue(3, $sourceUrl, SQLITE3_TEXT);
                $stmt->bindValue(4, $targetUrl, SQLITE3_TEXT);
                $stmt->bindValue(5, $_SERVER['REMOTE_ADDR'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(6, $_SERVER['HTTP_USER_AGENT'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(7, $spiderInfo['type'] ?? 'normal_user', SQLITE3_TEXT);
                $stmt->bindValue(8, $task['redirect_type'], SQLITE3_INTEGER);
                $stmt->execute();
                
                // 更新url_keywords表的统计
                $stmt = $db->prepare("
                    UPDATE url_keywords 
                    SET redirect_count = redirect_count + 1,
                        last_redirect_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->bindValue(1, $urlKeywordId, SQLITE3_INTEGER);
                $stmt->execute();
            }
            
            $db->close();
            
        } catch (\Exception $e) {
            error_log("FocusRedirect: 记录日志失败 - " . $e->getMessage());
        }
    }
}

