<?php
namespace Redirect301\Modules;

use Redirect301\Core\Logger;
use Redirect301\Core\Config;
use Redirect301\Core\RedisManager;

/**
 * 克隆站分组重定向模块
 */
class CloneGroupRedirect extends RedirectModule {
    private $db;
    
    public function __construct(Logger $logger, Config $config, RedisManager $redis) {
        parent::__construct($logger, $config, $redis);
        $this->initDB();
    }
    
    /**
     * 获取模块名称
     */
    public function getName() {
        return 'clonegroup';
    }
    
    /**
     * 获取模块优先级
     */
    public function getPriority(): float {
        return 4.6;
    }
    
    /**
     * 初始化数据库连接
     */
    private function initDB() {
        $dbPath = __DIR__ . '/../admin/data/clonegroupsite.db';
        if (file_exists($dbPath)) {
            $this->db = new \SQLite3($dbPath);
            $this->db->busyTimeout(5000);
        }
    }
    
    /**
     * 检查是否需要重定向
     */
    public function check(): ?string {
        if (!$this->db) {
            return null;
        }
        
        // 提取基础域名并尝试递归查找.
        $baseDomain = $this->extractBaseDomain($this->currentHost);
        $group = $this->findDomainGroup($baseDomain);
        
        if (!$group) {
            return null;
        }
        
        // 判断是否需要重定向（使用匹配到的组内域名作为基础域名）
        if (!$this->shouldRedirect($this->currentHost, $this->currentUri, $group, $group['matched_domain'])) {
            return null;
        }
        
        // 生成重定向目标（使用找到的组内域名作为基础域名）
        $redirectUrl = $this->generateRedirectUrl($this->currentHost, $this->currentUri, $group, $group['matched_domain']);
        
        if ($redirectUrl) {
            // 调试：记录到error_log
            error_log("CloneGroupRedirect: 准备跳转 - group_name={$group['group_name']}, url={$redirectUrl}");
            
            // 使用父类的redirect方法（自动记录日志）
            $this->redirect($redirectUrl, $group['group_name'], 301);
        }
        
        return null;
    }
    
