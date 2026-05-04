<?php
/**
 * 占位符处理工具类
 */

namespace Redirect301\Utils;

class PlaceholderHelper
{
    private static $config = null;
    
    /**
     * 加载占位符配置
     */
    private static function loadConfig()
    {
        if (self::$config === null) {
            $file = defined('_REDIRECT301_PLACEHOLDERS_FILE_') 
                ? _REDIRECT301_PLACEHOLDERS_FILE_ 
                : REDIRECT301_ROOT . '/admin/data/placeholders.json';
            
            if (file_exists($file)) {
                $data = json_decode(file_get_contents($file), true);
                self::$config = is_array($data) ? $data : [];
            } else {
                self::$config = [];
            }
        }
        
        return self::$config;
    }
    
    /**
     * 替换URL中的所有占位符
     * 
     * @param string $url 包含占位符的URL
     * @return string 替换后的URL
     */
    public static function replace($url)
    {
        // 如果URL中没有占位符标记，直接返回
        if (strpos($url, '{') === false) {
            return $url;
        }
        
        // 时间类占位符
        $url = self::replaceTime($url);
        
        // 随机数字
        $url = self::replaceRandomNumbers($url);
        
        // 随机字母
        $url = self::replaceRandomLetters($url);
        
        // 随机字符
        $url = self::replaceRandomChars($url);
        
        // 自定义占位符
        $url = self::replaceCustom($url);
        
        return $url;
    }
    
    /**
     * 替换时间类占位符
     */
    private static function replaceTime($url)
    {
        $url = str_replace('{年}', date('Y'), $url);
        $url = str_replace('{月}', date('m'), $url);
        $url = str_replace('{日}', date('d'), $url);
        
        return $url;
    }
    
    /**
     * 替换随机数字占位符
     * 例如：{数字8} → 12345678
     */
    private static function replaceRandomNumbers($url)
    {
        return preg_replace_callback('/{数字(\d+)}/', function($matches) {
            $length = intval($matches[1]);
            $result = '';
            for ($i = 0; $i < $length; $i++) {
                $result .= mt_rand(0, 9);
            }
            return $result;
        }, $url);
    }
    
    /**
     * 替换随机字母占位符
     */
    private static function replaceRandomLetters($url)
    {
        // 小写字母 {小写字母8}
        $url = preg_replace_callback('/{小写字母(\d+)}/', function($matches) {
            $length = intval($matches[1]);
            $chars = 'abcdefghijklmnopqrstuvwxyz';
            $result = '';
            for ($i = 0; $i < $length; $i++) {
                $result .= $chars[mt_rand(0, 25)];
            }
            return $result;
        }, $url);
        
        // 大写字母 {大写字母8}
        $url = preg_replace_callback('/{大写字母(\d+)}/', function($matches) {
            $length = intval($matches[1]);
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $result = '';
            for ($i = 0; $i < $length; $i++) {
                $result .= $chars[mt_rand(0, 25)];
            }
            return $result;
        }, $url);
        
        // 大小写字母 {大小写字母8}
        $url = preg_replace_callback('/{大小写字母(\d+)}/', function($matches) {
            $length = intval($matches[1]);
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $result = '';
            for ($i = 0; $i < $length; $i++) {
                $result .= $chars[mt_rand(0, 51)];
            }
            return $result;
        }, $url);
        
        return $url;
    }
    
    /**
     * 替换随机字符占位符（字母+数字）
     */
    private static function replaceRandomChars($url)
    {
        // 小写随机字符 {小写随机字符8}
        $url = preg_replace_callback('/{小写随机字符(\d+)}/', function($matches) {
            $length = intval($matches[1]);
            $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
            $result = '';
            for ($i = 0; $i < $length; $i++) {
                $result .= $chars[mt_rand(0, 35)];
            }
            return $result;
        }, $url);
        
        // 大写随机字符 {大写随机字符8}
        $url = preg_replace_callback('/{大写随机字符(\d+)}/', function($matches) {
            $length = intval($matches[1]);
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $result = '';
            for ($i = 0; $i < $length; $i++) {
                $result .= $chars[mt_rand(0, 35)];
            }
            return $result;
        }, $url);
        
        // 大小写随机字符 {大小写随机字符8}
        $url = preg_replace_callback('/{大小写随机字符(\d+)}/', function($matches) {
            $length = intval($matches[1]);
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $result = '';
            for ($i = 0; $i < $length; $i++) {
                $result .= $chars[mt_rand(0, 61)];
            }
            return $result;
        }, $url);
        
        return $url;
    }
    
    /**
     * 替换自定义占位符
     * 支持 {自定义参数1} ~ {自定义参数30}
     */
    private static function replaceCustom($url)
    {
        $config = self::loadConfig();
        
        // 使用 preg_replace_callback 确保每次匹配都重新随机
        for ($i = 1; $i <= 30; $i++) {
            $key = "自定义参数{$i}";
            if (isset($config[$key]) && !empty($config[$key])) {
                $value = $config[$key];
                
                // 先按换行分割，再按逗号分割
                $items = preg_split('/[\n,]+/', $value);
                $items = array_map('trim', $items);
                $items = array_filter($items, function($v) { return $v !== ''; });
                
                if (!empty($items)) {
                    // 使用 preg_replace_callback 确保每次匹配都重新随机
                    $pattern = '/{' . preg_quote($key, '/') . '}/';
                    $url = preg_replace_callback($pattern, function($matches) use ($items) {
                        // 每次匹配都重新随机选择
                        return $items[array_rand($items)];
                    }, $url);
                }
            }
        }
        
        return $url;
    }
    
    /**
     * 检查字符串是否包含占位符
     * 
     * @param string $str 字符串
     * @return bool 是否包含占位符
     */
    public static function hasPlaceholder($str)
    {
        return strpos($str, '{') !== false && strpos($str, '}') !== false;
    }
    
    /**
     * 获取字符串中的所有占位符
     * 
     * @param string $str 字符串
     * @return array 占位符列表
     */
    public static function extractPlaceholders($str)
    {
        preg_match_all('/{([^}]+)}/', $str, $matches);
        return $matches[1] ?? [];
    }
}

