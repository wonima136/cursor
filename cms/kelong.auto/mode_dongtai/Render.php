<?php
/**
 * 动态顶级模式 - 页面渲染
 */
class DongtaiRender {
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
        
        // 1. 解析TDK引用（动态顶级模式使用引用格式）
        $tdk = $this->resolveTDK($config);
        
        if (!$tdk) {
            if ($this->logger) $this->logger->error("      ❌ TDK解析失败");
            echo "TDK配置错误";
            exit;
        }
        
        // 2. 获取镜像配置
        $mirrorConfig = $this->getMirrorConfig($config);
        
        if (!$mirrorConfig) {
            if ($this->logger) $this->logger->error("      ❌ 镜像配置获取失败");
            echo "镜像配置错误";
            exit;
        }
        
        // 2.1 确保 config 中有 source_domain（多重获取策略）
        if (empty($config['source_domain'])) {
            // 策略1：从镜像配置读取（支持 source_domain 或 target_domain）
            if (!empty($mirrorSourceDomain)) {
                $config['source_domain'] = $mirrorSourceDomain;
                if ($this->logger) $this->logger->debug("      [策略1] 从镜像配置同步源站域名: {$config['source_domain']}");
            }
            // 策略2：从顶级域名配置读取
            else if (!empty($config['top_domain'])) {
                $topConfigFile = $this->base_dir . '/data/domain/' . $config['top_domain'] . '.json';
                if (file_exists($topConfigFile)) {
                    $topConfig = json_decode(file_get_contents($topConfigFile), true);
                    if (!empty($topConfig['source_domain'])) {
                        $config['source_domain'] = $topConfig['source_domain'];
                        if ($this->logger) $this->logger->debug("      [策略2] 从顶级域名配置读取源站域名: {$config['source_domain']}");
                    } else {
                        if ($this->logger) $this->logger->warning("      ⚠️  顶级域名配置中 source_domain 为空");
                    }
                } else {
                    if ($this->logger) $this->logger->warning("      ⚠️  顶级域名配置文件不存在: {$topConfigFile}");
                }
            }
            
            // 最终检查
            if (empty($config['source_domain'])) {
                if ($this->logger) {
                    $this->logger->error("      ❌ 所有策略均失败，source_domain 仍为空");
                    $this->logger->error("      请检查镜像配置或顶级域名配置是否包含有效的源站域名");
                }
            }
        }
        
        // 3. 渲染页面
        if ($this->logger) $this->logger->debug("      尝试从镜像渲染: {$requestUri}");
        
        $html = $this->mirrorManager->render($config['mirror_id'], $tdk, $requestUri);
        
        // 4. 如果镜像渲染失败，尝试克隆源站内页
        if ($html === false && $requestUri !== '/') {
            if ($this->logger) $this->logger->info("      镜像中无此页面，克隆源站内页");
            
            $html = $this->cloneInnerPage($config, $requestUri, $mirrorConfig);
            
            if ($html === false) {
                if ($this->logger) $this->logger->error("      ❌ 源站内页克隆失败，启动降级处理");
                
                // 引入内页失败处理类
                require_once __DIR__ . '/../inc/InnerPageFallback.php';
                $fallback = new InnerPageFallback($this->mirrorManager, $this->logger);
                
                // 使用降级处理（随机页面或301跳转）
                $html = $fallback->handle($config['mirror_id'], $requestUri, $tdk);
            } else {
                if ($this->logger) $this->logger->info("      ✓ 源站内页克隆成功");
            }
        }
        
        // 5. 应用后处理
        if ($html) {
            if ($this->logger) $this->logger->debug("      开始后处理...");
            $html = $this->postProcess($html, $tdk, $config);
            if ($this->logger) $this->logger->info("      ✓ 渲染完成");
            echo $html;
        } else {
            if ($this->logger) $this->logger->error("      ❌ 渲染失败");
            echo "页面加载失败";
        }
        
