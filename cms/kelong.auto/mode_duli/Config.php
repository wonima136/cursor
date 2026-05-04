<?php
/**
 * 独立配置模式 - 配置管理
 */
class DuliConfig {
    private $base_dir;
    private $domainDir;
    private $logger;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->domainDir = $this->base_dir . '/data/domain';
    }
    
    public function setLogger($logger) {
        $this->logger = $logger;
    }
    
    /**
     * 获取或创建子域名配置
     */
    public function getOrCreate($subdomain, $topDomain, $groupConfig) {
        if ($this->logger) $this->logger->info("      配置模块: 查找配置 {$subdomain}");
        
        // 1. 先查找已有配置（支持前缀文件名）
        $existingConfig = $this->findExisting($subdomain, $topDomain);
        
        if ($existingConfig) {
            if ($this->logger) $this->logger->info("      找到已有配置");
            return $existingConfig;
        }
        
        // 2. 生成新配置
        if ($this->logger) $this->logger->info("      配置不存在，开始生成新配置");
        return $this->generate($subdomain, $topDomain, $groupConfig);
    }
    
    /**
     * 查找已有配置
     */
    private function findExisting($subdomain, $topDomain) {
        // 直接文件名
        $directFile = "$subdomain.json";
        if (file_exists($this->domainDir . '/' . $directFile)) {
            return $this->load($directFile);
        }
        
        // 前缀文件名（如：groupId.subdomain.json）
        $pattern = "*.$subdomain.json";
        $files = glob($this->domainDir . '/' . $pattern);
        
        if (!empty($files)) {
            $filename = basename($files[0]);
            return $this->load($filename);
        }
        
        return null;
    }
    
    /**
     * 加载配置文件
     */
    private function load($filename) {
        $filepath = $this->domainDir . '/' . $filename;
        $content = file_get_contents($filepath);
        
        if (!$content) {
            return null;
        }
        
        $config = json_decode($content, true);
        
        if (!$config) {
            return null;
        }
        
        // 添加文件名信息（用于保存时保持原文件名）
        $config['_config_file'] = $filename;
        
        return $config;
    }
    
    /**
     * 生成新配置
     */
    private function generate($subdomain, $topDomain, $groupConfig) {
        require_once $this->base_dir . '/inc/KeywordListManager.php';
        require_once $this->base_dir . '/inc/DomainConfigManager.php';
        
        if ($this->logger) {
            $this->logger->debug("        分组配置检查:");
            $this->logger->debug("          - 分组名称: " . ($groupConfig['name'] ?? '未知'));
            $this->logger->debug("          - 子域名模式: " . ($groupConfig['subdomain_config']['mode'] ?? '未设置'));
            $this->logger->debug("          - 克隆源数量: " . count($groupConfig['clone_sources'] ?? []));
        }
        
        // 读取顶级域名配置（获取词根）
        $configManager = new DomainConfigManager();
        $topConfig = $configManager->getConfig($topDomain);
        
        // 兼容旧格式和新格式
        if (isset($groupConfig['tdk_config'])) {
            // 新格式：tdk_config
            $tdkConfig = $groupConfig['tdk_config'];
            $tdkMode = $tdkConfig['mode'] ?? 'random';
        } else {
            // 旧格式：type 和 value
            $tdkMode = $groupConfig['type'] ?? 'random';
            $tdkConfig = [
                'mode' => $tdkMode,
                'value' => $groupConfig['value'] ?? '',
                'root_keyword' => $groupConfig['value'] ?? '',
                'title_list_id' => null
            ];
            
            // 自定义标题模式
            if ($tdkMode === 'custom_title') {
                $tdkConfig['custom_title'] = $groupConfig['value'] ?? '';
            }
        }
        
        if ($this->logger) $this->logger->debug("        TDK配置:");
        if ($this->logger) $this->logger->debug("          - 模式: {$tdkMode}");
        
        // 生成TDK（传递顶级配置，以便使用其词根）
        $tdk = $this->generateTDK($tdkMode, $tdkConfig, $topConfig, $groupConfig);
        
        if ($this->logger) {
            $this->logger->debug("        TDK生成成功");
            $this->logger->debug("        标题: " . ($tdk['title'] ?? 'N/A'));
        }
        
        // 获取随机镜像ID（独立配置模式）
        $mirrorResult = $this->getFirstMirror($topDomain);
        
        if (!$mirrorResult) {
            if ($this->logger) $this->logger->error("        ❌ 无可用镜像");
            return null;
        }
        
        // 处理返回值：可能是字符串（mirror_id）或数组（包含 mirror_id 和 source_domain）
        if (is_array($mirrorResult)) {
            $mirrorId = $mirrorResult['mirror_id'];
            $sourceDomain = $mirrorResult['source_domain'] ?? null;
            
            if ($this->logger) {
                $this->logger->info("        ✓ 找到镜像: {$mirrorId}");
                $this->logger->debug("        源站域名（从顶级配置）: {$sourceDomain}");
            }
        } else {
            $mirrorId = $mirrorResult;
            
            if ($this->logger) $this->logger->info("        ✓ 找到镜像: {$mirrorId}");
            
            // 从镜像配置文件获取源站域名
            $sourceDomain = $this->getSourceDomainFromMirror($mirrorId);
            
            if ($this->logger) {
                if ($sourceDomain) {
                    $this->logger->debug("        源站域名（从镜像配置）: {$sourceDomain}");
                } else {
                    $this->logger->warning("        ⚠ 无法获取源站域名");
                }
            }
        }
        
        $config = [
            'tdk' => $tdk,
            'root' => $tdk['root'] ?? '',
            'mode' => 'mirror',
            'config_mode' => 'independent',
            'mirror_id' => $mirrorId,
            'source_domain' => $sourceDomain ?: '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // 保存配置
        if ($this->logger) $this->logger->info("        保存配置到文件...");
        $this->save($subdomain, $config);
        
        if ($this->logger) $this->logger->info("        ✓ 配置生成完成");
        
        return $config;
    }
    
    /**
     * 生成TDK
     */
    private function generateTDK($mode, $tdkConfig, $topConfig = null, $groupConfig = null) {
        $keywordManager = new KeywordListManager();
        
        switch ($mode) {
            case 'random':
                // 随机根词 - 使用顶级域名配置中的词根（保持主题统一）
                if ($topConfig && !empty($topConfig['root'])) {
                    $root = $topConfig['root'];
                    if ($this->logger) $this->logger->debug("          使用顶级域名词根: {$root}");
                } else {
                    // 如果顶级配置没有词根，才随机选择
                    $root = $this->selectRandomRoot();
                    if ($this->logger) $this->logger->warning("          顶级域名无词根，随机选择: {$root}");
                }
                $tdk = $keywordManager->generateTDK($root, '');
                $tdk['root'] = $root;  // 保存词根
                return $tdk;
                
            case 'fixed':
            case 'root':  // 兼容旧格式
                // 固定根词 - 优先使用TDK配置中的词根，否则使用顶级域名的词根
                $root = $tdkConfig['root_keyword'] ?? $tdkConfig['value'] ?? '';
                if (empty($root) && $topConfig && !empty($topConfig['root'])) {
                    $root = $topConfig['root'];
                    if ($this->logger) $this->logger->debug("          TDK配置无词根，使用顶级域名词根: {$root}");
                } else {
                    if ($this->logger) $this->logger->debug("          使用固定词根: {$root}");
                }
                $tdk = $keywordManager->generateTDK($root, '');
                $tdk['root'] = $root;  // 保存词根
                return $tdk;
                
            case 'custom_title':
                // 🆕 兼容旧版：单个标题（直接使用）
                $customTitle = $tdkConfig['custom_title'] ?? $tdkConfig['value'] ?? '';
                if (!empty($customTitle)) {
                    if ($this->logger) $this->logger->debug("          使用自定义标题: {$customTitle}");
                    
                    // 解析标题中的关键词（使用下划线分隔）
                    $keywords = explode('_', $customTitle);
                    $keywordsStr = implode(',', $keywords);
                    
                    return [
                        'title' => $customTitle,
                        'keywords' => $keywordsStr,
                        'description' => $customTitle,
                        'root' => ''  // 标题列表模式没有词根
                    ];
                }
                
                // 如果标题为空，降级为随机
                if ($this->logger) $this->logger->warning("          自定义标题为空，降级为随机词根");
                $root = $this->selectRandomRoot();
                $tdk = $keywordManager->generateTDK($root, '');
                $tdk['root'] = $root;
                return $tdk;
                
            default:
                $root = $this->selectRandomRoot();
                $tdk = $keywordManager->generateTDK($root, '');
                $tdk['root'] = $root;  // 保存词根
                return $tdk;
        }
    }
    
    /**
     * 随机选择词根
     */
    private function selectRandomRoot() {
        $rootsFile = $this->base_dir . '/data/data_key/统计词根.txt';
        
        if (!file_exists($rootsFile)) {
            if ($this->logger) $this->logger->warning("          词根文件不存在: {$rootsFile}");
            return '默认词根';
        }
        
        $roots = file($rootsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $roots = array_filter(array_map('trim', $roots));
        
        if (empty($roots)) {
            if ($this->logger) $this->logger->warning("          词根文件为空");
            return '默认词根';
        }
        
        return $roots[array_rand($roots)];
    }
    
    /**
     * 获取随机镜像（使用通用镜像选择器）
     */
    private function getFirstMirror($topDomain) {
        if ($this->logger) $this->logger->info("        查找可用镜像（独立模式）...");
        
        // 使用通用镜像选择器
        require_once $this->base_dir . '/inc/MirrorSelector.php';
        $selector = new MirrorSelector();
        $selector->setLogger($this->logger);
        
        $result = $selector->selectRandom();
        
        if (!$result) {
            if ($this->logger) {
                $this->logger->error("        ❌ 无可用镜像");
                $this->logger->error("        → 请先在后台克隆一些站点，生成镜像数据");
            }
            return null;
        }
        
        return $result['mirror_id'];
    }
    
    /**
     * 从镜像ID获取源站域名
     */
    private function getSourceDomainFromMirror($mirrorId) {
        $mirrorConfigFile = $this->base_dir . '/data/mirrors/' . $mirrorId . '/config.json';
        
        if (!file_exists($mirrorConfigFile)) {
            if ($this->logger) $this->logger->warning("        镜像配置文件不存在: {$mirrorConfigFile}");
            return null;
        }
        
        $mirrorConfig = json_decode(file_get_contents($mirrorConfigFile), true);
        
        if (empty($mirrorConfig)) {
            if ($this->logger) $this->logger->warning("        镜像配置文件为空或格式错误");
            return null;
        }
        
        return $mirrorConfig['source_domain'] ?? null;
    }
    
    /**
     * 保存配置
     */
    public function save($subdomain, $config) {
        // 如果配置中有原文件名，使用原文件名
        $filename = $config['_config_file'] ?? "$subdomain.json";
        unset($config['_config_file']); // 移除内部字段
        
        $filepath = $this->domainDir . '/' . $filename;
        
        // 添加保护：确保配置不为空
        if (empty($config) || !isset($config['mirror_id'])) {
            if ($this->logger) {
                $this->logger->error("        ❌ 配置为空或缺少必要字段，拒绝保存");
            }
            return false;
        }
        
        $config['updated_at'] = date('Y-m-d H:i:s');
        
        // 先写入临时文件，再重命名（原子操作，防止写入失败导致文件损坏）
        $tempFile = $filepath . '.tmp';
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if ($json === false) {
            if ($this->logger) {
                $this->logger->error("        ❌ JSON编码失败");
            }
            return false;
        }
        
        $result = file_put_contents($tempFile, $json);
        
        if ($result === false) {
            if ($this->logger) {
                $this->logger->error("        ❌ 写入临时文件失败");
            }
            return false;
        }
        
        // 重命名临时文件为正式文件
        if (!rename($tempFile, $filepath)) {
            if ($this->logger) {
                $this->logger->error("        ❌ 重命名文件失败");
            }
            @unlink($tempFile); // 清理临时文件
            return false;
        }
        
        if ($this->logger) {
            $this->logger->debug("        ✓ 配置保存成功: $filename");
        }
        
        return true;
    }
}
