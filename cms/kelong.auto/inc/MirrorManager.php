<?php
/**
 * 镜像管理器
 * 负责镜像的读取、保存、内页处理
 */

require_once __DIR__ . '/PlaceholderExtractor.php';

class MirrorManager {
    private $base_dir;
    private $mirrors_dir;
    public $extractor;  // 改为public，供外部访问
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->mirrors_dir = $this->base_dir . '/data/mirrors/';
        $this->extractor = new PlaceholderExtractor();
    }
    
    /**
     * 渲染镜像页面
     * @param string $mirrorId 镜像ID
     * @param array $tdk TDK数据
     * @param string $requestUri 请求路径
     * @return string|false 渲染后的HTML
     */
    public function render($mirrorId, $tdk, $requestUri = '/') {
        $mirrorDir = $this->mirrors_dir . $mirrorId . '/';
        
        // 确定页面文件
        if ($requestUri === '/' || $requestUri === '/index.html' || $requestUri === '/index.php') {
            $pageFile = $mirrorDir . 'index.html';
        } else {
            // 内页
            $pageFile = $this->getInnerPageFile($mirrorDir, $requestUri);
            
            if (!$pageFile || !file_exists($pageFile)) {
                // 内页不存在，返回false触发克隆
                error_log("[镜像] 内页不存在: {$requestUri}");
                return false;
            }
        }
        
        if (!file_exists($pageFile)) {
            error_log("[镜像] 页面文件不存在: {$pageFile}");
            return false;
        }
        
        // 读取模板
        $template = file_get_contents($pageFile);
        
        // 读取镜像配置（用于域名替换）
        $configFile = $mirrorDir . 'config.json';
        $config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : null;
        
        // 替换占位符
        $html = $this->replacePlaceholdersWithConfig($template, $tdk, $config);
        
        return $html;
    }
    
    /**
     * 保存克隆的内页为占位符模板
     * @param string $mirrorId 镜像ID
     * @param string $requestUri 请求路径
     * @param string $html 克隆的HTML
     * @param array $tdk 当前TDK
     */
    public function saveInnerPage($mirrorId, $requestUri, $html, $tdk) {
        $mirrorDir = $this->mirrors_dir . $mirrorId . '/';
        $pagesDir = $mirrorDir . 'pages/';
        
        // 确保目录存在
        if (!is_dir($pagesDir)) {
            mkdir($pagesDir, 0755, true);
        }
        
        // 生成文件名
        $fileName = $this->generatePageFileName($requestUri);
        $pageFile = $pagesDir . $fileName;
        
        // 提取占位符
        $template = $this->extractor->extract($html, $tdk);
        
        // 保存模板
        file_put_contents($pageFile, $template);
        
        // 更新镜像配置
        $this->updateMirrorConfig($mirrorId, $requestUri, $fileName, strlen($template));
        
        error_log("[镜像] 内页已保存为模板: {$requestUri} → {$fileName}");
        
        return true;
    }
    
    /**
     * 生成内页文件名
     */
    private function generatePageFileName($requestUri) {
        $clean = trim($requestUri, '/');
        $clean = str_replace(['/', '?', '&', '='], '_', $clean);
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $clean);
        $clean = preg_replace('/_+/', '_', $clean);
        
        return $clean . '.html';
    }
    
    /**
     * 获取内页文件路径
     */
    private function getInnerPageFile($mirrorDir, $requestUri) {
        $pagesDir = $mirrorDir . 'pages/';
        
        if (!is_dir($pagesDir)) {
            return null;
        }
        
        $fileName = $this->generatePageFileName($requestUri);
        $pageFile = $pagesDir . $fileName;
        
        return $pageFile;
    }
    
    /**
     * 替换占位符
     */
    public function replacePlaceholders($template, $tdk) {
        $currentDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // 1. 替换占位符
        $replacements = [
            '{{DOMAIN}}' => $currentDomain,
            '{{WWW_DOMAIN}}' => 'www.' . $currentDomain,
            '{{TITLE}}' => $tdk['title'],
            '{{KEYWORDS}}' => $tdk['keywords'],
            '{{DESCRIPTION}}' => $tdk['description'],
            '{{YEAR}}' => date('Y')
        ];
        
        $html = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // 2. 处理模板中的源站域名（参考旧版本逻辑）
        // 获取镜像的源站域名
        $mirrorConfig = $this->getMirrorConfigFromTemplate($template);
        if ($mirrorConfig && isset($mirrorConfig['target_domain'])) {
            $targetDomain = $mirrorConfig['target_domain'];
            $targetDomainClean = str_replace('www.', '', $targetDomain);
            
            // 检测当前访问协议
            $currentProtocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            
            // 替换各种格式的域名引用
            $html = str_replace($targetDomain, $currentDomain, $html);
            $html = str_replace($targetDomainClean, $currentDomain, $html);
            $html = str_replace('www.' . $targetDomainClean, 'www.' . $currentDomain, $html);
            
            // 替换URL中的域名（统一使用当前协议）
            $html = str_replace('http://' . $targetDomain, $currentProtocol . '://' . $currentDomain, $html);
            $html = str_replace('https://' . $targetDomain, $currentProtocol . '://' . $currentDomain, $html);
            $html = str_replace('//' . $targetDomain, '//' . $currentDomain, $html);
            
            // 处理相对路径（使用当前协议）
            $html = str_replace('href="//', 'href="' . $currentProtocol . '://', $html);
            $html = str_replace("href='//", "href='" . $currentProtocol . "://", $html);
            
            // 将外部链接改为#（参考旧版本）
            $html = preg_replace('@href="http://(?!www\.'.$currentDomain.'|'.$currentDomain.')([^"]*)"@is', 'href="#"', $html);
            $html = preg_replace("@href='http://(?!www\.".$currentDomain."|".$currentDomain.")([^']*)'@is", "href='#'", $html);
            $html = preg_replace('@href="https://(?!www\.'.$currentDomain.'|'.$currentDomain.')([^"]*)"@is', 'href="#"', $html);
            $html = preg_replace("@href='https://(?!www\.".$currentDomain."|".$currentDomain.")([^']*)'@is", "href='#'", $html);
            
            // 简化本站链接（同时处理http和https）
            $html = str_replace('"http://www.'.$currentDomain, '"/', $html);
            $html = str_replace("'http://www.".$currentDomain, "'/", $html);
            $html = str_replace('"http://'.$currentDomain, '"/', $html);
            $html = str_replace("'http://".$currentDomain, "'/", $html);
            $html = str_replace('"https://www.'.$currentDomain, '"/', $html);
            $html = str_replace("'https://www.".$currentDomain, "'/", $html);
            $html = str_replace('"https://'.$currentDomain, '"/', $html);
            $html = str_replace("'https://".$currentDomain, "'/", $html);
        }
        
        // 3. 动态插入 <h1> 标签占位符（实际内容在繁体转换后插入）
        $html = preg_replace('@<body([^>]*)>\s*<h1[^>]*>.*?</h1>@is', '<body$1>', $html);
        $html = preg_replace('@<h1[^>]*>.*?</h1>@is', '', $html);
        $html = preg_replace("/<body(.*)>/i", "<body$1>\n<!--H1_PLACEHOLDER-->", $html, 1);
        
        return $html;
    }
    
    /**
     * 替换占位符（带配置信息）
     */
    private function replacePlaceholdersWithConfig($template, $tdk, $config) {
        $currentDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // 1. 替换占位符
        $replacements = [
            '{{DOMAIN}}' => $currentDomain,
            '{{WWW_DOMAIN}}' => 'www.' . $currentDomain,
            '{{TITLE}}' => $tdk['title'],
            '{{KEYWORDS}}' => $tdk['keywords'],
            '{{DESCRIPTION}}' => $tdk['description'],
            '{{YEAR}}' => date('Y')
        ];
        
        $html = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // 2. 处理源站域名
        if (isset($config['target_domain'])) {
            $targetDomain = $config['target_domain'];
            $targetDomainClean = str_replace('www.', '', $targetDomain);
            
            // 替换域名
            $html = str_replace($targetDomain, $currentDomain, $html);
            $html = str_replace($targetDomainClean, $currentDomain, $html);
            $html = str_replace('www.' . $targetDomainClean, 'www.' . $currentDomain, $html);
            
            // 替换URL
            $html = str_replace('http://' . $targetDomain, 'http://' . $currentDomain, $html);
            $html = str_replace('https://' . $targetDomain, 'https://' . $currentDomain, $html);
            $html = str_replace('//' . $targetDomain, '//' . $currentDomain, $html);
            
            // 处理相对路径
            $html = str_replace('href="//', 'href="http://', $html);
            $html = str_replace("href='//", "href='http://", $html);
            $html = str_replace('src="//', 'src="http://', $html);
            $html = str_replace("src='//", "src='http://", $html);
            
            // 将外部链接改为#
            $html = preg_replace('@href="http://(?!www\.'.$currentDomain.'|'.$currentDomain.')([^"]*)"@is', 'href="#"', $html);
            $html = preg_replace("@href='http://(?!www\.".$currentDomain."|".$currentDomain.")([^']*)'@is", "href='#'", $html);
            $html = preg_replace('@href="https://(?!www\.'.$currentDomain.'|'.$currentDomain.')([^"]*)"@is', 'href="#"', $html);
            $html = preg_replace("@href='https://(?!www\.".$currentDomain."|".$currentDomain.")([^']*)'@is", "href='#'", $html);
            
            // 简化本站链接
            $html = str_replace('"http://www.'.$currentDomain, '"/', $html);
            $html = str_replace("'http://www.".$currentDomain, "'/", $html);
            $html = str_replace('"http://'.$currentDomain, '"/', $html);
            $html = str_replace("'http://".$currentDomain, "'/", $html);
            $html = str_replace('"//', '"/', $html);
            $html = str_replace("'//", "'/", $html);
        }
        
        // 3. 动态插入 <h1> 标签占位符（实际内容在繁体转换后插入）
        $html = preg_replace('@<body([^>]*)>\s*<h1[^>]*>.*?</h1>@is', '<body$1>', $html);
        $html = preg_replace('@<h1[^>]*>.*?</h1>@is', '', $html);
        $html = preg_replace("/<body(.*)>/i", "<body$1>\n<!--H1_PLACEHOLDER-->", $html, 1);
        
        return $html;
    }
    
    /**
     * 更新镜像配置（添加内页信息）
     */
    private function updateMirrorConfig($mirrorId, $requestUri, $fileName, $size) {
        $configFile = $this->mirrors_dir . $mirrorId . '/config.json';
        
        if (!file_exists($configFile)) {
            return;
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        
        if (!$config) {
            return;
        }
        
        // 添加内页信息
        if (!isset($config['pages']['inner_pages'])) {
            $config['pages']['inner_pages'] = [];
        }
        
        $config['pages']['inner_pages'][$requestUri] = [
            'file' => $fileName,
            'size' => $size,
            'created' => date('Y-m-d H:i:s')
        ];
        
        // 更新统计
        $config['statistics']['total_pages'] = 1 + count($config['pages']['inner_pages']);
        $config['statistics']['total_size'] = ($config['statistics']['total_size'] ?? 0) + $size;
        
        // 保存
        file_put_contents($configFile, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    
    /**
     * 获取所有可用镜像
     */
    public function getAvailableMirrors() {
        if (!is_dir($this->mirrors_dir)) {
            return [];
        }
        
        $mirrors = [];
        $dirs = scandir($this->mirrors_dir);
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            
            $configFile = $this->mirrors_dir . $dir . '/config.json';
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true);
                if ($config) {
                    $mirrors[] = [
                        'mirror_id' => $dir,
                        'target_domain' => $config['target_domain'] ?? '',
                        'target_title' => $config['target_title'] ?? ''
                    ];
                }
            }
        }
        
        return $mirrors;
    }
    
    /**
     * 随机选择镜像
     */
    public function getRandomMirror() {
        $mirrors = $this->getAvailableMirrors();
        
        if (empty($mirrors)) {
            return null;
        }
        
        return $mirrors[array_rand($mirrors)];
    }
}
