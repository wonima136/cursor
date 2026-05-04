<?php
/**
 * 镜像选择器 - 通用类
 * 用于随机选择镜像，不遍历全部镜像（高性能）
 */
class MirrorSelector {
    private $base_dir;
    private $logger;
    private $maxAttempts = 10; // 最多尝试次数
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
    }
    
    public function setLogger($logger) {
        $this->logger = $logger;
    }
    
    /**
     * 随机选择一个镜像（排除指定镜像）
     * @param string $excludeMirrorId 要排除的镜像ID（可选）
     * @param array $preferredSources 优先使用的镜像列表（可选）
     * @return array|null ['mirror_id' => 'xxx', 'source_domain' => 'xxx']
     */
    public function selectRandom($excludeMirrorId = null, $preferredSources = []) {
        // 1. 优先从指定的镜像列表中选择
        if (!empty($preferredSources)) {
            if ($this->logger) $this->logger->debug("      从指定列表随机选择镜像");
            
            $result = $this->selectFromList($preferredSources, $excludeMirrorId);
            if ($result) {
                return $result;
            }
            
            if ($this->logger) $this->logger->warning("      指定列表无有效镜像，尝试扫描 mirrors 目录");
        }
        
        // 2. 从 mirrors 目录随机选择
        if ($this->logger) $this->logger->debug("      从 mirrors 目录随机选择镜像");
        
        return $this->selectFromDirectory($excludeMirrorId);
    }
    
    /**
     * 从指定列表中随机选择
     */
    private function selectFromList($sources, $excludeMirrorId) {
        // 过滤掉要排除的镜像
        $sources = array_filter($sources, function($id) use ($excludeMirrorId) {
            return $id !== $excludeMirrorId;
        });
        $sources = array_values($sources);
        
        if (empty($sources)) {
            if ($this->logger) $this->logger->warning("      列表中只有1个镜像，无法切换");
            return null;
        }
        
        // 随机选择并验证
        for ($i = 0; $i < min($this->maxAttempts, count($sources)); $i++) {
            $mirrorId = $sources[array_rand($sources)];
            $result = $this->validate($mirrorId);
            if ($result) {
                if ($this->logger) $this->logger->info("      ✓ 选中镜像: {$mirrorId}");
                return $result;
            }
        }
        
        return null;
    }
    
    /**
     * 从 mirrors 目录随机选择（使用 array_rand 方法）
     */
    private function selectFromDirectory($excludeMirrorId) {
        $mirrorsDir = $this->base_dir . '/data/mirrors';
        
        if (!is_dir($mirrorsDir)) {
            if ($this->logger) $this->logger->error("      ❌ mirrors 目录不存在");
            return null;
        }
        
        $dirs = @scandir($mirrorsDir);
        if ($dirs === false) {
            if ($this->logger) $this->logger->error("      ❌ 无法读取 mirrors 目录");
            return null;
        }
        
        // 过滤出有效的镜像目录
        $validDirs = [];
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === $excludeMirrorId) {
                continue;
            }
            if (strpos($dir, 'mirror_') === 0) {
                $validDirs[] = $dir;
            }
        }
        
        if (empty($validDirs)) {
            if ($this->logger) $this->logger->warning("      mirrors 目录中没有可用镜像");
            return null;
        }
        
        // 使用 array_rand() 随机选择（方法1）
        $attempts = 0;
        $triedIndexes = [];
        
        while ($attempts < $this->maxAttempts && count($triedIndexes) < count($validDirs)) {
            $attempts++;
            
            // 从未尝试过的索引中随机选择
            $availableIndexes = array_diff(array_keys($validDirs), $triedIndexes);
            if (empty($availableIndexes)) {
                break;
            }
            
            $randomIndex = array_rand(array_flip($availableIndexes));
            $triedIndexes[] = $randomIndex;
            $dir = $validDirs[$randomIndex];
            
            if ($this->logger) $this->logger->debug("      尝试 {$attempts}: {$dir}");
            
            $result = $this->validate($dir);
            if ($result) {
                if ($this->logger) $this->logger->info("      ✓ 选中镜像: {$dir}");
                return $result;
            }
        }
        
        if ($this->logger) $this->logger->error("      ❌ 尝试了 {$attempts} 次，未找到有效镜像");
        return null;
    }
    
    /**
     * 验证镜像是否有效
     * @param string $mirrorId 镜像ID
     * @return array|null ['mirror_id' => 'xxx', 'source_domain' => 'xxx']
     */
    public function validate($mirrorId) {
        $mirrorDir = $this->base_dir . '/data/mirrors/' . $mirrorId;
        
        // 检查目录
        if (!is_dir($mirrorDir)) {
            if ($this->logger) $this->logger->debug("        ✗ 目录不存在");
            return null;
        }
        
        // 检查 index.html
        $indexFile = $mirrorDir . '/index.html';
        if (!file_exists($indexFile)) {
            if ($this->logger) $this->logger->debug("        ✗ index.html 不存在");
            return null;
        }
        
        // 检查 config.json
        $configFile = $mirrorDir . '/config.json';
        if (!file_exists($configFile)) {
            if ($this->logger) $this->logger->debug("        ✗ config.json 不存在");
            return null;
        }
        
        // 读取源站域名
        $config = @json_decode(file_get_contents($configFile), true);
        $sourceDomain = $config['source_domain'] ?? '';
        
        if ($this->logger) $this->logger->debug("        ✓ 验证通过");
        
        return [
            'mirror_id' => $mirrorId,
            'source_domain' => $sourceDomain
        ];
    }
    
    /**
     * 获取镜像总数（用于统计）
     */
    public function count() {
        $mirrorsDir = $this->base_dir . '/data/mirrors';
        
        if (!is_dir($mirrorsDir)) {
            return 0;
        }
        
        $dirs = @scandir($mirrorsDir);
        if ($dirs === false) {
            return 0;
        }
        
        $count = 0;
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            if (strpos($dir, 'mirror_') === 0) {
                $mirrorPath = $mirrorsDir . '/' . $dir;
                if (is_dir($mirrorPath) && file_exists($mirrorPath . '/config.json')) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
}
