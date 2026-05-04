<?php
/**
 * 泛二级域名管理器（精简版）
 * 主要功能：
 * 1. 为静态资源请求提供子域名配置（getOrCreateSubdomainConfig）
 * 2. 路由子域名请求到新架构的模式处理器（handleSubdomainRequest）
 * 
 * 注意：三个模式的渲染逻辑已迁移到独立的模式文件夹：
 * - mode_duli/ (独立配置模式)
 * - mode_guding/ (固定顶级模式)
 * - mode_dongtai/ (动态顶级模式)
 */
class SubdomainManager {
    private $domainExtractor;
    private $configManager;
    private $keywordManager;
    private $cacheManager;
    private $base_dir;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        
        // 引入依赖类
        if (!class_exists('DomainExtractor')) {
            require_once __DIR__ . '/DomainExtractor.php';
        }
        if (!class_exists('DomainConfigManager')) {
            require_once __DIR__ . '/DomainConfigManager.php';
        }
        if (!class_exists('KeywordListManager')) {
            require_once __DIR__ . '/KeywordListManager.php';
        }
        if (!class_exists('CacheManager')) {
            require_once __DIR__ . '/CacheManager.php';
        }
        
        $this->domainExtractor = DomainExtractor::getInstance();
        $this->configManager = new DomainConfigManager();
        $this->keywordManager = new KeywordListManager();
        $this->cacheManager = new CacheManager();
    }
    
    /**
     * 获取或创建子域名配置（用于静态资源请求）
     * @param string $currentHost 当前子域名
     * @param string $topDomain 顶级域名
     * @return array|null 配置信息
     */
    public function getOrCreateSubdomainConfig($currentHost, $topDomain) {
        // 读取顶级域名配置
        $topConfig = $this->configManager->getConfig($topDomain);
        if (!$topConfig) {
            error_log("[SubdomainManager] 顶级域名配置不存在: $topDomain");
            return null;
        }
        
        // 获取分组配置
        $groupConfig = $this->getGroupConfigByDomain($topDomain);
        if (!$groupConfig) {
            error_log("[SubdomainManager] 未找到分组配置: $topDomain");
            return null;
        }
        
        // 提取子域名前缀
        $subdomain = $currentHost;
        
        // 检查子域名配置模式
        $subdomainConfig = $groupConfig['subdomain_config'] ?? [];
        $mode = $subdomainConfig['mode'] ?? 'independent';
        
        error_log("[SubdomainManager-静态资源] 子域名配置模式: $mode");
        
        switch ($mode) {
            case 'independent':
                // 独立配置模式 - 查找或生成独立配置
                return $this->getIndependentConfig($subdomain, $topDomain, $topConfig, $groupConfig);
                
            case 'fixed_top':
                // 固定顶级模式 - 使用父域名配置
                return [
                    'success' => true,
                    'mode' => 'fixed_top',
                    'config' => $topConfig,
                    'domain' => $subdomain
                ];
                
            case 'dynamic_top':
                // 动态顶级模式 - 为子域名分配不同的克隆源
                $subConfig = $topConfig;
                $mirrors = $this->getAvailableMirrors($topConfig);
                if (!empty($mirrors)) {
                    $hash = crc32($subdomain);
                    $mirrorIndex = $hash % count($mirrors);
                    $selectedMirror = $mirrors[$mirrorIndex];
                    $subConfig['mirror_id'] = $selectedMirror['id'];
                    $subConfig['source_domain'] = $selectedMirror['source_domain'];
                }
                
                return [
                    'success' => true,
                    'mode' => 'dynamic_top',
                    'config' => $subConfig,
                    'domain' => $subdomain
                ];
                
            default:
                error_log("[SubdomainManager] 未知模式: $mode");
                return null;
        }
    }
    
    /**
     * 处理泛二级域名请求（路由器调用）- 使用新的模式处理器
     * @param string $currentHost 当前子域名
     * @param string $topDomain 顶级域名
     * @param string $requestUri 请求URI
     * @return bool 是否成功处理
     */
    public function handleSubdomainRequest($currentHost, $topDomain, $requestUri) {
        error_log("=== SubdomainManager 路由到新架构 ===");
        error_log("[SubdomainManager] 当前域名: $currentHost");
        error_log("[SubdomainManager] 顶级域名: $topDomain");
        error_log("[SubdomainManager] 请求URI: $requestUri");
        
        // 读取顶级域名配置
        $topConfig = $this->configManager->getConfig($topDomain);
        if (!$topConfig) {
            error_log("[SubdomainManager] 顶级域名配置不存在: $topDomain");
            return false;
        }
        
        // 读取分组配置
        $groupConfig = $this->getGroupConfigByDomain($topDomain);
        if (!$groupConfig) {
            error_log("[SubdomainManager] 未找到分组配置: $topDomain");
            return false;
        }
        
        if (!isset($groupConfig['subdomain_config'])) {
            error_log("[SubdomainManager] 分组配置中没有 subdomain_config");
            return false;
        }
        
        // 使用新的路由器分发到对应模式处理器
        if (!class_exists('SubdomainRouter')) {
            require_once __DIR__ . '/SubdomainRouter.php';
        }
        
        $router = new SubdomainRouter();
        return $router->route($currentHost, $topDomain, $topConfig, $groupConfig, $requestUri);
    }
    
    /**
     * 根据域名获取分组配置
     */
    private function getGroupConfigByDomain($domain) {
        $groupsFile = $this->base_dir . '/data/domain_groups/groups.json';
        if (!file_exists($groupsFile)) {
            return null;
        }
        
        $groups = json_decode(file_get_contents($groupsFile), true);
        if (!$groups) {
            return null;
        }
        
        // 查找包含此域名的分组
        foreach ($groups as $groupId => $group) {
            $domains = $group['domains'] ?? [];
            foreach ($domains as $domainInfo) {
                $groupDomain = is_array($domainInfo) ? ($domainInfo['domain'] ?? '') : $domainInfo;
                if ($groupDomain === $domain) {
                    return $group;
                }
            }
        }
        
        return null;
    }
    
    /**
     * 获取独立配置（独立配置模式）
     */
    private function getIndependentConfig($subdomain, $topDomain, $topConfig, $groupConfig) {
        // 查找已存在的配置文件（支持前缀）
        $configFile = $this->findSubdomainConfigFile($subdomain);
        
        if ($configFile && file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            if ($config) {
                error_log("[SubdomainManager-静态资源] 使用已存在的独立配置: $subdomain");
                return [
                    'success' => true,
                    'mode' => 'independent',
                    'config' => $config,
                    'domain' => $subdomain
                ];
            }
        }
        
        // 配置不存在，返回 null（让模式处理器自己生成）
        error_log("[SubdomainManager-静态资源] 独立配置不存在，等待模式处理器生成: $subdomain");
        return null;
    }
    
    /**
     * 查找子域名配置文件（支持前缀文件名）
     */
    private function findSubdomainConfigFile($subdomain) {
        $domainDir = $this->base_dir . '/data/domain/';
        
        // 方法1：直接查找 subdomain.json
        $directFile = $domainDir . $subdomain . '.json';
        if (file_exists($directFile)) {
            return $directFile;
        }
        
        // 方法2：查找 *.subdomain.json（带分组ID前缀的文件）
        $pattern = $domainDir . '*.' . $subdomain . '.json';
        $matchedFiles = glob($pattern);
        
        if (!empty($matchedFiles)) {
            return $matchedFiles[0];
        }
        
        return null;
    }
    
    /**
     * 获取可用镜像源列表
     */
    private function getAvailableMirrors($topConfig) {
        $materialsFile = $topConfig['material_file'] ?? '';
        
        if (!$materialsFile) {
            error_log("[SubdomainManager] 顶级配置中没有 material_file");
            return [];
        }
        
        $materialsPath = $this->base_dir . '/data/mirrors/' . $materialsFile;
        
        if (!file_exists($materialsPath)) {
            error_log("[SubdomainManager] material 文件不存在: $materialsPath");
            return [];
        }
        
        $materials = json_decode(file_get_contents($materialsPath), true);
        
        if (empty($materials)) {
            error_log("[SubdomainManager] material 文件为空");
            return [];
        }
        
        return $materials;
    }
}
