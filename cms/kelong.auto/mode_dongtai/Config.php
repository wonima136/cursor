<?php
/**
 * 动态顶级模式 - 配置管理
 * 为子域名创建独立配置，继承顶级域名的TDK，但分配不同的镜像
 */
class DongtaiConfig {
    private $base_dir;
    private $domainDir;
    private $logger;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->domainDir = $this->base_dir . '/data/domain';
        
        if (!is_dir($this->domainDir)) {
            mkdir($this->domainDir, 0755, true);
        }
    }
    
    public function setLogger($logger) {
        $this->logger = $logger;
    }
    
    /**
     * 获取或创建子域名配置
     */
    public function getOrCreate($subdomain, $topDomain, $topConfig, $groupConfig) {
        if ($this->logger) $this->logger->info("      配置模块: 查找配置 {$subdomain}");
        
        // 1. 查找已有配置
        $existingConfig = $this->findExisting($subdomain);
        if ($existingConfig) {
            if ($this->logger) $this->logger->info("      配置已存在，直接使用");
            return $existingConfig;
        }
        
        // 2. 生成新配置
        if ($this->logger) $this->logger->info("      配置不存在，开始生成新配置");
        return $this->generate($subdomain, $topDomain, $topConfig, $groupConfig);
    }
    
    /**
     * 查找已有配置
     */
    private function findExisting($subdomain) {
        $directFile = "$subdomain.json";
        if (file_exists($this->domainDir . '/' . $directFile)) {
            return $this->load($directFile);
        }
        
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
        
        $config['_config_file'] = $filename;
        
        return $config;
    }
    
    /**
     * 生成新配置（使用顶级域名的词根生成独立TDK，独立分配镜像）
     */
    private function generate($subdomain, $topDomain, $topConfig, $groupConfig) {
        if ($this->logger) {
            $this->logger->debug("        生成配置:");
            $this->logger->debug("          - 子域名: {$subdomain}");
            $this->logger->debug("          - 顶级域名: {$topDomain}");
        }
        
        // 1. 获取顶级域名的词根
        $root = $topConfig['root'] ?? '';
        
        if (empty($root)) {
            if ($this->logger) $this->logger->warning("        ⚠ 顶级域名无词根，使用默认词根");
            $root = '默认词根';
        }
        
        if ($this->logger) $this->logger->debug("        顶级词根: {$root}");
        
        // 2. 直接引用顶级域名的TDK（不生成新的）
        if ($this->logger) $this->logger->debug("        引用顶级域名TDK...");
        
        // 使用引用格式：顶级域名.json
        $tdkReference = $topDomain . '.json';
        $tdk = [
            'title' => $tdkReference,
            'keywords' => $tdkReference,
            'description' => $tdkReference
        ];
        
        if ($this->logger) {
            $this->logger->debug("        ✓ TDK引用: {$tdkReference}");
        }
        
        // 3. 根据子域名哈希分配镜像
        $mirrorResult = $this->assignMirrorByHash($subdomain, $groupConfig);
        
        if (!$mirrorResult) {
            if ($this->logger) $this->logger->error("        ❌ 无可用镜像");
            return null;
        }
        
        if ($this->logger) {
            $this->logger->info("        ✓ 分配镜像: {$mirrorResult['mirror_id']}");
            if (!empty($mirrorResult['source_domain'])) {
                $this->logger->debug("        源站域名: {$mirrorResult['source_domain']}");
            } else {
                $this->logger->warning("        ⚠️  源站域名为空，镜像配置可能缺少 source_domain/target_domain 字段");
            }
        }
        
        // 4. 构建配置（使用TDK引用，统一使用 source_domain 字段）
        $config = [
            'tdk' => $tdk,  // TDK引用格式：{topDomain}.json
            'top_domain' => $topDomain,  // 保存顶级域名
            'mode' => 'mirror',
            'config_mode' => 'dynamic_top',
            'mirror_id' => $mirrorResult['mirror_id'],
            'source_domain' => $mirrorResult['source_domain'] ?? '',  // 统一使用 source_domain
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // 5. 保存配置
        if ($this->logger) $this->logger->info("        保存配置到文件...");
        
        if (!$this->save($subdomain, $config)) {
            if ($this->logger) $this->logger->error("        ❌ 配置保存失败");
            return null;
        }
        
        if ($this->logger) $this->logger->info("        ✓ 配置生成完成");
        
        return $config;
    }
    
    /**
     * 从词根生成TDK
     */
    private function generateTDKFromRoot($root, $domain) {
        try {
            if ($this->logger) $this->logger->debug("          调用 KeywordListManager...");
            
            require_once $this->base_dir . '/inc/KeywordListManager.php';
            
            $keywordManager = new KeywordListManager();
            
            if ($this->logger) $this->logger->debug("          执行 generateTDK('{$root}', '{$domain}')...");
            
            // 使用词根生成TDK（传入词根和域名）
            $tdk = $keywordManager->generateTDK($root, $domain);
            
            if ($this->logger) {
                if (empty($tdk)) {
                    $this->logger->error("          ❌ generateTDK 返回空");
                } else {
                    $this->logger->debug("          ✓ generateTDK 返回成功");
                }
            }
            
            return $tdk;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("          ❌ 生成TDK异常: " . $e->getMessage());
                $this->logger->error("          错误位置: " . $e->getFile() . ":" . $e->getLine());
            }
            return null;
        }
    }
    
    /**
     * 随机分配镜像（使用 array_rand 方法）
     */
    private function assignMirrorByHash($subdomain, $groupConfig) {
        // 1. 获取所有可用镜像
        $availableMirrors = $this->getAvailableMirrors($groupConfig);
        
        if (empty($availableMirrors)) {
            if ($this->logger) $this->logger->error("        ❌ 无可用镜像");
            return null;
        }
        
        if ($this->logger) $this->logger->debug("        可用镜像数: " . count($availableMirrors));
        
        // 2. 使用 array_rand() 随机选择镜像（方法1：抽奖盒子法）
        $randomIndex = array_rand($availableMirrors);
        $mirrorId = $availableMirrors[$randomIndex];
        
        if ($this->logger) $this->logger->debug("        随机分配: {$subdomain} → index {$randomIndex} → {$mirrorId}");
        
        // 3. 获取源站域名
        $sourceDomain = $this->getMirrorSourceDomain($mirrorId);
        
        return [
            'mirror_id' => $mirrorId,
            'source_domain' => $sourceDomain
        ];
    }
    
    /**
     * 获取可用镜像列表
     */
    private function getAvailableMirrors($groupConfig) {
        // 1. 优先从分组配置的 clone_sources 获取
        if (!empty($groupConfig['clone_sources'])) {
            if ($this->logger) $this->logger->debug("        从分组配置获取镜像列表");
            return $groupConfig['clone_sources'];
        }
        
        // 2. 从 mirrors 目录扫描
        if ($this->logger) $this->logger->debug("        从 mirrors 目录扫描");
        
        $mirrorsDir = $this->base_dir . '/data/mirrors';
        if (!is_dir($mirrorsDir)) {
            return [];
        }
        
        $mirrors = [];
        $dirs = @scandir($mirrorsDir);
        
        if ($dirs === false) {
            return [];
        }
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            
            if (strpos($dir, 'mirror_') === 0) {
                $configFile = $mirrorsDir . '/' . $dir . '/config.json';
                if (file_exists($configFile)) {
                    $mirrors[] = $dir;
                }
            }
        }
        
        return $mirrors;
    }
    
    /**
     * 获取镜像的源站域名
     * 读取时兼容 target_domain（向后兼容），但始终返回 source_domain
     */
    private function getMirrorSourceDomain($mirrorId) {
        $configPath = $this->base_dir . '/data/mirrors/' . $mirrorId . '/config.json';
        
        if (!file_exists($configPath)) {
            if ($this->logger) $this->logger->warning("          镜像配置不存在: {$configPath}");
            return '';
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        
        if (!$config) {
            if ($this->logger) $this->logger->error("          镜像配置解析失败: {$configPath}");
            return '';
        }
        
        // 读取时兼容两种字段名：优先 source_domain，备用 target_domain
        $sourceDomain = $config['source_domain'] ?? ($config['target_domain'] ?? '');
        
        if ($this->logger) {
            if (empty($sourceDomain)) {
                $this->logger->warning("          ⚠️  镜像配置中无 source_domain 或 target_domain: {$mirrorId}");
            } else {
                $fieldName = isset($config['source_domain']) ? 'source_domain' : 'target_domain';
                $this->logger->debug("          ✓ 读取源站域名: {$sourceDomain} (字段: {$fieldName})");
            }
        }
        
        return $sourceDomain;
    }
    
    /**
     * 保存配置（原子写入）
     */
    public function save($subdomain, $config) {
        $filename = $config['_config_file'] ?? "$subdomain.json";
        unset($config['_config_file']);
        
        $filepath = $this->domainDir . '/' . $filename;
        $tempFilepath = $filepath . '.tmp';
        
        $config['updated_at'] = date('Y-m-d H:i:s');
        
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if (file_put_contents($tempFilepath, $json) === false) {
            if ($this->logger) $this->logger->error("        ❌ 写入临时配置文件失败: {$tempFilepath}");
            return false;
        }
        
        if (rename($tempFilepath, $filepath) === false) {
            if ($this->logger) $this->logger->error("        ❌ 重命名配置文件失败: {$tempFilepath} -> {$filepath}");
            unlink($tempFilepath);
            return false;
        }
        
        if ($this->logger) $this->logger->info("        ✓ 配置保存成功: {$filename}");
        return true;
    }
}
