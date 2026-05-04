<?php
/**
 * 域名索引管理
 * 用于重建 Redis 域名索引，避免引入 redirect.php 导致重定向执行
 */

// 引入必要的配置
if (!defined('_REDIRECT301_CONFIG_DIR_')) {
    define('_REDIRECT301_CONFIG_DIR_', __DIR__ . '/data');
}

// Redis 配置
if (!defined('_REDIRECT301_REDIS_HOST_')) {
    define('_REDIRECT301_REDIS_HOST_', '127.0.0.1');
}
if (!defined('_REDIRECT301_REDIS_PORT_')) {
    define('_REDIRECT301_REDIS_PORT_', 6379);
}
if (!defined('_REDIRECT301_REDIS_PASSWORD_')) {
    define('_REDIRECT301_REDIS_PASSWORD_', '');
}
if (!defined('_REDIRECT301_REDIS_DB_')) {
    define('_REDIRECT301_REDIS_DB_', 1);
}
if (!defined('_REDIRECT301_SITE_ID_')) {
    define('_REDIRECT301_SITE_ID_', substr(md5(dirname(__DIR__)), 0, 8));
}
if (!defined('_REDIRECT301_REDIS_PREFIX_')) {
    define('_REDIRECT301_REDIS_PREFIX_', 'redirect301:' . _REDIRECT301_SITE_ID_ . ':');
}

/**
 * 获取 Redis 连接
 */
function _domainIndex_getRedis() {
    static $redis = null;
    
    if ($redis === null) {
        if (!class_exists('Redis')) {
            return null;
        }
        
        $redis = new Redis();
        
        try {
            $redis->connect(_REDIRECT301_REDIS_HOST_, _REDIRECT301_REDIS_PORT_, 3);
            
            if (_REDIRECT301_REDIS_PASSWORD_) {
                $redis->auth(_REDIRECT301_REDIS_PASSWORD_);
            }
            
            $redis->select(_REDIRECT301_REDIS_DB_);
        } catch (Exception $e) {
            error_log('Redis connection failed: ' . $e->getMessage());
            $redis = false;
        }
    }
    
    return $redis ?: null;
}

/**
 * 重建全局域名索引到 Redis
 */
function rebuildDomainIndex() {
    $redis = _domainIndex_getRedis();
    if (!$redis) {
        return false;
    }
    
    $prefix = _REDIRECT301_REDIS_PREFIX_;
    $domainsKey = "{$prefix}domains";
    
    // 清空旧索引
    $redis->del($domainsKey);
    
    $allDomains = [];
    
    // 1. 收集整站重定向的域名
    $sitewideFile = _REDIRECT301_CONFIG_DIR_ . '/sitewide.json';
    if (file_exists($sitewideFile)) {
        $config = json_decode(file_get_contents($sitewideFile), true);
        foreach ($config['tasks'] ?? [] as $task) {
            // 不检查 enabled，只要任务存在就收集域名
            
            foreach ($task['source_domains'] ?? [] as $domain) {
                $cleanDomain = preg_replace('/{[^}]+}\.?/', '', $domain);
                $cleanDomain = strtolower(trim($cleanDomain));
                if (!empty($cleanDomain)) {
                    $allDomains[] = $cleanDomain;
                }
            }
        }
    }
    
    // 2. 收集站群链轮的域名
    $groupsFile = _REDIRECT301_CONFIG_DIR_ . '/groups.json';
    if (file_exists($groupsFile)) {
        $groups = json_decode(file_get_contents($groupsFile), true);
        // 兼容两种数据结构：数组 或 {groups: [...]}
        if (isset($groups['groups'])) {
            $groups = $groups['groups'];
        }
        foreach ($groups ?? [] as $group) {
            // 不检查 enabled，只要分组存在就收集域名
            
            foreach ($group['domains'] ?? [] as $d) {
                $cleanDomain = preg_replace('/{[^}]+}\.?/', '', $d['domain']);
                $cleanDomain = strtolower(trim($cleanDomain));
                if (!empty($cleanDomain)) {
                    $allDomains[] = $cleanDomain;
                }
            }
        }
    }
    
    // 3. 收集寄生重定向的域名
    $parasiteFile = _REDIRECT301_CONFIG_DIR_ . '/parasites.json';
    if (file_exists($parasiteFile)) {
        $config = json_decode(file_get_contents($parasiteFile), true);
        foreach ($config['tasks'] ?? [] as $task) {
            // 不检查 enabled，只要任务存在就收集域名
            
            if ($task['manage_type'] === 'directory') {
                foreach ($task['source_domains'] ?? [] as $d) {
                    $cleanDomain = preg_replace('/{[^}]+}\.?/', '', $d['domain']);
                    $cleanDomain = strtolower(trim($cleanDomain));
                    if (!empty($cleanDomain)) {
                        $allDomains[] = $cleanDomain;
                    }
                }
            } else {
                $cleanDomain = preg_replace('/{[^}]+}\.?/', '', $task['source_domain'] ?? '');
                $cleanDomain = strtolower(trim($cleanDomain));
                if (!empty($cleanDomain)) {
                    $allDomains[] = $cleanDomain;
                }
            }
        }
    }
    
    // 去重
    $allDomains = array_unique($allDomains);
    
    // 批量写入 Redis SET（永久存储，不设置过期时间）
    if (!empty($allDomains)) {
        foreach ($allDomains as $domain) {
            $redis->sAdd($domainsKey, $domain);
        }
    }
    
    // 清除 APCu 缓存，确保下次读取最新数据
    if (function_exists('apcu_clear_cache')) {
        apcu_clear_cache();
    }
    
    return count($allDomains);
}