        exit;
    }
    
    /**
     * 解析TDK引用
     */
    private function resolveTDK($config) {
        $tdk = $config['tdk'] ?? [];
        
        if (empty($tdk)) {
            if ($this->logger) $this->logger->error("      ❌ TDK为空");
            return null;
        }
        
        // 检查是否是引用格式（例如：jinshentatg168.cn.json）
        $titleValue = $tdk['title'] ?? '';
        
        if (substr($titleValue, -5) === '.json') {
            // 是引用格式，需要从顶级域名配置中读取
            $topDomain = $config['top_domain'] ?? '';
            
            if (empty($topDomain)) {
                if ($this->logger) $this->logger->error("      ❌ 缺少 top_domain 配置");
                return null;
            }
            
            if ($this->logger) $this->logger->debug("      解析TDK引用: {$titleValue}");
            
            // 读取顶级域名配置
            $topConfigFile = $this->base_dir . '/data/domain/' . $topDomain . '.json';
            
            if (!file_exists($topConfigFile)) {
                if ($this->logger) $this->logger->error("      ❌ 顶级域名配置不存在: {$topConfigFile}");
                return null;
            }
            
            $topConfig = json_decode(file_get_contents($topConfigFile), true);
            
            if (empty($topConfig['tdk'])) {
                if ($this->logger) $this->logger->error("      ❌ 顶级域名TDK为空");
                return null;
            }
            
            $realTDK = $topConfig['tdk'];
            
            if ($this->logger) {
                $this->logger->debug("      ✓ 解析TDK成功");
                $this->logger->debug("        标题: " . ($realTDK['title'] ?? ''));
            }
            
            return $realTDK;
        }
        
        // 不是引用格式，直接返回
        return $tdk;
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
        
        // 直接从 mirrors 目录读取镜像配置
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
        
        // 从镜像配置中获取源站域名（支持两种字段名）
        $mirrorSourceDomain = $mirrorConfig['source_domain'] ?? ($mirrorConfig['target_domain'] ?? '');
        
        if ($this->logger) {
            $this->logger->debug("      ✓ 镜像配置读取成功");
            $this->logger->debug("        镜像ID: " . ($mirrorConfig['mirror_id'] ?? '(空)'));
            $this->logger->debug("        源站域名: " . ($mirrorSourceDomain ?: '(空)'));
        }
        
        // 如果 config 中没有 source_domain，从镜像配置中获取
        if (empty($config['source_domain']) && !empty($mirrorSourceDomain)) {
            $config['source_domain'] = $mirrorSourceDomain;
            if ($this->logger) $this->logger->debug("      从镜像配置同步 source_domain 到 config");
        }
        
        return $mirrorConfig;
    }
    
    /**
     * 克隆内页
     */
    private function cloneInnerPage($config, $requestUri, $mirrorConfig) {
        // 优先从 config 读取，然后从 mirrorConfig 读取（支持 source_domain 和 target_domain 两种字段名）
        $sourceDomain = $config['source_domain'] ?? '';
        
        if (empty($sourceDomain)) {
            $sourceDomain = $mirrorConfig['source_domain'] ?? ($mirrorConfig['target_domain'] ?? '');
        }
        
        if ($this->logger) {
            $this->logger->debug("        config[source_domain]: " . ($config['source_domain'] ?? '(空)'));
            $this->logger->debug("        mirrorConfig[source_domain]: " . ($mirrorConfig['source_domain'] ?? '(空)'));
            $this->logger->debug("        mirrorConfig[target_domain]: " . ($mirrorConfig['target_domain'] ?? '(空)'));
            $this->logger->debug("        最终 source_domain: " . ($sourceDomain ?: '(空)'));
        }
        
        if (!$sourceDomain) {
            if ($this->logger) {
                $this->logger->error("        ❌ 源站域名为空");
                $this->logger->warning("        提示：镜像配置文件可能缺少 source_domain 字段");
                $this->logger->warning("        请检查: /data/mirrors/{$config['mirror_id']}/config.json");
            }
            return false;
        }
        
        // 构建源站URL
        $sourceUrl = 'http://' . $sourceDomain . $requestUri;
        
        if ($this->logger) $this->logger->debug("        克隆源站: {$sourceUrl}");
        
        // 设置上下文选项（增加超时和错误处理）
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);
        
        $html = @file_get_contents($sourceUrl, false, $context);
        
        if ($html === false) {
            if ($this->logger) $this->logger->error("        ❌ 克隆失败: 无法访问源站");
            return false;
        }
        
        if (empty($html)) {
            if ($this->logger) $this->logger->error("        ❌ 克隆失败: 源站返回空内容");
            return false;
        }
        
        if ($this->logger) {
            $size = strlen($html);
            $this->logger->debug("        ✓ 克隆成功 (大小: {$size} 字节)");
        }
        
        return $html;
    }
    
    /**
     * 后处理HTML
     */
    private function postProcess($html, $tdk, $config = null) {
        // 加载必要的函数库
        if (file_exists($this->base_dir . '/inc/func.php')) {
            require_once $this->base_dir . '/inc/func.php';
        }
        if (file_exists($this->base_dir . '/inc/coon.php')) {
            require_once $this->base_dir . '/inc/coon.php';
        }
        // ❌ 不加载 add.php，避免重复引入（mirror_router.php 已经加载了友情链接）
        // 友情链接函数 getFriendLinkHTML() 已在 mirror_router.php 中定义
        
        // 0. 域名替换（将源站域名替换为当前域名）
        if ($config && !empty($config['source_domain'])) {
            $sourceDomain = $config['source_domain'];
            $currentDomain = $_SERVER['HTTP_HOST'] ?? '';
            
            if (!empty($currentDomain) && $sourceDomain !== $currentDomain) {
                if ($this->logger) {
                    $this->logger->debug("        域名替换: {$sourceDomain} → {$currentDomain}");
                }
                
                // 替换完整域名（包括协议）
                $html = str_replace('http://' . $sourceDomain, 'http://' . $currentDomain, $html);
                $html = str_replace('https://' . $sourceDomain, 'http://' . $currentDomain, $html);
                $html = str_replace('//' . $sourceDomain, '//' . $currentDomain, $html);
                
                // 替换纯域名（用于某些特殊情况）
                $html = str_replace($sourceDomain, $currentDomain, $html);
            }
        }
        
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
        
        return $html;
    }
}
