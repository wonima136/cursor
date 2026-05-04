<?php
/**
 * 固定顶级模式 - 页面渲染
 */
class GudingRender {
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
        if ($this->logger) $this->logger->debug("      配置 mirror_id: " . ($config['mirror_id'] ?? 'N/A'));
        if ($this->logger) $this->logger->debug("      配置 source_domain: " . ($config['source_domain'] ?? 'N/A'));
        
        // 1. 获取真实的TDK（如果是引用，则读取顶级域名的TDK）
        if ($this->logger) $this->logger->info("      步骤1: 获取真实TDK");
        $realTDK = $this->getRealTDK($config);
        if ($this->logger) $this->logger->debug("      TDK Title: " . substr($realTDK['title'] ?? '', 0, 50) . "...");
        
        // 2. 获取镜像配置
        if ($this->logger) $this->logger->info("      步骤2: 获取镜像配置");
        $mirrorConfig = $this->getMirrorConfig($config);
        if ($this->logger) $this->logger->debug("      getMirrorConfig 返回: " . ($mirrorConfig ? 'true' : 'false'));
        
        if (!$mirrorConfig) {
            if ($this->logger) $this->logger->error("      ❌ 镜像配置获取失败");
            error_log("[固定-渲染] 镜像配置获取失败");
            echo "镜像配置错误";
            exit;
        }
        
        if ($this->logger) $this->logger->debug("      镜像配置检查通过，准备渲染");
        
        // 3. 渲染页面（使用真实的TDK）
        if ($this->logger) $this->logger->info("      步骤3: 渲染页面");
        $html = $this->mirrorManager->render($config['mirror_id'], $realTDK, $requestUri);
        
        if ($html === false) {
            if ($this->logger) $this->logger->warning("      ⚠️ 镜像渲染返回 false");
        } else {
            if ($this->logger) $this->logger->info("      ✓ 镜像渲染成功 (" . strlen($html) . " 字节)");
        }
        
        // 4. 如果首页渲染失败，尝试克隆内页
        if ($html === false && $requestUri !== '/') {
            if ($this->logger) $this->logger->info("      步骤4: 尝试克隆内页");
            error_log("[固定-渲染] 首次渲染失败，尝试克隆内页");
            $html = $this->cloneInnerPage($config, $requestUri, $mirrorConfig);
            
            if ($html === false) {
                if ($this->logger) $this->logger->error("      ❌ 内页克隆失败，启动降级处理");
                
                // 引入内页失败处理类
                require_once __DIR__ . '/../inc/InnerPageFallback.php';
                $fallback = new InnerPageFallback($this->mirrorManager, $this->logger);
                
                // 使用降级处理（随机页面或301跳转）
                $html = $fallback->handle($config['mirror_id'], $requestUri, $tdk);
            } else {
                if ($this->logger) $this->logger->info("      ✓ 内页克隆成功 (" . strlen($html) . " 字节)");
            }
        }
        
        // 5. 应用后处理（传递真实的TDK）
        if ($html) {
            if ($this->logger) $this->logger->info("      步骤5: 应用后处理");
            $html = $this->postProcess($html, $realTDK);
            if ($this->logger) $this->logger->info("      ✓ 后处理完成，准备输出 (" . strlen($html) . " 字节)");
            echo $html;
        } else {
            if ($this->logger) $this->logger->error("      ❌ 渲染失败，HTML为空");
            error_log("[固定-渲染] 渲染失败");
            echo "页面加载失败";
        }
        
