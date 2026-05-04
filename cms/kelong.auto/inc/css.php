<?php
require('func.php');
require_once(__DIR__ . '/DomainConfigManager.php');
require_once(__DIR__ . '/StaticResourceHandler.php');

ob_clean();

// 获取当前域名和请求URI
$djym = dj();
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

// 支持泛二级域名
require_once(__DIR__ . '/DomainExtractor.php');
$domainExtractor = new DomainExtractor();
$currentHost = $_SERVER['HTTP_HOST'] ?? $djym;
$topDomain = $domainExtractor->extractTopDomain($currentHost);
$isSubdomain = ($currentHost !== $topDomain && !empty($topDomain));

// 检查配置
$configManager = new DomainConfigManager();

// 如果是子域名，尝试读取子域名配置
if ($isSubdomain) {
    $config = $configManager->getConfig($currentHost);
    
    // 如果子域名配置不存在，尝试创建
    if (!$config) {
        $topConfig = $configManager->getConfig($topDomain);
        if ($topConfig) {
            require_once(__DIR__ . '/SubdomainManager.php');
            $subdomainManager = new SubdomainManager();
            $result = $subdomainManager->getOrCreateSubdomainConfig($currentHost, $topDomain);
            
            if ($result && isset($result['config'])) {
                $config = $result['config'];
            } else {
                $config = $topConfig;
            }
        }
    }
} else {
    $config = $configManager->getConfig($djym);
}

// 统一使用镜像模式逻辑（所有资源都保存到 data/mirrors/）
if ($config && $config['mode'] === 'mirror') {
    $mirrorId = $config['mirror_id'];
    $sourceDomain = $config['source_domain'] ?? '';
    
    $resourceHandler = new StaticResourceHandler();
    $result = $resourceHandler->handleResource($mirrorId, $requestUri, $sourceDomain);
    
    if ($result['success']) {
        header('Content-type: text/css; charset=utf-8');
        echo $result['content'];
        exit();
    } else {
        // 失败，返回空CSS
        header('Content-type: text/css; charset=utf-8');
        echo '/* CSS not available */';
        exit();
    }
}

// 如果没有配置或不是镜像模式，使用老版本逻辑（向后兼容）
$cachefile=get_css();
if(is_file($cachefile)){
	header("Content-type: text/css; charset=utf-8");
	$shuchuneirong=file_get_contents($cachefile);
	echo $shuchuneirong;
	exit;
}

$url= str_replace($djym,top_domain($mubiao), $url);

$url= str_replace(" ","%20", $url);
$nr=get_content($url);
if($nr==""){
	 header("HTTP/1.1 404 Not Found");
     header("Status: 404 Not Found");
     include(__DIR__ . "/../404.html");
	 exit();
}
$encode = mb_detect_encoding($nr, array("ASCII","UTF-8","GB2312","GBK","BIG5")); 
if($encode!=="UTF-8"){
$nr=iconv('GB2312','utf-8//IGNORE',$nr);
}
header("Content-type: text/css; charset=utf-8");
$nr= str_replace(top_domain($mubiao),$djym, $nr);
write($cachefile,$nr);
echo $nr;
exit;
?>
