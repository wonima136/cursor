<?php
/**
 * 镜像路由器
 * 检测配置格式，决定使用克隆模式还是镜像模式
 */

// 在 index.php 最开头引入此文件
// require_once __DIR__ . '/mirror_router.php';

// ===== 系统文件夹排除列表 =====
// 这些路径不应该被镜像路由处理，直接跳过
$excluded_paths = [
    '/data/',           // 后台管理和数据目录
    '/inc/',            // 核心函数库
    '/cachefile_yuan/', // 缓存目录
    '/vendor/',         // 第三方库
    '/node_modules/',   // Node模块
    '/static/',         // 静态资源目录（自有资源）
];

// ===== 第一步：在页面最开头引入 add.php =====
// 注意：必须在任何输出之前引入
require_once __DIR__ . '/add.php';
// 检查当前请求路径是否在排除列表中
$request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
foreach ($excluded_paths as $excluded_path) {
    if (strpos($request_uri, $excluded_path) === 0) {
        // 在排除列表中，不执行镜像路由逻辑
        return;
    }
}
// ===== 排除逻辑结束 =====

// 记录所有请求（仅记录非排除路径）
// 已移除日志记录功能

// ===== 友情链接代码已移除（调试用） =====
// ===== 友情链接代码已移除（调试用） =====
// ===== 友情链接代码已移除（调试用） =====
// ===== 友情链接代码已移除（调试用） =====
// ===== 友情链接代码已移除（调试用） =====
if (!function_exists('getFriendLinkHTML')) {
    function getFriendLinkHTML() {
        global $_add_friendlink_html;
        return isset($_add_friendlink_html) ? $_add_friendlink_html : '';
    }
}
// ===== 友情链接加载结束 =====

// ⚠️ 重要：在加载 func.php 之前，先设置 $djym，避免 duixiang() 触发自动配置生成
$djym = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

// 获取当前域名
require_once __DIR__ . '/inc/func.php';
require_once __DIR__ . '/inc/DomainConfigManager.php';
require_once __DIR__ . '/inc/DomainGroupManager.php';
require_once __DIR__ . '/inc/StaticResourceHandler.php';
require_once __DIR__ . '/inc/DomainExtractor.php';
require_once __DIR__ . '/inc/SubdomainRouter.php';
$requestUri = $request_uri;

// 提取顶级域名
$domainExtractor = DomainExtractor::getInstance();
$currentHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $djym;
$topDomain = $domainExtractor->extractTopDomain($currentHost);

// 判断是否为子域名
$isSubdomain = ($currentHost !== $topDomain && !empty($topDomain));

// 初始化管理器
$configManager = new DomainConfigManager();
$groupManager = new DomainGroupManager();

// ===== 优先处理静态资源请求 =====
if (StaticResourceHandler::isStaticResource($requestUri)) {
    // 静态资源使用旧逻辑（暂时保留）
    require_once __DIR__ . '/inc/SubdomainManager.php';
    $subdomainManager = new SubdomainManager();
    
    $config = null;
    if ($isSubdomain) {
        $result = $subdomainManager->getOrCreateSubdomainConfig($currentHost, $topDomain);
        $config = isset($result['config']) ? $result['config'] : $configManager->getConfig($topDomain);
    } else {
        $config = $configManager->getConfig($djym);
    }
    
    if ($config && $config['mode'] === 'mirror') {
        $mirrorId = $config['mirror_id'];
        $sourceDomain = isset($config['source_domain']) ? $config['source_domain'] : '';
        
        $resourceHandler = new StaticResourceHandler();
        $result = $resourceHandler->handleResource($mirrorId, $requestUri, $sourceDomain);
        
        if ($result['success']) {
            header('Content-Type: ' . $result['type']);
            echo $result['content'];
            exit();
        }
    }
    
    header('Content-Type: text/plain');
    echo '/* Resource not available */';
    exit();
}
// ===== 静态资源处理结束 =====

// ===== 统一路由：判断域名模式并分发 =====
error_log("[路由] 开始处理请求: $currentHost, URI: $requestUri");

