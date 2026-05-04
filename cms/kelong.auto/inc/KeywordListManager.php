<?php
/**
 * 自定义关键词列表管理器
 * 管理 /data/data_key/diy_key/ 目录下的关键词列表
 */
class KeywordListManager {
    private $base_dir;
    private $keywords_dir;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->keywords_dir = $this->base_dir . '/data/data_key/diy_key/';
        
        // 确保目录存在
        if (!is_dir($this->keywords_dir)) {
            mkdir($this->keywords_dir, 0755, true);
        }
    }
    
    /**
     * 创建关键词列表
     * @param string $groupId 分组ID
     * @param string $name 列表名称  
     * @param array $keywords 关键词数组
     * @param array $domains 使用的域名数组
     * @return string 返回列表ID
     */
    public function createKeywordList($groupId, $name, $keywords, $domains = []) {
        // 生成唯一ID
        $listId = $groupId . '_keywords_' . substr(md5(uniqid()), 0, 8);
        
        $data = [
            'id' => $listId,
            'name' => $name,
            'group_id' => $groupId,
            'keywords' => array_filter($keywords), // 过滤空项
            'usage_rule' => 'random_repeatable',    // 随机选择（可重复）
            'domains' => $domains,
            'created' => date('Y-m-d H:i:s'),
            'last_used' => null,
            'usage_count' => 0
        ];
        
        $filename = $this->keywords_dir . $listId . '.json';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        if (file_put_contents($filename, $json)) {
            return $listId;
        }
        
        return false;
    }
    
    /**
     * 获取关键词列表
     * @param string $listId 列表ID
     * @return array|null
     */
    public function getKeywordList($listId) {
        $filename = $this->keywords_dir . $listId . '.json';
        
        if (!file_exists($filename)) {
            return null;
        }
        
        $content = file_get_contents($filename);
        return json_decode($content, true);
    }
    
    /**
     * 更新关键词列表
     * @param string $listId 列表ID
     * @param array $data 要更新的数据
     * @return bool
     */
    public function updateKeywordList($listId, $data) {
        $existing = $this->getKeywordList($listId);
        if (!$existing) {
            return false;
        }
        
        // 合并数据
        $updated = array_merge($existing, $data);
        $updated['updated'] = date('Y-m-d H:i:s');
        
        $filename = $this->keywords_dir . $listId . '.json';
        $json = json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        return file_put_contents($filename, $json) !== false;
    }
    
    /**
     * 删除关键词列表
     * @param string $listId 列表ID
     * @return bool
     */
    public function deleteKeywordList($listId) {
        $filename = $this->keywords_dir . $listId . '.json';
        
        if (file_exists($filename)) {
            return unlink($filename);
        }
        
        return true;
    }
    
    /**
     * 获取所有关键词列表
     * @return array
     */
    public function getAllKeywordLists() {
        $lists = [];
        
        if (!is_dir($this->keywords_dir)) {
            return $lists;
        }
        
        $files = glob($this->keywords_dir . '*.json');
        
        foreach ($files as $file) {
            $listId = basename($file, '.json');
            $data = $this->getKeywordList($listId);
            
            if ($data) {
                $lists[$listId] = $data;
            }
        }
        
        return $lists;
    }
    
    /**
     * 根据分组ID获取关键词列表
     * @param string $groupId 分组ID
     * @return array
     */
    public function getKeywordListsByGroup($groupId) {
        $allLists = $this->getAllKeywordLists();
        $groupLists = [];
        
        foreach ($allLists as $listId => $data) {
            if ($data['group_id'] === $groupId) {
                $groupLists[$listId] = $data;
            }
        }
        
        return $groupLists;
    }
    
    /**
     * 从关键词列表中随机选择一个关键词
     * @param string $listId 列表ID
     * @return string|null
     */
    public function getRandomKeyword($listId) {
        $list = $this->getKeywordList($listId);
        
        if (!$list || empty($list['keywords'])) {
            return null;
        }
        
        // 随机选择一个关键词（可重复）
        $keywords = $list['keywords'];
        $selectedKeyword = $keywords[array_rand($keywords)];
        
        // 更新使用统计
        $this->updateUsageStats($listId);
        
        return $selectedKeyword;
    }
    
    /**
     * 更新使用统计
     * @param string $listId 列表ID
     */
    private function updateUsageStats($listId) {
        $list = $this->getKeywordList($listId);
        
        if ($list) {
            $list['last_used'] = date('Y-m-d H:i:s');
            $list['usage_count'] = ($list['usage_count'] ?? 0) + 1;
            
            $filename = $this->keywords_dir . $listId . '.json';
            $json = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            file_put_contents($filename, $json);
        }
    }
    
    /**
     * 生成TDK信息
     * @param string $keyword 关键词
     * @param string $domain 域名（用于占位符替换）
     * @return array ['title' => '', 'keywords' => '', 'description' => '']
     */
    public function generateTDK($root, $domain) {
        // 从词根文件中读取关键词生成TDK
        $keywordsFile = $this->base_dir . '/data/data_key/keywords_by_root/' . $root . '.txt';
        
        if (file_exists($keywordsFile)) {
            // 从词根文件读取关键词
            $keywords = file($keywordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $keywords = array_filter(array_map('trim', $keywords));
            
            if (!empty($keywords)) {
                // 随机选择8个关键词
                shuffle($keywords);
                $selected = array_slice($keywords, 0, 8);
                
                $title = implode('_', $selected);
                $keywordsStr = implode(',', $selected);
                
                return [
                    'title' => $title,
                    'keywords' => $keywordsStr,
                    'description' => $title
                ];
            }
        }
        
        // 如果词根文件不存在，尝试从主关键词库搜索
        $mainFile = $this->base_dir . '/data/data_key/key.txt';
        if (file_exists($mainFile)) {
            $allKeywords = file($mainFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $keywords = array_filter($allKeywords, function($kw) use ($root) {
                return mb_strpos($kw, $root) !== false;
            });
            
            if (!empty($keywords)) {
                shuffle($keywords);
                $selected = array_slice($keywords, 0, 8);
                
                $title = implode('_', $selected);
                $keywordsStr = implode(',', $selected);
                
                return [
                    'title' => $title,
                    'keywords' => $keywordsStr,
                    'description' => $title
                ];
            }
        }
        
        // 如果都找不到，使用词根作为默认值（重复8次）
        $defaultKeywords = array_fill(0, 8, $root);
        $title = implode('_', $defaultKeywords);
        $keywordsStr = implode(',', $defaultKeywords);
        
        return [
            'title' => $title,
            'keywords' => $keywordsStr,
            'description' => $title
        ];
    }
    
    /**
     * 获取关键词列表统计信息
     * @return array
     */
    public function getStatistics() {
        $allLists = $this->getAllKeywordLists();
        
        $stats = [
            'total_lists' => count($allLists),
            'total_keywords' => 0,
            'total_usage' => 0,
            'active_lists' => 0
        ];
        
        foreach ($allLists as $list) {
            $stats['total_keywords'] += count($list['keywords'] ?? []);
            $stats['total_usage'] += $list['usage_count'] ?? 0;
            
            if (!empty($list['domains'])) {
                $stats['active_lists']++;
            }
        }
        
        return $stats;
    }
}