    /**
     * 查找域名所属的分组（支持递归查找父级域名）
     */
    private function findDomainGroup($domain) {
        // 尝试查找当前域名
        $stmt = $this->db->prepare("
            SELECT g.*, d.domain as matched_domain
            FROM clonegroupsite_groups g
            INNER JOIN clonegroupsite_domains d ON g.group_name = d.group_name
            WHERE d.domain = ?
            LIMIT 1
        ");
        $stmt->bindValue(1, $domain, SQLITE3_TEXT);
        $result = $stmt->execute();
        $group = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($group) {
            return $group;
        }
        
        // 如果没找到，尝试查找父级域名
        // 例如: 234.1-14.cn → 1-14.cn
        $parts = explode('.', $domain);
        if (count($parts) >= 3) {
            array_shift($parts);
            $parentDomain = implode('.', $parts);
            return $this->findDomainGroup($parentDomain);
        }
        
        return null;
    }
    
    /**
     * 判断是否需要重定向
     */
    private function shouldRedirect($currentDomain, $currentUri, $group, $matchedDomain) {
        $redirectMode = $group['redirect_mode'];
        
        // 解析集权配置
        $centralizeConfig = [];
        if (!empty($group['centralize_targets'])) {
            $centralizeConfig = json_decode($group['centralize_targets'], true);
        }
        $hasCentralize = !empty($centralizeConfig['enabled']);
        
        // ===== 集权模式：接管所有 =====
        if ($hasCentralize) {
            // 检查是否是集权对象自己
            if ($this->isCentralizeTarget($currentDomain, $centralizeConfig['domains'] ?? [])) {
                return false;
            }
            return true; // 接管所有
        }
        
        // ===== 默认模式 =====
        $domainType = $this->getDomainType($currentDomain, $matchedDomain, $group);
        
        switch ($redirectMode) {
            case 'random_three':
                // 随机三端模式：三端及其内页不接管，其他接管
                return !in_array($domainType, ['top', 'www', 'm']);
                
            case 'fixed_www':
                // 固定www模式：只有www及其内页不接管
                return $domainType !== 'www';
                
            case 'fixed_m':
                // 固定m模式：只有m及其内页不接管
                return $domainType !== 'm';
                
            case 'fixed_top':
                // 固定顶级模式：只有@及其内页不接管
                return $domainType !== 'top';
                
            case 'custom_subdomain':
                // 自定义二级模式：只有目标端及其内页不接管
                return $domainType !== 'target';
        }
        
        return false;
    }
    
    /**
     * 获取域名类型
     * @param string $domain 当前访问的域名
     * @param string $matchedDomain 数据库中匹配到的组内域名
     * @param array $group 分组配置
     */
    private function getDomainType($domain, $matchedDomain, $group) {
        // 移除协议和路径
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        
        // 使用数据库中匹配到的域名作为基础域名
        $baseDomain = $matchedDomain;
        
        // 检查是否是目标端（自定义二级模式）
        if ($group['redirect_mode'] === 'custom_subdomain') {
            $targetSubdomain = $group['redirect_target'];
            if ($domain === "{$targetSubdomain}.{$baseDomain}") {
                return 'target';
            }
        }
        
        // 检查三端
        if ($domain === $baseDomain) {
            return 'top'; // @
        }
        if ($domain === "www.{$baseDomain}") {
            return 'www';
        }
        if ($domain === "m.{$baseDomain}") {
            return 'm';
        }
        
        // 其他二级域名
        return 'other';
    }
    
    /**
     * 生成重定向URL
     */
    private function generateRedirectUrl($currentDomain, $currentUri, $group, $baseDomain) {
        $redirectMode = $group['redirect_mode'];
        
        // 解析集权配置
        $centralizeConfig = [];
        if (!empty($group['centralize_targets'])) {
            $centralizeConfig = json_decode($group['centralize_targets'], true);
        }
        
        // ===== 集权模式 =====
        if (!empty($centralizeConfig['enabled'])) {
            $targets = $centralizeConfig['domains'] ?? [];
            
            // 过滤掉组内域名（避免死循环）
            $currentBase = $this->extractBaseDomain($currentDomain);
            $targets = array_filter($targets, function($target) use ($currentBase) {
                $targetBase = $this->extractBaseDomain($target);
                return $targetBase !== $currentBase;
            });
            
            if (empty($targets)) {
                return null;
            }
            
            // 随机选择一个集权对象
            $target = $targets[array_rand($targets)];
            
            // 解析集权对象格式
            $parsed = $this->parseCentralizeTarget($target);
            
            switch ($parsed['type']) {
                case 'random':
                    // 随机三端
                    $endpoints = [
                        $parsed['domain'],
                        "www.{$parsed['domain']}",
                        "m.{$parsed['domain']}"
                    ];
                    $finalDomain = $endpoints[array_rand($endpoints)];
                    break;
                    
                case 'www':
                case 'm':
                case 'top':
                    // 固定格式
                    $finalDomain = $parsed['domain'];
                    break;
            }
            
            // 添加协议头
            return "http://{$finalDomain}";
        }
        
        // ===== 默认模式 =====
        switch ($redirectMode) {
            case 'random_three':
                // 随机三端
                $endpoints = [
                    $baseDomain,
                    "www.{$baseDomain}",
                    "m.{$baseDomain}"
                ];
                $targetDomain = $endpoints[array_rand($endpoints)];
                break;
                
            case 'fixed_www':
                $targetDomain = "www.{$baseDomain}";
                break;
                
            case 'fixed_m':
                $targetDomain = "m.{$baseDomain}";
                break;
                
            case 'fixed_top':
                $targetDomain = $baseDomain;
                break;
                
            case 'custom_subdomain':
                $targetSubdomain = $group['redirect_target'];
                $targetDomain = "{$targetSubdomain}.{$baseDomain}";
                break;
                
            default:
                return null;
        }
        
        return "http://{$targetDomain}";
    }
    
    /**
     * 解析集权对象格式
     */
    private function parseCentralizeTarget($target) {
        // 移除协议和路径
        $target = preg_replace('#^https?://#', '', $target);
        $target = preg_replace('#/.*$#', '', $target);
        $target = trim($target);
        
        // 1. 检查是否是 @.domain.com 格式（固定顶级）
        if (preg_match('/^@\.(.+)$/', $target, $matches)) {
            return [
                'type' => 'top',
                'domain' => $matches[1]
            ];
        }
        
        // 2. 检查是否是 www.domain.com 格式
        if (preg_match('/^www\.(.+)$/', $target)) {
            return [
                'type' => 'www',
                'domain' => $target
            ];
        }
        
        // 3. 检查是否是 m.domain.com 格式
        if (preg_match('/^m\.(.+)$/', $target)) {
            return [
                'type' => 'm',
                'domain' => $target
            ];
        }
        
        // 4. 否则是顶级域名格式（随机三端）
        return [
            'type' => 'random',
            'domain' => $target
        ];
    }
    
    /**
     * 检查是否是集权对象
     */
    private function isCentralizeTarget($currentDomain, $targets) {
        $currentBase = $this->extractBaseDomain($currentDomain);
        
        foreach ($targets as $target) {
            $targetBase = $this->extractBaseDomain($target);
            if ($currentBase === $targetBase) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 提取基础域名（去除所有二级前缀，只保留主域名）
     */
    private function extractBaseDomain($domain) {
        // 去除协议
        $domain = preg_replace('#^https?://#', '', $domain);
        // 去除路径
        $domain = preg_replace('#/.*$#', '', $domain);
        // 去除端口
        $domain = preg_replace('#:\d+$#', '', $domain);
        // 去除 @ 前缀
        $domain = ltrim($domain, '@.');
        // 转小写
        $domain = strtolower(trim($domain));
        
        // 去除所有二级前缀，只保留主域名
        // 例如: wap.example.com => example.com
        //      www.example.com => example.com
        //      m.example.com => example.com
        $parts = explode('.', $domain);
        if (count($parts) >= 3) {
            // 有二级域名，去掉第一部分
            array_shift($parts);
            return implode('.', $parts);
        }
        
        return $domain;
    }
}