// 1. 读取顶级域名配置
$topConfig = $configManager->getConfig($topDomain);

// 2. 检查该域名所属的分组
$groupInfo = $groupManager->getDomainGroup($topDomain);

// 3. 如果顶级域名配置不存在，但有分组配置，自动生成配置
if (!$topConfig && $groupInfo) {
    error_log("[路由] 顶级域名配置不存在，尝试自动生成: $topDomain");
    
    require_once __DIR__ . '/inc/DomainConfigGenerator.php';
    $generator = new DomainConfigGenerator();
    
    try {
        $topConfig = $generator->generateFromGroup($topDomain, $groupInfo);
        
        if ($topConfig) {
            error_log("[路由] ✓ 顶级域名配置自动生成成功");
        } else {
            error_log("[路由] ✗ 顶级域名配置生成失败，回退到旧系统");
            return;
        }
    } catch (Exception $e) {
        error_log("[路由] ✗ 配置生成异常: " . $e->getMessage());
        return;
    }
}

// 4. 如果仍然没有配置，回退到旧系统
if (!$topConfig) {
    error_log("[路由] 顶级域名配置不存在: $topDomain，回退到旧系统");
    return;  // 继续执行 index.php 的旧逻辑
}

// 3. 如果是子域名且有分组配置，使用新架构路由
if ($isSubdomain && $groupInfo && isset($groupInfo['subdomain_config'])) {
    error_log("[路由] 子域名请求，使用新架构路由");
    
    $router = new SubdomainRouter();
    $handled = $router->route($currentHost, $topDomain, $topConfig, $groupInfo, $requestUri);
    
    if ($handled) {
        exit();
    }
    
    error_log("[路由] 新架构路由失败，回退到旧系统");
}

// 4. 主域名或未配置分组的域名，使用现有逻辑
error_log("[路由] 主域名请求或未配置分组，使用现有逻辑");

$config = $configManager->getConfig($djym);

if (!$config) {
    error_log("[路由] 域名配置不存在: $djym，回退到旧系统");
    return;  // 继续执行 index.php 的旧逻辑
}

