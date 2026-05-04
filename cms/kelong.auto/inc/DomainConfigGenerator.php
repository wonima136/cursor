<?php
/**
 * 域名配置自动生成器
 * 功能：访问时自动检测并生成域名配置文件
 */

class DomainConfigGenerator {
    private $base_dir;
    private $data_dir;
    private $material_dir;
    private $indexManager;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->data_dir = __DIR__ . '/domain/';
        $this->material_dir = __DIR__ . '/config/';
        
        // 确保目录存在
        if (!is_dir($this->data_dir)) {
            mkdir($this->data_dir, 0755, true);
        }
        
        // 初始化索引管理器
        require_once __DIR__ . '/DomainIndexManager.php';
        $this->indexManager = new DomainIndexManager();
    }
    
    /**
     * 更新域名索引
     */
    private function updateDomainIndex($domain, $targetDomain, $targetTitle, $title, $keywords, $description, $root = '', $groupId = '', $mode = 'independent') {
        try {
            $this->indexManager->addOrUpdateDomain($domain, [
                'target' => $targetDomain,
                'target_title' => $targetTitle,
                'title' => $title,
                'keywords' => $keywords,
                'description' => $description,
                'root' => $root,
                'group_id' => $groupId,
                'mode' => $mode
            ]);
        } catch (Exception $e) {
            error_log("更新域名索引失败: {$domain} - " . $e->getMessage());
        }
    }
    
    /**
     * 提取顶级域名
     */
    public function extractTopDomain($domain) {
        // 加载双后缀列表
        $suffixFile = __DIR__ . '/../domain_suffixes.php';
        if (!file_exists($suffixFile)) {
            // 如果文件不存在，使用默认列表
            $double_suffixes = [
                '.com.cn', '.net.cn', '.org.cn', '.gov.cn', '.edu.cn',
                '.co.uk', '.co.jp', '.co.kr', '.com.hk', '.com.tw',
                '.net.au', '.com.au', '.org.au'
            ];
        } else {
            $suffixes = include($suffixFile);
            $double_suffixes = $suffixes['double_suffixes'];
        }
        
        $domain = strtolower($domain);
        $parts = explode('.', $domain);
        $count = count($parts);
        
        if ($count <= 2) {
            return $domain;
        }
        
        // 检查双后缀
        $lastTwo = '.' . $parts[$count - 2] . '.' . $parts[$count - 1];
        foreach ($double_suffixes as $suffix) {
            if ($lastTwo === $suffix) {
                // 双后缀域名，返回最后3段
                return implode('.', array_slice($parts, -3));
            }
        }
        
        // 单后缀域名，返回最后2段
        return implode('.', array_slice($parts, -2));
    }
    
    /**
     * 检查配置文件是否存在
     */
    public function configExists($domain, $isIndependent = false) {
        if ($isIndependent) {
            // 独立模式：检查完整域名配置
            $file = $this->data_dir . $domain . '.txt';
        } else {
            // 统一模式：检查顶级域名配置
            $topDomain = $this->extractTopDomain($domain);
            $file = $this->data_dir . $topDomain . '.txt';
        }
        
        return file_exists($file);
    }
    
    /**
     * 生成配置文件（基于词根和关键词库）
     */
    public function generateConfig($domain, $isIndependent = false) {
        try {
            // 优先从公司信息.csv读取目标站点（7402个域名）
            $csvFile = $this->base_dir . '/data/data_key/公司信息.csv';
            $targetDomain = '';
            $targetTitle = '';
            
            if (file_exists($csvFile)) {
                error_log("从公司信息.csv读取目标站点");
                $csvLines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                
                if (count($csvLines) > 1) {
                    // 跳过表头，随机选择一行
                    $randomIndex = rand(1, count($csvLines) - 1);
                    $line = $csvLines[$randomIndex];
                    $parts = str_getcsv($line);
                    
                    if (count($parts) >= 1) {
                        $targetDomain = 'www.' . trim($parts[0]);
                        $targetTitle = isset($parts[1]) ? trim($parts[1]) : trim($parts[0]);
                        error_log("选择目标: {$targetDomain}");
                    }
                }
            }
            
            // 如果CSV读取失败，使用备用方案
            if (empty($targetDomain)) {
                error_log("CSV读取失败，使用targets.txt");
                $targets = $this->readMaterialFile('targets.txt');
                if (empty($targets)) {
                    throw new Exception('目标站点物料文件为空');
                }
                
                $target = $targets[array_rand($targets)];
                $targetParts = explode('|', $target);
                $targetDomain = trim($targetParts[0]);
                $targetTitle = isset($targetParts[1]) ? trim($targetParts[1]) : '';
            }
            
            // 读取词根列表
            $roots = $this->readLargeFile('统计词根.txt');
            if (empty($roots)) {
                error_log('词根文件为空，使用备用方案');
                // 使用旧的简单方案
                return $this->generateConfigSimple($domain, $mode, $targetDomain, $targetTitle);
            }
            
            // 随机选择一个词根
            $selectedRoot = $roots[array_rand($roots)];
            error_log("选择词根: {$selectedRoot}");
            
            // 优先使用分词文件（性能优化）
            $matchedKeywords = $this->getKeywordsByRootFromSplitFiles($selectedRoot);
            
            if (!empty($matchedKeywords)) {
                error_log("✓ 使用分词文件: " . count($matchedKeywords) . " 个关键词");
            } else {
                // 分词文件不存在，使用原始方法
                error_log("分词文件不存在，使用原始方法");
                
                // 读取主关键词库
                $mainKeywords = $this->readLargeFile('key.txt');
                error_log("主关键词库: " . count($mainKeywords) . " 个");
                
                // 查找包含该词根的关键词
                $matchedKeywords = $this->findKeywordsByRoot($selectedRoot, $mainKeywords);
                error_log("匹配到关键词: " . count($matchedKeywords) . " 个");
                
                // 如果匹配的关键词不足，使用备用关键词库
                if (count($matchedKeywords) < 5) {
                    error_log("关键词不足，读取备用库");
                    $backupKeywords = $this->readLargeFile('app备用.txt');
                    $backupMatches = $this->findKeywordsByRoot($selectedRoot, $backupKeywords);
                    $matchedKeywords = array_merge($matchedKeywords, $backupMatches);
                    error_log("补充后关键词: " . count($matchedKeywords) . " 个");
                }
                
                // 如果还是不足，使用所有关键词
                if (count($matchedKeywords) < 5) {
                    error_log("关键词仍不足，使用随机关键词");
                    shuffle($mainKeywords);
                    $matchedKeywords = array_slice($mainKeywords, 0, 20);
                }
            }
            
            // 生成TDK（统一使用8个关键词）
            $title = $this->combineKeywords($matchedKeywords, 8);
            $keywordsStr = str_replace('_', ',', $this->combineKeywords($matchedKeywords, 8));
            // 第6、7、8行都使用相同的关键词内容
            $description = $title;
            
            // 确定保存的域名
            if ($isIndependent) {
                $saveDomain = $domain;
            } else {
                $saveDomain = $this->extractTopDomain($domain);
            }
            
            // 生成配置内容
            $config = $this->buildConfigContent(
                $targetDomain,
                $targetTitle,
                $title,
                $keywordsStr,
                $description
            );
            
            // 保存配置文件
            $configFile = $this->data_dir . $saveDomain . '.txt';
            file_put_contents($configFile, $config);
            
            // 更新索引
            $this->updateDomainIndex(
                $saveDomain,
                $targetDomain,
                $targetTitle,
                $title,
                $keywordsStr,
                $description,
                $selectedRoot,
                '',
                $isIndependent ? 'independent' : 'top'
            );
            
            error_log("配置生成成功: {$saveDomain}");
            
            return [
                'success' => true,
                'domain' => $saveDomain,
                'file' => $configFile,
                'mode' => $mode,
                'root' => $selectedRoot,
                'keyword_count' => count($matchedKeywords)
            ];
            
        } catch (Exception $e) {
            error_log("配置生成失败: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 简单配置生成（备用方案）
     */
    private function generateConfigSimple($domain, $mode, $targetDomain, $targetTitle) {
        try {
            $keywords = $this->readMaterialFile('longTailKeywords.txt');
            
            if (empty($keywords)) {
                throw new Exception('关键词物料文件为空');
            }
            
            // 生成TDK
            $title = $this->generateTitle($keywords);
            $keywordsStr = $this->generateKeywords($keywords);
            $description = $this->generateDescription($keywords);
            
            // 确定保存的域名
            if ($isIndependent) {
                $saveDomain = $domain;
            } else {
                $saveDomain = $this->extractTopDomain($domain);
            }
            
            // 生成配置内容
            $config = $this->buildConfigContent(
                $targetDomain,
                $targetTitle,
                $title,
                $keywordsStr,
                $description
            );
            
            // 保存配置文件
            $configFile = $this->data_dir . $saveDomain . '.txt';
            file_put_contents($configFile, $config);
            
            // 更新索引
            $this->updateDomainIndex(
                $saveDomain,
                $targetDomain,
                $targetTitle,
                $title,
                $keywordsStr,
                $description,
                $selectedRoot,
                '',
                $mode
            );
            
            return [
                'success' => true,
                'domain' => $saveDomain,
                'file' => $configFile,
                'mode' => $mode
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 读取物料文件
     */
    private function readMaterialFile($filename) {
        $file = $this->material_dir . $filename;
        
        if (!file_exists($file)) {
            return [];
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        // 过滤空行和注释行（以#开头）
        $filtered = array_filter(array_map('trim', $lines), function($line) {
            return !empty($line) && $line[0] !== '#';
        });
        
        return array_values($filtered);
    }
    
    /**
     * 从data_key目录读取大文件（词根、关键词库）
     */
    private function readLargeFile($filename) {
        $file = $this->base_dir . '/data/data_key/' . $filename;
        
        if (!file_exists($file)) {
            error_log("文件不存在: {$file}");
            return [];
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        // 过滤空行
        $filtered = array_filter(array_map('trim', $lines), function($line) {
            return !empty($line);
        });
        
        return array_values($filtered);
    }
    
    /**
     * 查找包含指定词根的关键词
     */
    private function findKeywordsByRoot($root, $keywords) {
        $matches = [];
        foreach ($keywords as $keyword) {
            if (mb_strpos($keyword, $root) !== false) {
                $matches[] = $keyword;
            }
        }
        return $matches;
    }
    
    /**
     * 从分词文件中获取关键词（性能优化）
     */
    private function getKeywordsByRootFromSplitFiles($root) {
        // 分词文件目录
        $splitDir = $this->base_dir . '/data/data_key/keywords_by_root/';
        
        if (!is_dir($splitDir)) {
            return [];
        }
        
        // 生成安全的文件名
        $safeRoot = preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '_', $root);
        $splitFile = $splitDir . $safeRoot . '.txt';
        
        if (!file_exists($splitFile)) {
            return [];
        }
        
        // 读取分词文件
        $keywords = file($splitFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_filter(array_map('trim', $keywords));
    }
    
    /**
     * 从关键词列表中随机选择并组合
     */
    private function combineKeywords($keywords, $count = 9) {
        if (empty($keywords)) {
            error_log("combineKeywords: 关键词列表为空");
            return '';
        }
        
        error_log("combineKeywords: 输入" . count($keywords) . "个关键词，需要" . $count . "个");
        
        // 先去重，避免重复关键词
        $keywords = array_unique($keywords);
        $keywords = array_values($keywords); // 重新索引
        
        // 如果去重后关键词不足，重复使用
        $originalCount = count($keywords);
        while (count($keywords) < $count) {
            // 复制原始关键词并追加
            for ($i = 0; $i < $originalCount && count($keywords) < $count; $i++) {
                $keywords[] = $keywords[$i];
            }
        }
        
        // 随机打乱
        shuffle($keywords);
        
        // 取前N个
        $selected = array_slice($keywords, 0, $count);
        
        // 确保有内容
        if (empty($selected)) {
            error_log("combineKeywords: 选择后为空");
            return '';
        }
        
        $result = implode('_', $selected);
        error_log("combineKeywords: 生成结果 = " . $result);
        
        return $result;
    }
    
    /**
     * 生成标题
     */
    private function generateTitle($keywords) {
        $selected = array_rand(array_flip($keywords), min(9, count($keywords)));
        if (!is_array($selected)) {
            $selected = [$selected];
        }
        return implode('_', $selected);
    }
    
    /**
     * 生成关键词
     */
    private function generateKeywords($keywords) {
        $selected = array_rand(array_flip($keywords), min(9, count($keywords)));
        if (!is_array($selected)) {
            $selected = [$selected];
        }
        return implode(',', $selected);
    }
    
    /**
     * 生成描述
     */
    private function generateDescription($keywords) {
        $selected = array_rand(array_flip($keywords), min(14, count($keywords)));
        if (!is_array($selected)) {
            $selected = [$selected];
        }
        return implode('_', $selected);
    }
    
    /**
     * 构建配置文件内容
     */
    private function buildConfigContent($targetDomain, $targetTitle, $title, $keywords, $description) {
        // 提取目标站点的顶级域名
        $targetTop = $this->extractTopDomain($targetDomain);
        
        $config = [];
        $config[] = $targetDomain;                    // 第1行：目标域名
        $config[] = $targetTitle . ',' . $targetTop;  // 第2行：目标关键词
        $config[] = $title . ',' . $targetTop;        // 第3行：替换关键词
        $config[] = '1';                              // 第4行：是否更新首页
        $config[] = '0';                              // 第5行：调试模式
        $config[] = $title;                           // 第6行：网站标题（下划线分隔）
        $config[] = $keywords;                        // 第7行：网站关键词（半角逗号分隔）
        $config[] = $title;                           // 第8行：网站描述（下划线分隔，与第6行相同）
        $config[] = 'hhnnseo';                        // 第9行：其他配置
        $config[] = '0';                              // 第10行：简繁体设置
        
        return implode("\n", $config);
    }
    
    /**
     * 自动生成配置（供index.php调用）
     */
    public function autoGenerate($domain) {
        // 读取配置模式：true=独立模式, false=统一模式
        $isIndependent = $this->getConfigMode();
        
        // 确定有效域名
        $effective_domain = $isIndependent ? $domain : $this->extractTopDomain($domain);
        
        // 检查配置是否存在
        if ($this->configExists($domain, $isIndependent)) {
            return [
                'success' => true,
                'exists' => true,
                'message' => '配置文件已存在',
                'effective_domain' => $effective_domain,
                'mode' => $isIndependent ? 'independent' : 'top'
            ];
        }
        
        // 生成配置
        $result = $this->generateConfig($domain, $isIndependent);
        
        if ($result['success']) {
            $result['generated'] = true;
            $result['message'] = '配置文件已自动生成';
            $result['effective_domain'] = $effective_domain;
        }
        
        return $result;
    }
    
    /**
     * 获取配置模式
     * @return bool true=独立模式(fan), false=统一模式(top)
     */
    public function getConfigMode() {
        $settingsFile = $this->data_dir . '../config_mode.txt';
        
        if (file_exists($settingsFile)) {
            $content = file_get_contents($settingsFile);
            $lines = explode("\n", $content);
            
            // 读取非注释、非空行
            foreach ($lines as $line) {
                $line = trim($line);
                // 跳过注释和空行
                if (empty($line) || $line[0] === '#') {
                    continue;
                }
                // fan = 独立模式（所有二级独立标题）
                if ($line === 'fan' || $line === 'independent' || $line === '1' || $line === 'true') {
                    return true; // 独立模式
                }
                break; // 只读取第一个有效行
            }
        }
        
        return false; // 默认统一模式(top)
    }
    
    /**
     * 设置配置模式
     * @param string $mode 'top'=统一模式, 'fan'=独立模式
     */
    public function setConfigMode($mode) {
        $settingsFile = $this->data_dir . '../config_mode.txt';
        
        // 判断模式
        if ($mode === 'fan' || $mode === 'independent' || $mode === '1' || $mode === 'true' || $mode === true) {
            // 独立模式
            $content = "# 配置模式设置\n";
            $content .= "# 统一模式（所有二级同标题）- 所有子域名使用同一个配置文件\n";
            $content .= "# top\n";
            $content .= "\n";
            $content .= "# 独立模式（所有二级独立标题）- 每个子域名使用独立配置文件\n";
            $content .= "fan\n";
        } else {
            // 统一模式
            $content = "# 配置模式设置\n";
            $content .= "# 统一模式（所有二级同标题）- 所有子域名使用同一个配置文件\n";
            $content .= "top\n";
            $content .= "\n";
            $content .= "# 独立模式（所有二级独立标题）- 每个子域名使用独立配置文件\n";
            $content .= "# fan\n";
        }
        
        return file_put_contents($settingsFile, $content) !== false;
    }
    
    /**
     * 获取所有配置文件列表
     */
    public function getAllConfigs() {
        $configs = [];
        
        if (!is_dir($this->data_dir)) {
            return $configs;
        }
        
        $files = glob($this->data_dir . '*.txt');
        
        foreach ($files as $file) {
            $domain = basename($file, '.txt');
            $configs[] = [
                'domain' => $domain,
                'file' => $file,
                'size' => filesize($file),
                'mtime' => date('Y-m-d H:i:s', filemtime($file)),
                'mtime_unix' => filemtime($file)
            ];
        }
        
        return $configs;
    }
    
    /**
     * 获取配置文件内容
     */
    public function getConfigContent($domain) {
        $file = $this->data_dir . $domain . '.txt';
        
        if (!file_exists($file)) {
            return false;
        }
        
        return file_get_contents($file);
    }
    
    /**
     * 更新配置文件内容
     */
    public function updateConfigContent($domain, $content) {
        try {
            $file = $this->data_dir . $domain . '.txt';
            
            if (file_put_contents($file, $content) === false) {
                throw new Exception('写入文件失败');
            }
            
            return [
                'success' => true,
                'message' => '配置已更新'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 删除配置文件
     */
    public function deleteConfig($domain) {
        try {
            $file = $this->data_dir . $domain . '.txt';
            
            if (!file_exists($file)) {
                throw new Exception('配置文件不存在');
            }
            
            if (!unlink($file)) {
                throw new Exception('删除文件失败');
            }
            
            return [
                'success' => true,
                'message' => '配置已删除'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 使用指定词根生成配置（用于分组批量生成）
     */
    public function generateConfigWithRoot($domain, $root, $mode = 'independent') {
        try {
            // 选择克隆源 - 从公司信息.csv读取
            $csvFile = $this->base_dir . '/data/data_key/公司信息.csv';
            $targetDomain = '';
            $targetTitle = '';
            
            if (file_exists($csvFile)) {
                $csvLines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                
                if (count($csvLines) > 1) {
                    // 跳过表头，随机选择一行
                    $randomIndex = rand(1, count($csvLines) - 1);
                    $line = $csvLines[$randomIndex];
                    $parts = str_getcsv($line);
                    
                    if (count($parts) >= 1) {
                        $targetDomain = 'www.' . trim($parts[0]);
                        $targetTitle = isset($parts[1]) ? trim($parts[1]) : $targetDomain;
                    }
                }
            }
            
            // 如果CSV读取失败，使用备用方法
            if (empty($targetDomain)) {
                $targetsFile = $this->base_dir . '/data/data_key/targets.txt';
                if (file_exists($targetsFile)) {
                    $targets = array_filter(array_map('trim', file($targetsFile, FILE_IGNORE_NEW_LINES)));
                    if (!empty($targets)) {
                        $targetDomain = $targets[array_rand($targets)];
                        $targetTitle = $targetDomain;
                    }
                }
            }
            
            if (empty($targetDomain)) {
                throw new Exception('无法获取克隆源');
            }
            
            error_log("使用指定词根生成配置: 域名={$domain}, 词根={$root}");
            
            // 从分词文件或主关键词库获取关键词
            $matchedKeywords = [];
            $keywordsByRootDir = $this->base_dir . '/data/data_key/keywords_by_root/';
            $rootKeywordFile = $keywordsByRootDir . $root . '.txt';
            
            if (is_dir($keywordsByRootDir) && file_exists($rootKeywordFile)) {
                $matchedKeywords = file($rootKeywordFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $matchedKeywords = array_filter(array_map('trim', $matchedKeywords));
                error_log("✓ 使用分词文件: " . count($matchedKeywords) . " 个关键词");
            } else {
                // 使用原始方法
                $mainKeywords = $this->readLargeFile('key.txt');
                $matchedKeywords = $this->findKeywordsByRoot($root, $mainKeywords);
                
                if (count($matchedKeywords) < 5) {
                    $backupKeywords = $this->readLargeFile('app备用.txt');
                    $backupMatches = $this->findKeywordsByRoot($root, $backupKeywords);
                    $matchedKeywords = array_merge($matchedKeywords, $backupMatches);
                }
            }
            
            if (count($matchedKeywords) < 5) {
                error_log("关键词不足，使用随机关键词");
                $mainKeywords = $this->readLargeFile('key.txt');
                shuffle($mainKeywords);
                $matchedKeywords = array_slice($mainKeywords, 0, 20);
            }
            
            // 生成TDK（8个关键词）
            $title = $this->combineKeywords($matchedKeywords, 8);
            $keywordsStr = str_replace('_', ',', $this->combineKeywords($matchedKeywords, 8));
            $description = $title;
            
            // 确定保存的域名
            $isIndependent = ($mode === 'independent');
            if ($isIndependent) {
                $saveDomain = $domain;
            } else {
                $saveDomain = $this->extractTopDomain($domain);
            }
            
            // 生成配置内容
            $config = $this->buildConfigContent(
                $targetDomain,
                $targetTitle,
                $title,
                $keywordsStr,
                $description
            );
            
            // 保存配置文件
            $configFile = $this->data_dir . $saveDomain . '.txt';
            file_put_contents($configFile, $config);
            
            // 更新索引
            $this->updateDomainIndex(
                $saveDomain,
                $targetDomain,
                $targetTitle,
                $title,
                $keywordsStr,
                $description,
                $root,
                '',
                $mode
            );
            
            error_log("配置生成成功: {$saveDomain} (使用词根: {$root})");
            
            return [
                'success' => true,
                'domain' => $saveDomain,
                'file' => $configFile,
                'mode' => $mode,
                'root' => $root,
                'keyword_count' => count($matchedKeywords)
            ];
            
        } catch (Exception $e) {
            error_log("配置生成失败: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 使用自定义标题生成配置（用于分组批量生成）
     */
    public function generateConfigWithTitle($domain, $customTitle, $mode = 'independent') {
        try {
            // 选择克隆源 - 从公司信息.csv读取
            $csvFile = $this->base_dir . '/data/data_key/公司信息.csv';
            $targetDomain = '';
            $targetTitle = '';
            
            if (file_exists($csvFile)) {
                $csvLines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                
                if (count($csvLines) > 1) {
                    // 跳过表头，随机选择一行
                    $randomIndex = rand(1, count($csvLines) - 1);
                    $line = $csvLines[$randomIndex];
                    $parts = str_getcsv($line);
                    
                    if (count($parts) >= 1) {
                        $targetDomain = 'www.' . trim($parts[0]);
                        $targetTitle = isset($parts[1]) ? trim($parts[1]) : $targetDomain;
                    }
                }
            }
            
            // 如果CSV读取失败，使用备用方法
            if (empty($targetDomain)) {
                $targetsFile = $this->base_dir . '/data/data_key/targets.txt';
                if (file_exists($targetsFile)) {
                    $targets = array_filter(array_map('trim', file($targetsFile, FILE_IGNORE_NEW_LINES)));
                    if (!empty($targets)) {
                        $targetDomain = $targets[array_rand($targets)];
                        $targetTitle = $targetDomain;
                    }
                }
            }
            
            if (empty($targetDomain)) {
                throw new Exception('无法获取克隆源');
            }
            
            error_log("使用自定义标题生成配置: 域名={$domain}");
            
            // 使用自定义标题
            $title = $customTitle;
            $keywordsStr = str_replace('_', ',', $customTitle);
            $description = $customTitle;
            
            // 确定保存的域名
            $isIndependent = ($mode === 'independent');
            if ($isIndependent) {
                $saveDomain = $domain;
            } else {
                $saveDomain = $this->extractTopDomain($domain);
            }
            
            // 生成配置内容
            $config = $this->buildConfigContent(
                $targetDomain,
                $targetTitle,
                $title,
                $keywordsStr,
                $description
            );
            
            // 保存配置文件
            $configFile = $this->data_dir . $saveDomain . '.txt';
            file_put_contents($configFile, $config);
            
            // 更新索引
            $this->updateDomainIndex(
                $saveDomain,
                $targetDomain,
                $targetTitle,
                $title,
                $keywordsStr,
                $description,
                '',
                '',
                $mode
            );
            
            error_log("配置生成成功: {$saveDomain} (使用自定义标题)");
            
            return [
                'success' => true,
                'domain' => $saveDomain,
                'file' => $configFile,
                'mode' => $mode,
                'custom_title' => true
            ];
            
        } catch (Exception $e) {
            error_log("配置生成失败: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 从分组配置生成顶级域名配置（JSON格式，用于新架构）
     */
    public function generateFromGroup($topDomain, $groupConfig) {
        try {
            error_log("[配置生成] 开始为顶级域名生成配置: {$topDomain}");
            
            require_once __DIR__ . '/KeywordListManager.php';
            
            // 1. 获取镜像源
            $mirrorId = '';
            $sourceDomain = '';
            
            // 从分组的克隆源列表获取
            if (!empty($groupConfig['clone_sources'])) {
                $mirrorId = $groupConfig['clone_sources'][array_rand($groupConfig['clone_sources'])];
                error_log("[配置生成] 从分组克隆源选择: {$mirrorId}");
                
                // 获取源站域名
                $mirrorConfigFile = $this->base_dir . '/data/mirrors/' . $mirrorId . '/config.json';
                if (file_exists($mirrorConfigFile)) {
                    $mirrorConfig = json_decode(file_get_contents($mirrorConfigFile), true);
                    $sourceDomain = $mirrorConfig['source_domain'] ?? '';
                }
            } else {
                // 直接扫描 mirrors 目录
                $mirrorsDir = $this->base_dir . '/data/mirrors';
                if (is_dir($mirrorsDir)) {
                    $allMirrors = array_filter(scandir($mirrorsDir), function($item) use ($mirrorsDir) {
                        return $item !== '.' && $item !== '..' && is_dir($mirrorsDir . '/' . $item);
                    });
                    
                    if (!empty($allMirrors)) {
                        $mirrorId = $allMirrors[array_rand($allMirrors)];
                        error_log("[配置生成] 从mirrors目录随机选择: {$mirrorId}");
                        
                        $mirrorConfigFile = $mirrorsDir . '/' . $mirrorId . '/config.json';
                        if (file_exists($mirrorConfigFile)) {
                            $mirrorConfig = json_decode(file_get_contents($mirrorConfigFile), true);
                            $sourceDomain = $mirrorConfig['source_domain'] ?? '';
                        }
                    }
                }
            }
            
            if (empty($mirrorId)) {
                throw new Exception('无可用镜像源');
            }
            
            // 2. 生成TDK
            $tdkMode = $groupConfig['type'] ?? 'random';
            $keywordManager = new KeywordListManager();
            $tdk = [];
            $root = '';
            
            switch ($tdkMode) {
                case 'title_list':
                    // 标题列表模式：从文件读取
                    $titleListPath = $groupConfig['title_list_path'] ?? '';
                    if (!empty($titleListPath)) {
                        $fullPath = $this->base_dir . '/' . $titleListPath;
                        if (file_exists($fullPath)) {
                            $customTitle = trim(file_get_contents($fullPath));
                            if (!empty($customTitle)) {
                                $keywords = explode('_', $customTitle);
                                $keywordsStr = implode(',', $keywords);
                                $tdk = [
                                    'title' => $customTitle,
                                    'keywords' => $keywordsStr,
                                    'description' => $customTitle
                                ];
                                error_log("[配置生成] 使用标题列表: {$customTitle}");
                                break;
                            }
                        }
                    }
                    // 如果标题列表失败，降级为随机
                    error_log("[配置生成] 标题列表失败，降级为随机词根");
                    // 继续执行下面的 random 逻辑
                    
                case 'random':
                    // 随机词根
                    $rootsFile = $this->base_dir . '/data/data_key/统计词根.txt';
                    if (file_exists($rootsFile)) {
                        $roots = file($rootsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        $roots = array_filter(array_map('trim', $roots));
                        if (!empty($roots)) {
                            $root = $roots[array_rand($roots)];
                            $tdk = $keywordManager->generateTDK($root, '');
                            error_log("[配置生成] 使用随机词根: {$root}");
                        }
                    }
                    break;
                    
                case 'fixed':
                case 'root':
                    // 固定词根
                    $root = $groupConfig['value'] ?? '';
                    if (!empty($root)) {
                        $tdk = $keywordManager->generateTDK($root, '');
                        error_log("[配置生成] 使用固定词根: {$root}");
                    }
                    break;
                    
                case 'custom_title':
                    // 自定义标题
                    $customTitle = $groupConfig['value'] ?? '';
                    if (!empty($customTitle)) {
                        $keywords = explode('_', $customTitle);
                        $keywordsStr = implode(',', $keywords);
                        $tdk = [
                            'title' => $customTitle,
                            'keywords' => $keywordsStr,
                            'description' => $customTitle
                        ];
                        error_log("[配置生成] 使用自定义标题: {$customTitle}");
                    }
                    break;
            }
            
            // 如果TDK生成失败，使用默认值
            if (empty($tdk)) {
                error_log("[配置生成] TDK生成失败，使用默认值");
                $tdk = [
                    'title' => $topDomain,
                    'keywords' => $topDomain,
                    'description' => $topDomain
                ];
            }
            
            // 3. 构建配置
            $config = [
                'mode' => 'mirror',
                'mirror_id' => $mirrorId,
                'source_domain' => $sourceDomain,
                'tdk' => $tdk,
                'root' => $root,
                'config_mode' => 'independent',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // 4. 保存配置
            $configFile = $this->base_dir . '/data/domain/' . $topDomain . '.json';
            $result = file_put_contents($configFile, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            
            if ($result === false) {
                throw new Exception('配置文件保存失败');
            }
            
            error_log("[配置生成] ✓ 配置生成成功: {$configFile}");
            
            return $config;
            
        } catch (Exception $e) {
            error_log("[配置生成] ✗ 配置生成失败: " . $e->getMessage());
            return null;
        }
    }
}