        if ($this->logger) $this->logger->info("    渲染模块: 结束");
        exit;
    }
    
    /**
     * 获取真实的TDK（动态读取顶级域名的TDK）
     */
    private function getRealTDK($config) {
        // 如果有 top_domain 字段，说明需要动态读取顶级域名的TDK
        if (!empty($config['top_domain'])) {
            $topDomain = $config['top_domain'];
            
            if ($this->logger) $this->logger->debug("      动态读取顶级域名TDK: {$topDomain}");
            
            require_once $this->base_dir . '/inc/DomainConfigManager.php';
            $configManager = new DomainConfigManager();
            $topConfig = $configManager->getConfig($topDomain);
            
            if (!empty($topConfig['tdk'])) {
                if ($this->logger) $this->logger->debug("      ✓ 读取成功");
                return $topConfig['tdk'];
            }
        }
        
        // 否则直接使用配置中的TDK
        return $config['tdk'] ?? [
            'title' => '',
            'keywords' => '',
            'description' => ''
        ];
    }
    
    /**
     * 获取镜像配置
     */
    private function getMirrorConfig($config) {
        $mirrorId = $config['mirror_id'] ?? '';
        
        if (!$mirrorId) {
            if ($this->logger) $this->logger->error("      ❌ mirror_id 为空");
            return null;
        }
        
        // 尝试从 config.json 读取镜像配置
        $mirrorConfigPath = $this->base_dir . '/data/mirrors/' . $mirrorId . '/config.json';
        
        if (!file_exists($mirrorConfigPath)) {
            if ($this->logger) $this->logger->error("      ❌ 镜像配置文件不存在: {$mirrorConfigPath}");
            return null;
        }
        
        $mirrorConfig = json_decode(file_get_contents($mirrorConfigPath), true);
        
        if (!$mirrorConfig) {
            if ($this->logger) $this->logger->error("      ❌ 镜像配置文件解析失败");
            return null;
        }
        
        // 如果 config 中没有 source_domain，从镜像配置中获取
        // 兼容 target_domain 和 source_domain 两种字段名
        if (empty($config['source_domain'])) {
            if (!empty($mirrorConfig['source_domain'])) {
                $config['source_domain'] = $mirrorConfig['source_domain'];
            } elseif (!empty($mirrorConfig['target_domain'])) {
                $config['source_domain'] = $mirrorConfig['target_domain'];
            }
        }
        
        if ($this->logger) $this->logger->debug("      ✓ 镜像配置加载成功");
        if ($this->logger) $this->logger->debug("      准备返回镜像配置");
        
        return $mirrorConfig;
    }
    
    /**
     * 克隆内页
     */
    private function cloneInnerPage($config, $requestUri, $mirrorConfig) {
        // 兼容 target_domain 和 source_domain 两种字段名
        $sourceDomain = $mirrorConfig['source_domain'] ?? $mirrorConfig['target_domain'] ?? '';
        
        if (!$sourceDomain) {
            return false;
        }
        
        $sourceUrl = 'http://' . $sourceDomain . $requestUri;
        
        error_log("[固定-渲染] 克隆内页: $sourceUrl");
        
        $html = @file_get_contents($sourceUrl);
        
        if ($html === false) {
            error_log("[固定-渲染] 内页克隆失败");
            return false;
        }
        
        error_log("[固定-渲染] 内页克隆成功");
        return $html;
    }
    
    /**
     * 后处理HTML
     */
    private function postProcess($html, $tdk) {
        if ($this->logger) $this->logger->debug("        后处理: 开始");
        
        // ⚠️ 不加载 func.php 和 coon.php，避免触发自动配置生成
        // 这些函数库已经在 mirror_router.php 或 index.php 中加载
        // 我们只使用已经存在的全局函数
        
        // 1. 添加干扰标签（插入到body开始后）
        if (function_exists('rand_body_label')) {
            if ($this->logger) $this->logger->debug("        后处理: 添加干扰标签");
            $interferenceHtml = rand_body_label();
            $html = preg_replace("/<body(.*)>/i", "<body$1>\n" . $interferenceHtml, $html, 1);
        }
        
        // 2. 转繁体
        global $chinese;
        if (isset($chinese) && is_object($chinese) && method_exists($chinese, 'gb2312_big5')) {
            if ($this->logger) $this->logger->debug("        后处理: 转繁体");
            $html = $chinese->gb2312_big5($html);
        }
        
        // 3. TDK转码（unicode_encode）
        if (function_exists('unicode_encode')) {
            if ($this->logger) $this->logger->debug("        后处理: TDK转码");
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
            if ($this->logger) $this->logger->debug("        后处理: 应用拼音注释");
            $html = applyAnnotations($html);
        }
        
        // 5. 插入友情链接
        if (function_exists('getFriendLinkHTML')) {
            if ($this->logger) $this->logger->debug("        后处理: 插入友情链接");
            $friendLinksHtml = getFriendLinkHTML();
            if (!empty($friendLinksHtml)) {
                if ($this->logger) $this->logger->debug("        友情链接长度: " . strlen($friendLinksHtml) . " 字节");
                // 先删除可能存在的旧友情链接
                $html = preg_replace('@<table[^>]*?id=["\']table1["\'][^>]*>.*?</table>@is', '', $html);
                $html = str_replace("</body>", $friendLinksHtml . "\n</body>", $html);
            } else {
                if ($this->logger) $this->logger->debug("        友情链接为空");
            }
        }
        
        // 6. 插入底部关键词链接（AddKeys功能）
        if (function_exists('AddKeys')) {
            $guanjianziold = $tdk['keywords'] ?? '';
            if (!empty($guanjianziold)) {
                if ($this->logger) $this->logger->debug("        后处理: 插入关键词链接");
                $html = AddKeys($html, $guanjianziold);
            }
        }
        
        if ($this->logger) $this->logger->debug("        后处理: 完成");
        return $html;
    }
}