// 检测配置模式
if ($config['mode'] === 'clone') {
    // 克隆模式 - 继续使用原 index.php
    return;
} elseif ($config['mode'] === 'mirror') {
    // 镜像模式 - 使用镜像系统
    require_once __DIR__ . '/inc/MirrorManager.php';
    require_once __DIR__ . '/inc/DomainGroupManager.php';
    
    $t1 = microtime(true);
    
    // 读取配置
    $mirrorId = $config['mirror_id'];
    $tdk = $config['tdk'];
    
    // 初始化
    $mirrorManager = new MirrorManager();
    $groupManager = new DomainGroupManager();
    
    // 检查分组功能
    $groupInfo = $groupManager->getDomainGroup($djym);
    $enableSwitch = $groupInfo && isset($groupInfo['clone_source_switch']) && $groupInfo['clone_source_switch']['enabled'];
    
    // 所有模式都不使用缓存，直接从模板渲染
    error_log("[路由] 域名: {$djym}, 请求URI: {$requestUri}, 模式: 模板渲染（无缓存）");
    
    // 检查镜像切换功能
    if ($enableSwitch) {
        // 只在访问首页时增加计数，避免静态资源请求导致计数异常
        $isHomepage = ($requestUri === '/' || $requestUri === '/index.html' || $requestUri === '/index.php');
        
        if ($isHomepage) {
            // 增加计数
            $visitCount = $groupManager->incrementVisitCounter($djym);
            $trigger = $groupInfo['clone_source_switch']['trigger_visits'];
        } else {
            // 非首页访问，只读取计数不增加
            $counter = $groupManager->getVisitCounter($djym);
            $visitCount = isset($counter['visit_count']) ? $counter['visit_count'] : 0;
            $trigger = $groupInfo['clone_source_switch']['trigger_visits'];
        }
        
        if ($isHomepage && $visitCount >= $trigger) {
            
            // 获取新镜像ID
            $newMirrorId = isset($groupInfo['clone_source_switch']['target_domain']) ? $groupInfo['clone_source_switch']['target_domain'] : '';
            
            if (empty($newMirrorId)) {
                $newMirror = $mirrorManager->getRandomMirror();
                $newMirrorId = $newMirror ? $newMirror['mirror_id'] : $mirrorId;
            }
            
            // 更新配置中的镜像ID和源站域名
            $configManager->updateMirrorId($djym, $newMirrorId);
            
            
            // 重新读取配置，确保 mirror_id 和 source_domain 都是最新的
            $config = $configManager->getConfig($djym);
            $mirrorId = $config['mirror_id'];
            
            
            // 先读取当前计数器，保存切换次数
            $counter = $groupManager->getVisitCounter($djym);
            $totalSwitches = (isset($counter['total_clone_source_switches']) ? $counter['total_clone_source_switches'] : 0) + 1;
            
            // 重置访问计数器
            $reset_counter = isset($groupInfo['clone_source_switch']['reset_counter']) ? $groupInfo['clone_source_switch']['reset_counter'] : true;
            if ($reset_counter) {
                $groupManager->resetVisitCounter($djym);
                // 重新读取被重置后的计数器
                $counter = $groupManager->getVisitCounter($djym);
            }
            
            // 更新切换信息（保持累加的切换次数）
            $counter['total_clone_source_switches'] = $totalSwitches;
            $counter['current_clone_source'] = $newMirrorId;
            $counter['last_clone_source_switch'] = date('Y-m-d H:i:s');
            
            $counterFile = __DIR__ . '/data/domain_groups/visit_counters/' . $djym . '.json';
            $counterDir = dirname($counterFile);
            if (!is_dir($counterDir)) {
                mkdir($counterDir, 0755, true);
            }
            file_put_contents($counterFile, json_encode($counter, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            
        }
    }
    
    // 尝试渲染镜像
    $html = $mirrorManager->render($mirrorId, $tdk, $requestUri);
    
    if ($html === false) {
        // 镜像渲染失败（可能是内页不存在）
        if ($requestUri !== '/' && $requestUri !== '/index.html') {
            // 是内页，需要克隆
            
            // 构建克隆URL
            $mirrorsDir = __DIR__ . '/data/mirrors/';
            $mirrorConfigFile = $mirrorsDir . $mirrorId . '/config.json';
            
            if (!file_exists($mirrorConfigFile)) {
                http_response_code(404);
                exit("镜像配置不存在");
            }
            
            $mirrorConfig = json_decode(file_get_contents($mirrorConfigFile), true);
            $targetDomain = isset($mirrorConfig['target_domain']) ? $mirrorConfig['target_domain'] : '';
            
            if (!empty($targetDomain)) {
                $cloneUrl = 'http://' . $targetDomain . $requestUri;
                
                
                // 克隆内页（使用优化的curl参数）
                $curl = curl_init();
                $useragent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
                
                $headers = array(
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                    'Accept-Encoding: gzip, deflate',
                    'Connection: keep-alive',
                    'Cache-Control: max-age=0'
                );

                curl_setopt($curl, CURLOPT_URL, $cloneUrl);
                curl_setopt($curl, CURLOPT_HEADER, 0);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($curl, CURLOPT_TIMEOUT, 8);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($curl, CURLOPT_USERAGENT, $useragent);
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($curl, CURLOPT_ENCODING, '');
                curl_setopt($curl, CURLOPT_MAXREDIRS, 2);
                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($curl, CURLOPT_NOSIGNAL, 1);
                
                $clonedHtml = curl_exec($curl);
                $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
                
                // 检查HTTP状态码
                if ($httpCode != 200 && $httpCode != 301 && $httpCode != 302) {
                    $clonedHtml = false;
                }
                
                if (!empty($clonedHtml) && strlen($clonedHtml) > 1024) {
                    
                    // 保存为占位符模板
                    $mirrorManager->saveInnerPage($mirrorId, $requestUri, $clonedHtml, $tdk);
                    
                    // 重新渲染（现在内页已存在）
                    $html = $mirrorManager->render($mirrorId, $tdk, $requestUri);
                    
                    if ($html === false) {
                        // 渲染失败，使用降级处理
                        error_log("[镜像路由] 渲染失败，启动降级处理");
                        require_once __DIR__ . '/inc/InnerPageFallback.php';
                        $fallback = new InnerPageFallback($mirrorManager);
                        $html = $fallback->handle($mirrorId, $requestUri, $tdk);
                    }
                } else {
                    // 内页克隆失败，使用降级处理
                    error_log("[镜像路由] 内页克隆失败，启动降级处理");
                    require_once __DIR__ . '/inc/InnerPageFallback.php';
                    $fallback = new InnerPageFallback($mirrorManager);
                    $html = $fallback->handle($mirrorId, $requestUri, $tdk);
                }
            } else {
                // 镜像配置不存在，使用降级处理
                error_log("[镜像路由] 镜像配置不存在，启动降级处理");
                require_once __DIR__ . '/inc/InnerPageFallback.php';
                $fallback = new InnerPageFallback($mirrorManager);
                $html = $fallback->handle($mirrorId, $requestUri, $tdk);
            }
        } else {
            // 首页渲染失败，显示错误
            http_response_code(500);
            exit("镜像渲染失败");
        }
    }
    
    // 后处理（MirrorManager已经插入了<h1>标签）
    require_once __DIR__ . '/inc/func.php';
    require_once __DIR__ . '/inc/coon.php';
    
    // 1. 插入HTML结构干扰标签（rand_body_label）
    if (function_exists('rand_body_label')) {
        $interferenceHtml = rand_body_label();
        // 在 <body> 后插入干扰标签
        $html = preg_replace("/<body(.*)>/i", "<body$1>\n" . $interferenceHtml, $html, 1);
    }
    
    // 2. 繁体转换（转换整个HTML页面的文本，但不包括后面插入的TDK和H1）
    if (isset($chinese)) {
        $html = $chinese->gb2312_big5($html);
    }
    
    // 3. 标题和关键词转码（unicode_encode）- 在繁体转换之后
    $encodedTitle = unicode_encode($tdk['title']);
    $encodedKeywords = unicode_encode($tdk['keywords']);
    $encodedDescription = unicode_encode($tdk['description']);
    
    // 3.5. 替换H1占位符（使用转码后的标题）
    $html = str_replace('<!--H1_PLACEHOLDER-->', '<h1>' . $encodedTitle . '</h1>', $html);
    
    // 替换HTML中的TDK为转码后的版本
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
    
    // 3. 应用拼音注释（动态处理，不保存文件）
    if (function_exists('applyAnnotations')) {
        $html = applyAnnotations($html);
    }
    
    // 4. 插入 __overflow_a 链接（AddKeys功能）
    if (function_exists('AddKeys')) {
        $guanjianziold = $tdk['keywords'];
        $html = AddKeys($html, $guanjianziold);
    }
    
    // 5. 插入友情链接（在最后一步，输出前处理）
    if (function_exists('getFriendLinkHTML')) {
        $friendLinksHtml = getFriendLinkHTML();
        if (!empty($friendLinksHtml)) {
            // 先移除可能存在的旧友情链接（避免重复）
            $friendlink_pattern = '@<table[^>]*id=["\']table1["\'][^>]*>[\s\S]*?</table>@i';
            $html = preg_replace($friendlink_pattern, '', $html);
            
            // 循环清理，确保移除所有table1
            $max_iterations = 10;
            $iteration = 0;
            while (stripos($html, 'id="table1"') !== false && $iteration < $max_iterations) {
                $html = preg_replace($friendlink_pattern, '', $html);
                $iteration++;
            }
            
            // 然后插入新的友情链接到 </body> 前
            if (stripos($html, '</body>') !== false) {
                $html = str_replace("</body>", $friendLinksHtml . "\n</body>", $html);
            } else {
                // 如果找不到 </body> 标签，就在页面最底部追加
                $html = $html . "\n" . $friendLinksHtml;
            }
        }
    }
    
    // 不再写入缓存，所有模式都直接从模板渲染
    
    // 输出
    echo $html;
    exit();  // 阻止继续执行 index.php
}

// 如果不是镜像模式，继续执行原 index.php
