<?php
/**
 * 独立配置模式 - 页面渲染
 */
class DuliRender {
    private $base_dir;
    private $logger;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        require_once $this->base_dir . '/inc/MirrorManager.php';
        // ❌ 不在构造函数中引入 func.php，避免触发自动配置生成
        // func.php 会在 postProcess() 中按需引入
        
        // 初始化 MirrorManager 实例
        $this->mirrorManager = new MirrorManager();
    }
    
    private $mirrorManager;
    
    public function setLogger($logger) {
        $this->logger = $logger;
    }
    
    /**
     * 渲染页面
     */
    public function render($config, $requestUri) {
        if ($this->logger) $this->logger->info("    渲染模块: 开始渲染 {$requestUri}");
        
        // 1. 获取镜像配置
        $mirrorConfig = $this->getMirrorConfig($config);
        
        if (!$mirrorConfig) {
            if ($this->logger) $this->logger->error("    镜像配置获取失败");
            echo "镜像配置错误";
            exit;
        }
        
        if ($this->logger) {
            $this->logger->debug("    Mirror ID: " . ($config['mirror_id'] ?? 'N/A'));
            $this->logger->debug("    Source Domain: " . ($mirrorConfig['source_domain'] ?? 'N/A'));
        }
        
        // 2. 渲染页面
        $html = $this->mirrorManager->render($config['mirror_id'], $config['tdk'], $requestUri);
        
        if ($html === false) {
            if ($this->logger) $this->logger->warning("    首次渲染失败，尝试克隆内页");
            
            // 如果是内页且不存在，尝试克隆
            if ($requestUri !== '/' && $requestUri !== '/index.html') {
                $html = $this->cloneAndRender($config, $requestUri, $mirrorConfig);
            }
            
            // 克隆失败，使用降级处理
            if ($html === false) {
                if ($this->logger) $this->logger->error("    内页克隆失败，启动降级处理");
                
                // 引入内页失败处理类
                require_once __DIR__ . '/../inc/InnerPageFallback.php';
                $fallback = new InnerPageFallback($this->mirrorManager, $this->logger);
                
                // 使用降级处理（随机页面或301跳转）
                $html = $fallback->handle($config['mirror_id'], $requestUri, $config['tdk']);
            }
        }
        
        if ($this->logger) $this->logger->info("    渲染成功，页面大小: " . strlen($html) . " bytes");
        
        // 3. 后处理
        $html = $this->postProcess($html, $config);
        
        // 4. 输出
        echo $html;
    }
    
    /**
     * 克隆并渲染内页
     */
    private function cloneAndRender($config, $requestUri, $mirrorConfig) {
        if ($this->logger) $this->logger->info("    开始克隆内页: {$requestUri}");
        
        $sourceDomain = $mirrorConfig['source_domain'];
        $sourceUrl = 'http://' . $sourceDomain . $requestUri;
        
        if ($this->logger) $this->logger->debug("    源站URL: {$sourceUrl}");
        
        // 克隆内页
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $sourceUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200 && !empty($content) && strlen($content) > 100) {
            if ($this->logger) $this->logger->info("    内页克隆成功，大小: " . strlen($content) . " bytes");
            
            // 保存内页模板
            $mirrorId = $config['mirror_id'];
            $this->mirrorManager->saveInnerPage($mirrorId, $requestUri, $content, $config['tdk']);
            
            // 重新渲染
            return $this->mirrorManager->render($mirrorId, $config['tdk'], $requestUri);
        }
        
        if ($this->logger) $this->logger->error("    内页克隆失败，HTTP: {$httpCode}");
        return false;
    }
    
    /**
     * 后处理
     */
    private function postProcess($html, $config) {
        if ($this->logger) $this->logger->info("    开始后处理");
        
        // 加载必要的函数库
        if (file_exists($this->base_dir . '/inc/func.php')) {
            require_once $this->base_dir . '/inc/func.php';
        }
        if (file_exists($this->base_dir . '/inc/coon.php')) {
            require_once $this->base_dir . '/inc/coon.php';
        }
        // ❌ 不加载 add.php，避免重复引入（mirror_router.php 已经加载了友情链接）
        // 友情链接函数 getFriendLinkHTML() 已在 mirror_router.php 中定义
        
        $tdk = $config['tdk'] ?? [];
        
        // 1. 添加干扰标签（插入到body开始后）
        if (function_exists('rand_body_label')) {
            $interferenceHtml = rand_body_label();
            $html = preg_replace("/<body(.*)>/i", "<body$1>\n" . $interferenceHtml, $html, 1);
        }
        
        // 2. 转繁体
        global $chinese;
        if (isset($chinese) && is_object($chinese) && method_exists($chinese, 'gb2312_big5')) {
            $html = $chinese->gb2312_big5($html);
        }
        
        // 3. TDK转码（unicode_encode）
        if (function_exists('unicode_encode')) {
            $encodedTitle = unicode_encode($tdk['title'] ?? '');
            $encodedKeywords = unicode_encode($tdk['keywords'] ?? '');
            $encodedDescription = unicode_encode($tdk['description'] ?? '');
            
            // 3.1 替换H1占位符（使用转码后的标题）
            $html = str_replace('<!--H1_PLACEHOLDER-->', '<h1>' . $encodedTitle . '</h1>', $html);
            
            // 3.2 替换HTML中的TDK为转码后的版本
            $html = preg_replace(
                '@<title>(.*?)</title>@is',
                '<title>' . $encodedTitle . '</title>',
                $html
            );
            $html = preg_replace(
                '@<meta([^>]*?)name=["\']?keywords["\']?([^>]*?)content=["\']([^"\']*)["\']@is',
                '<meta$1name="keywords"$2content="' . $encodedKeywords . '"',
                $html
            );
            $html = preg_replace(
                '@<meta([^>]*?)name=["\']?description["\']?([^>]*?)content=["\']([^"\']*)["\']@is',
                '<meta$1name="description"$2content="' . $encodedDescription . '"',
                $html
            );
        }
        
        // 4. 应用拼音注释
        if (function_exists('applyAnnotations')) {
            $html = applyAnnotations($html);
        }
        
        // 5. 插入友情链接
        if (function_exists('getFriendLinkHTML')) {
            $friendLinksHtml = getFriendLinkHTML();
            if (!empty($friendLinksHtml)) {
                // 先删除可能存在的旧友情链接
                $html = preg_replace('@<table[^>]*?id=["\']table1["\'][^>]*>.*?</table>@is', '', $html);
                $html = str_replace("</body>", $friendLinksHtml . "\n</body>", $html);
            }
        }
        
        // 6. 插入底部关键词链接（AddKeys功能）
        if (function_exists('AddKeys')) {
            $guanjianziold = $tdk['keywords'] ?? '';
            if (!empty($guanjianziold)) {
                $html = AddKeys($html, $guanjianziold);
            }
        }
        
        if ($this->logger) $this->logger->info("    后处理完成");
        
        return $html;
    }
    
    private function getMirrorConfig($config) {
        $mirrorId = $config['mirror_id'] ?? '';
        if (empty($mirrorId)) {
            return null;
        }
        
        $mirrorDir = $this->base_dir . '/data/mirrors/' . $mirrorId;
        $configFile = $mirrorDir . '/config.json';
        
        if (!file_exists($configFile)) {
            return null;
        }
        
        $mirrorConfig = json_decode(file_get_contents($configFile), true);
        
        // 如果镜像配置中没有 source_domain，从子域名配置中获取
        if (empty($mirrorConfig['source_domain']) && !empty($config['source_domain'])) {
            $mirrorConfig['source_domain'] = $config['source_domain'];
            if ($this->logger) {
                $this->logger->debug("    从子域名配置补充 source_domain: {$config['source_domain']}");
            }
        }
        
        return $mirrorConfig;
    }
}
