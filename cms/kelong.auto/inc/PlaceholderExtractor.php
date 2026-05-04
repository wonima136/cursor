<?php
/**
 * 占位符提取器
 * 将克隆的HTML转换为占位符模板
 */

class PlaceholderExtractor {
    
    /**
     * 提取HTML中的TDK并替换为占位符
     * @param string $html 原始HTML
     * @param array $tdk 当前域名的TDK（用于识别）
     * @return string 替换后的HTML模板
     */
    public function extract($html, $tdk) {
        $template = $html;
        
        // 1. 替换标题为占位符
        $template = preg_replace(
            '@<title>(.*?)</title>@is',
            '<title>{{TITLE}}</title>',
            $template
        );
        
        // 2. 替换关键词meta标签
        $template = preg_replace(
            '@<meta([^>]*?)name=["\']?keywords["\']?([^>]*?)content=["\'][^"\']*["\']([^>]*?)>@is',
            '<meta$1name="keywords"$2content="{{KEYWORDS}}"$3>',
            $template
        );
        
        // 3. 替换描述meta标签
        $template = preg_replace(
            '@<meta([^>]*?)name=["\']?description["\']?([^>]*?)content=["\'][^"\']*["\']([^>]*?)>@is',
            '<meta$1name="description"$2content="{{DESCRIPTION}}"$3>',
            $template
        );
        
        // 4. 删除 <h1> 标签（index.php会动态插入）
        $template = preg_replace('@<h1[^>]*>.*?</h1>@is', '', $template);
        
        // 5. 删除 __overflow_a 链接（AddKeys会动态插入）
        $template = preg_replace('@<a[^>]*?id=["\']__overflow_a["\'][^>]*>.*?</a>@is', '', $template);
        
        // 6. 删除友情链接表格
        $template = preg_replace('@<table[^>]*?id=["\']table1["\'][^>]*>.*?</table>@is', '', $template);
        
        // 7. 删除隐藏的关键词堆砌
        $template = preg_replace('@<div[^>]*?style=["\'][^"\']*display:\s*none[^"\']*["\'][^>]*>.*?</div>@is', '', $template);
        
        // 8. 替换域名引用为占位符
        $currentDomain = $_SERVER['HTTP_HOST'] ?? '';
        if (!empty($currentDomain)) {
            $template = str_replace($currentDomain, '{{DOMAIN}}', $template);
            $template = str_replace('www.' . $currentDomain, '{{WWW_DOMAIN}}', $template);
        }
        
        return $template;
    }
    
    /**
     * 从HTML中提取所有内链
     * @param string $html HTML内容
     * @return array 链接列表
     */
    public function extractLinks($html) {
        $links = [];
        
        // 提取所有 href
        preg_match_all('@href=["\']([^"\']+)["\']@i', $html, $matches);
        
        if (isset($matches[1])) {
            foreach ($matches[1] as $link) {
                // 只保留相对路径和本站链接
                if (strpos($link, 'http') === false && $link !== '#' && $link !== '/') {
                    $links[] = $link;
                }
            }
        }
        
        return array_unique($links);
    }
}
