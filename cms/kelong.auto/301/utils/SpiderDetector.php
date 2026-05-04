<?php
/**
 * 蜘蛛识别工具类
 */

namespace Redirect301\Utils;

class SpiderDetector
{
    // 蜘蛛类型常量（仅保留百度、谷歌、搜狗）
    const TYPE_BAIDU = 'baidu_spider';
    const TYPE_GOOGLE = 'google_spider';
    const TYPE_SOGOU = 'sogou_spider';
    const TYPE_OTHER = 'other_spider';
    const TYPE_USER = 'normal_user';
    
    // 百度蜘蛛子类型（仅保留 PC 和移动）
    const BAIDU_MOBILE = 'baidu_mobile';
    const BAIDU_PC = 'baidu_pc';
    
    /**
     * 识别蜘蛛类型
     * 
     * @param string|null $userAgent User-Agent字符串，如果为null则从$_SERVER获取
     * @return array ['type' => '蜘蛛类型', 'subtype' => '子类型或null']
     */
    public static function detect($userAgent = null)
    {
        if ($userAgent === null) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }
        
        $userAgentLower = strtolower($userAgent);
        
        // 首先检查是否是百度蜘蛛
        if (strpos($userAgentLower, 'baiduspider') !== false || 
            strpos($userAgentLower, 'baidu') !== false) {
            $subtype = self::detectBaiduType($userAgent);
            return [
                'type' => self::TYPE_BAIDU,
                'subtype' => $subtype
            ];
        }
        
        // 其他蜘蛛类型（仅保留谷歌和搜狗）
        $spiders = [
            self::TYPE_GOOGLE => ['googlebot', 'google'],
            self::TYPE_SOGOU => ['sogou'],
        ];
        
        foreach ($spiders as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($userAgentLower, $keyword) !== false) {
                    return ['type' => $type, 'subtype' => null];
                }
            }
        }
        
        // 检查是否是其他蜘蛛
        $botKeywords = ['spider', 'bot', 'crawler', 'slurp', 'yahoo'];
        foreach ($botKeywords as $keyword) {
            if (strpos($userAgentLower, $keyword) !== false) {
                return ['type' => self::TYPE_OTHER, 'subtype' => null];
            }
        }
        
        // 普通用户
        return ['type' => self::TYPE_USER, 'subtype' => null];
    }
    
    /**
     * 识别百度蜘蛛细分类型
     * 1. 移动端: UA 包含 Mobile/Android/iPhone 等
     * 2. PC端: 不包含移动端特征的百度蜘蛛
     * 
     * @param string $userAgent User-Agent字符串
     * @return string 百度蜘蛛子类型
     */
    private static function detectBaiduType($userAgent)
    {
        $userAgentLower = strtolower($userAgent);
        
        // 判断是否为移动端
        // 移动端特征: mobile, android, iphone, ipad, ipod
        $mobileKeywords = ['mobile', 'android', 'iphone', 'ipad', 'ipod'];
        foreach ($mobileKeywords as $keyword) {
            if (strpos($userAgentLower, $keyword) !== false) {
                return self::BAIDU_MOBILE;
            }
        }
        
        // 其他情况为PC端
        return self::BAIDU_PC;
    }
    
    /**
     * 获取蜘蛛类型的显示名称
     * 
     * @param array $spiderInfo 蜘蛛信息数组
     * @return string 显示名称
     */
    public static function getDisplayName($spiderInfo)
    {
        if (!$spiderInfo) {
            return '未知';
        }
        
        $type = $spiderInfo['type'] ?? '';
        $subtype = $spiderInfo['subtype'] ?? '';
        
        // 百度蜘蛛细分
        if ($type === self::TYPE_BAIDU) {
            switch ($subtype) {
                case self::BAIDU_MOBILE:
                    return '百度移动';
                case self::BAIDU_PC:
                    return '百度PC';
                default:
                    return '百度蜘蛛';
            }
        }
        
        // 其他蜘蛛
        $names = [
            self::TYPE_GOOGLE => '谷歌蜘蛛',
            self::TYPE_SOGOU => '搜狗蜘蛛',
            self::TYPE_OTHER => '其他蜘蛛',
            self::TYPE_USER => '普通用户',
        ];
        
        return $names[$type] ?? '未知';
    }
    
    /**
     * 判断是否是蜘蛛
     * 
     * @param array $spiderInfo 蜘蛛信息数组
     * @return bool 是否是蜘蛛
     */
    public static function isSpider($spiderInfo)
    {
        $type = $spiderInfo['type'] ?? '';
        return $type !== self::TYPE_USER;
    }
    
    /**
     * 判断是否是百度蜘蛛
     * 
     * @param array $spiderInfo 蜘蛛信息数组
     * @return bool 是否是百度蜘蛛
     */
    public static function isBaiduSpider($spiderInfo)
    {
        $type = $spiderInfo['type'] ?? '';
        return $type === self::TYPE_BAIDU;
    }
    
    /**
     * 检查百度蜘蛛是否匹配配置
     * 
     * @param array $spiderInfo 蜘蛛信息
     * @param array $config 百度蜘蛛配置 ['enabled' => bool, 'baidu_pc' => bool, 'baidu_mobile' => bool]
     * @return bool 是否匹配
     */
    public static function matchBaiduConfig($config, $spiderInfo)
    {
        $type = $spiderInfo['type'] ?? '';
        $subtype = $spiderInfo['subtype'] ?? '';
        
        // 只对百度蜘蛛进行筛选
        if ($type !== self::TYPE_BAIDU) {
            return true;
        }
        
        // 如果启用了百度蜘蛛，检查是否有子类型被启用
        if (!empty($config['baidu_spider'])) {
            // 检查是否至少有一个子类型被启用
            $hasEnabledSubtype = !empty($config['baidu_mobile']) 
                              || !empty($config['baidu_pc']);
            
            // 如果没有启用任何子类型，拒绝所有百度蜘蛛
            if (!$hasEnabledSubtype) {
                return false;
            }
            
            // 检查当前蜘蛛的子类型是否被启用
            if ($subtype === self::BAIDU_MOBILE && !empty($config['baidu_mobile'])) {
                return true;
            }
            if ($subtype === self::BAIDU_PC && !empty($config['baidu_pc'])) {
                return true;
            }
            
            // 子类型未被启用
            return false;
        }
        
        // 百度蜘蛛未启用
        return false;
    }
}

