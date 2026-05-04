<?php
/**
 * 内页克隆失败处理类
 * 
 * 功能：
 * 1. 内页克隆失败时，随机选择镜像中已存在的其他内页
 * 2. 如果没有可用内页，301跳转到当前域名首页
 * 3. 支持所有配置模式（独立、固定、动态）
 */

class InnerPageFallback {
    
    private $mirrorManager;
    private $logger;
    
    public function __construct($mirrorManager = null, $logger = null) {
        $this->mirrorManager = $mirrorManager;
        $this->logger = $logger;
    }
    
    /**
     * 处理内页克隆失败
     * 
     * @param string $mirrorId 镜像ID
     * @param string $requestUri 请求的URI
     * @param array $tdk 当前域名的TDK配置
     * @return string|false 返回HTML内容或false
     */
    public function handle($mirrorId, $requestUri, $tdk) {
        if ($this->logger) {
            $this->logger->warning("内页克隆失败，启动降级处理: {$requestUri}");
        }
        error_log("[内页失败处理] 开始处理: {$requestUri}");
        
        // 1. 尝试随机选择一个已存在的内页
        $randomPageHtml = $this->getRandomExistingPage($mirrorId, $tdk);
        
        if ($randomPageHtml !== false) {
            error_log("[内页失败处理] ✓ 使用随机已存在的页面");
            if ($this->logger) {
                $this->logger->info("使用随机已存在的内页代替");
            }
            return $randomPageHtml;
        }
        
        // 2. 没有可用的随机页面，执行301跳转
        error_log("[内页失败处理] 没有可用页面，301跳转到首页");
        if ($this->logger) {
            $this->logger->warning("没有可用的内页，301跳转到首页");
        }
        
        $this->redirectToHome();
        return false; // 不会执行到这里，因为redirect会exit
    }
    
    /**
     * 从镜像中随机选择一个已存在的内页并渲染
     * 
     * @param string $mirrorId 镜像ID
     * @param array $tdk 当前域名的TDK
     * @return string|false 返回渲染后的HTML或false
     */
    private function getRandomExistingPage($mirrorId, $tdk) {
        if (!$this->mirrorManager) {
            error_log("[内页失败处理] MirrorManager未初始化");
            return false;
        }
        
        $baseDir = dirname(__DIR__);
        $pagesDir = $baseDir . '/data/mirrors/' . $mirrorId . '/pages/';
        
        // 检查pages目录是否存在
        if (!is_dir($pagesDir)) {
            error_log("[内页失败处理] Pages目录不存在: {$pagesDir}");
            return false;
        }
        
        // 获取所有内页文件
        $pageFiles = glob($pagesDir . '*.html');
        
        if (empty($pageFiles)) {
            error_log("[内页失败处理] 没有找到任何内页文件");
            return false;
        }
        
        // 随机选择一个页面
        $randomFile = $pageFiles[array_rand($pageFiles)];
        $fileName = basename($randomFile);
        
        error_log("[内页失败处理] 随机选择页面: {$fileName}");
        
        // 读取模板内容
        $template = @file_get_contents($randomFile);
        
        if ($template === false) {
            error_log("[内页失败处理] 读取页面失败: {$fileName}");
            return false;
        }
        
        // 使用MirrorManager渲染（替换TDK占位符）
        try {
            // 直接使用模板内容替换占位符
            $html = $this->renderWithTdk($template, $tdk);
            
            if ($html !== false) {
                error_log("[内页失败处理] 页面渲染成功，大小: " . strlen($html) . " 字节");
                return $html;
            }
        } catch (Exception $e) {
            error_log("[内页失败处理] 渲染异常: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * 使用TDK渲染模板
     * 
     * @param string $template 模板内容
     * @param array $tdk TDK配置
     * @return string 渲染后的HTML
     */
    private function renderWithTdk($template, $tdk) {
        // 获取当前域名
        $currentDomain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        
        // 替换TDK占位符（使用镜像系统的占位符格式）
        $replacements = [
            '{{TITLE}}' => $tdk['title'] ?? '',
            '{{KEYWORDS}}' => $tdk['keywords'] ?? '',
            '{{DESCRIPTION}}' => $tdk['description'] ?? '',
            '{{DOMAIN}}' => $currentDomain,
            '{{WWW_DOMAIN}}' => 'www.' . $currentDomain,
            '{{YEAR}}' => date('Y'),
            // 兼容旧格式
            '[网站标题]' => $tdk['title'] ?? '',
            '[网站关键词]' => $tdk['keywords'] ?? '',
            '[网站描述]' => $tdk['description'] ?? '',
        ];
        
        $html = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template
        );
        
        return $html;
    }
    
    /**
     * 301跳转到首页
     */
    private function redirectToHome() {
        error_log("[内页失败处理] 执行301跳转到首页");
        header("HTTP/1.1 301 Moved Permanently");
        header("Location: /");
        exit;
    }
    
    /**
     * 获取镜像中可用的内页数量（用于统计）
     * 
     * @param string $mirrorId 镜像ID
     * @return int 内页数量
     */
    public function getAvailablePagesCount($mirrorId) {
        $baseDir = dirname(__DIR__);
        $pagesDir = $baseDir . '/data/mirrors/' . $mirrorId . '/pages/';
        
        if (!is_dir($pagesDir)) {
            return 0;
        }
        
        $pageFiles = glob($pagesDir . '*.html');
        return count($pageFiles);
    }
    
    /**
     * 列出镜像中所有可用的内页（用于调试）
     * 
     * @param string $mirrorId 镜像ID
     * @return array 页面文件列表
     */
    public function listAvailablePages($mirrorId) {
        $baseDir = dirname(__DIR__);
        $pagesDir = $baseDir . '/data/mirrors/' . $mirrorId . '/pages/';
        
        if (!is_dir($pagesDir)) {
            return [];
        }
        
        $pageFiles = glob($pagesDir . '*.html');
        $pages = [];
        
        foreach ($pageFiles as $file) {
            $pages[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }
        
        return $pages;
    }
}
