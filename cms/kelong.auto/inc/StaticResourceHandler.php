<?php
/**
 * 静态资源处理器
 * 处理CSS、JS、图片等静态资源的镜像和缓存
 */

class StaticResourceHandler {
    private $base_dir;
    private $mirrors_dir;
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->mirrors_dir = $this->base_dir . '/data/mirrors/';
    }
    
    /**
     * 处理静态资源请求
     * @param string $mirrorId 镜像ID
     * @param string $requestUri 请求路径
     * @param string $sourceDomain 源站域名（可选，从域名配置中获取）
     * @return array ['success' => bool, 'content' => string, 'type' => string]
     */
    public function handleResource($mirrorId, $requestUri, $sourceDomain = '') {
        // 确定资源类型
        $ext = strtolower(pathinfo($requestUri, PATHINFO_EXTENSION));
        $contentType = $this->getContentType($ext);
        
        if (!$contentType) {
            return ['success' => false, 'error' => '不支持的资源类型'];
        }
        
        // 检查资源是否已缓存
        $cachedFile = $this->getCachedResourcePath($mirrorId, $requestUri);
        
        if (file_exists($cachedFile)) {
            // 资源已缓存，直接返回
            return [
                'success' => true,
                'content' => file_get_contents($cachedFile),
                'type' => $contentType,
                'from_cache' => true
            ];
        }
        
        // 如果没有传入源站域名，尝试从镜像配置中读取
        if (empty($sourceDomain)) {
            $mirrorConfig = $this->getMirrorConfig($mirrorId);
            if (!$mirrorConfig) {
                return ['success' => false, 'error' => '镜像配置不存在'];
            }
            
            $sourceDomain = $mirrorConfig['target_domain'] ?? '';
        }
        
        if (empty($sourceDomain)) {
            return ['success' => false, 'error' => '镜像源站不存在'];
        }
        
        $targetDomain = $sourceDomain;
        
        // 克隆资源
        $resourceUrl = 'http://' . $targetDomain . $requestUri;
        error_log("StaticResourceHandler: Cloning resource from: " . $resourceUrl);
        $content = $this->cloneResource($resourceUrl);
        
        if ($content === false) {
            error_log("StaticResourceHandler: Failed to clone resource: " . $resourceUrl);
            return ['success' => false, 'error' => '资源克隆失败'];
        }
        
        error_log("StaticResourceHandler: Successfully cloned resource, size: " . strlen($content) . " bytes");
        
        // 处理CSS和JS中的资源引用
        if ($ext === 'css') {
            $content = $this->processCss($content, $targetDomain, $requestUri);
        } elseif ($ext === 'js') {
            $content = $this->processJs($content, $targetDomain);
        }
        
        // 保存到镜像目录
        $this->saveResource($mirrorId, $requestUri, $content);
        
        return [
            'success' => true,
            'content' => $content,
            'type' => $contentType,
            'from_cache' => false
        ];
    }
    
    /**
     * 获取资源的Content-Type
     */
    private function getContentType($ext) {
        $types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject'
        ];
        
        return $types[$ext] ?? null;
    }
    
    /**
     * 获取缓存的资源路径
     */
    private function getCachedResourcePath($mirrorId, $requestUri) {
        $mirrorDir = $this->mirrors_dir . $mirrorId . '/';
        
        // 生成安全的文件路径
        $cleanPath = trim($requestUri, '/');
        $cleanPath = str_replace(['..', '//'], ['', '/'], $cleanPath);
        
        return $mirrorDir . 'static/' . $cleanPath;
    }
    
    /**
     * 克隆资源（使用优化的curl参数）
     */
    private function cloneResource($url) {
        $ch = curl_init();
        $useragent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if (($httpCode == 200 || $httpCode == 301 || $httpCode == 302) && $content !== false) {
            return $content;
        }
        
        return false;
    }
    
    /**
     * 保存资源到镜像目录
     */
    private function saveResource($mirrorId, $requestUri, $content) {
        $filePath = $this->getCachedResourcePath($mirrorId, $requestUri);
        $dir = dirname($filePath);
        
        // 确保目录存在
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // 保存文件
        file_put_contents($filePath, $content);
        
        error_log("[静态资源] 已保存: {$requestUri} → {$filePath}");
        
        return true;
    }
    
    /**
     * 获取镜像配置
     */
    private function getMirrorConfig($mirrorId) {
        $configFile = $this->mirrors_dir . $mirrorId . '/config.json';
        
        if (!file_exists($configFile)) {
            return null;
        }
        
        return json_decode(file_get_contents($configFile), true);
    }
    
    /**
     * 处理CSS文件中的资源引用
     */
    private function processCss($content, $sourceDomain, $cssPath) {
        $currentDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // 1. 替换域名
        $content = str_replace($sourceDomain, $currentDomain, $content);
        $content = str_replace('www.' . $sourceDomain, 'www.' . $currentDomain, $content);
        
        // 2. 处理 url() 中的绝对路径
        $content = preg_replace(
            '@url\(["\']?http://[^)]+["\']?\)@i',
            'url(#)',
            $content
        );
        $content = preg_replace(
            '@url\(["\']?https://[^)]+["\']?\)@i',
            'url(#)',
            $content
        );
        
        // 3. 处理 @import
        $content = preg_replace(
            '@\@import\s+["\']http://[^"\']+["\']@i',
            '@import "#"',
            $content
        );
        $content = preg_replace(
            '@\@import\s+["\']https://[^"\']+["\']@i',
            '@import "#"',
            $content
        );
        
        // 4. 处理 // 开头的URL
        $content = str_replace('url(//', 'url(http://', $content);
        $content = str_replace('url("//', 'url("http://', $content);
        $content = str_replace("url('//", "url('http://", $content);
        
        return $content;
    }
    
    /**
     * 处理JS文件中的资源引用
     */
    private function processJs($content, $sourceDomain) {
        $currentDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // 1. 替换域名
        $content = str_replace($sourceDomain, $currentDomain, $content);
        $content = str_replace('www.' . $sourceDomain, 'www.' . $currentDomain, $content);
        
        // 2. 处理常见的JS中的域名引用
        // 替换字符串中的域名
        $content = str_replace('"http://' . $sourceDomain, '"http://' . $currentDomain, $content);
        $content = str_replace("'http://" . $sourceDomain, "'http://" . $currentDomain, $content);
        $content = str_replace('"https://' . $sourceDomain, '"https://' . $currentDomain, $content);
        $content = str_replace("'https://" . $sourceDomain, "'https://" . $currentDomain, $content);
        
        // 3. 处理 // 开头的URL
        $content = str_replace('"//', '"http://', $content);
        $content = str_replace("'//", "'http://", $content);
        
        // 4. 处理可能的敏感关键词（参考旧版本）
        $yuan = array('/iPhone/i', '/eval/i', '/ipod/i', '/android/i', '/ios/i', '/phone/i', '/webos/i', '/mobile/i', '/ucweb/i', '/midp/i', '/windows ce/i', '/location/i', '/ipad/i');
        $hou = array('iphones', 'evals', 'ipods', 'androids', 'ioses', 'phones', 'weboses', 'mobiles', 'ucwebs', 'midps', 'windows ces', 'locations', 'ipads');
        $content = preg_replace($yuan, $hou, $content);
        
        return $content;
    }
    
    /**
     * 判断是否为静态资源请求
     */
    public static function isStaticResource($requestUri) {
        $ext = strtolower(pathinfo($requestUri, PATHINFO_EXTENSION));
        
        $staticExts = [
            'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 
            'ico', 'woff', 'woff2', 'ttf', 'eot', 'mp4', 'mp3', 'pdf'
        ];
        
        return in_array($ext, $staticExts);
    }
}
