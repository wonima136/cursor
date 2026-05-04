<?php
error_reporting(E_ERROR | E_PARSE);
@set_time_limit(120);
@ini_set('display_errors', '0');
@ini_set('pcre.backtrack_limit', 1000000);
date_default_timezone_set('PRC');
header("Content-type: text/html; charset=utf-8");
require_once('coon.php');
require_once('jianti.php');
$chinese = new utf8_chinese();

function top_domain($url)
{
    $host = strtolower($url);
    if (strpos($host, '/') !== false) {
        $parse = @parse_url($host);
        $host  = $parse['host'];
    }
    
    // 双后缀域名列表（完整版，重点针对常用后缀）
    $double_suffix_domains = array(
        // 中国域名（重点）
        'com.cn', 'net.cn', 'org.cn', 'edu.cn', 'gov.cn', 'mil.cn',
        'ah.cn', 'bj.cn', 'cq.cn', 'fj.cn', 'gd.cn', 'gs.cn', 'gz.cn', 'gx.cn', 
        'ha.cn', 'hb.cn', 'he.cn', 'hi.cn', 'hl.cn', 'hn.cn', 'jl.cn', 'js.cn', 
        'jx.cn', 'ln.cn', 'nm.cn', 'nx.cn', 'qh.cn', 'sc.cn', 'sd.cn', 'sh.cn', 
        'sn.cn', 'sx.cn', 'tj.cn', 'xj.cn', 'xz.cn', 'yn.cn', 'zj.cn', 'hk.cn', 'mo.cn', 'tw.cn','ac.cn',
        
        // 英国域名
        'com.uk', 'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'net.uk', 'ltd.uk', 'plc.uk', 'me.uk', 'mil.uk',
        
        // 香港域名
        'com.hk', 'org.hk', 'net.hk', 'edu.hk', 'gov.hk', 'idv.hk',
        
        // 台湾域名
        'com.tw', 'org.tw', 'net.tw', 'edu.tw', 'gov.tw', 'idv.tw',
        
        // 澳大利亚域名
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'asn.au', 'id.au',
        
        // 新加坡域名
        'com.sg', 'org.sg', 'net.sg', 'edu.sg', 'gov.sg', 'per.sg',
        
        // 马来西亚域名
        'com.my', 'org.my', 'net.my', 'edu.my', 'gov.my', 'mil.my', 'name.my',
        
        // 日本域名
        'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'go.jp', 'ed.jp', 'gr.jp', 'lg.jp',
        
        // 韩国域名
        'co.kr', 'or.kr', 'ne.kr', 're.kr', 'pe.kr', 'go.kr', 'mil.kr', 'ac.kr',
        
        // 新西兰域名
        'co.nz', 'org.nz', 'net.nz', 'ac.nz', 'govt.nz', 'school.nz',
        
        // 南非域名
        'co.za', 'org.za', 'net.za', 'ac.za', 'gov.za', 'edu.za', 'law.za', 'mil.za',
        
        // 印度域名
        'co.in', 'org.in', 'net.in', 'ac.in', 'edu.in', 'gov.in', 'mil.in', 'firm.in', 'gen.in', 'ind.in',
        
        // 巴西域名
        'com.br', 'org.br', 'net.br', 'edu.br', 'gov.br', 'mil.br',
        
        // 墨西哥域名
        'com.mx', 'org.mx', 'net.mx', 'edu.mx', 'gob.mx',
        
        // 阿根廷域名
        'com.ar', 'org.ar', 'net.ar', 'edu.ar', 'gov.ar', 'mil.ar', 'int.ar',
        
        // 土耳其域名
        'com.tr', 'org.tr', 'net.tr', 'edu.tr', 'gov.tr', 'mil.tr', 'biz.tr', 'info.tr',
        
        // 乌克兰域名
        'com.ua', 'org.ua', 'net.ua', 'edu.ua', 'gov.ua',
        
        // 其他常用双后缀域名
        'com.de', 'com.fr', 'com.es', 'com.it', 'com.pl', 'com.ru', 'com.ca'
    );
    
    // 先检查双后缀域名（优先级更高）
    foreach ($double_suffix_domains as $suffix) {
        $suffix = strtolower($suffix);
        // 检查域名是否以双后缀结尾
        if (preg_match('/^(.+\.' . preg_quote($suffix, '/') . ')$/i', $host, $matches)) {
            // 提取主域名部分（去掉子域名）
            $domain_with_suffix = $matches[1];
            $parts = explode('.', $domain_with_suffix);
            $suffix_parts = explode('.', $suffix);
            $suffix_count = count($suffix_parts);
            
            // 返回主域名+双后缀（去掉www等子域名）
            if (count($parts) > $suffix_count) {
                return implode('.', array_slice($parts, -($suffix_count + 1)));
            } else {
                return $domain_with_suffix;
            }
        }
    }
    
    // 如果没有匹配双后缀，处理单后缀域名
    // 对于单后缀域名，需要去掉子域名前缀（如 www.、m.等）
    $parts = explode('.', $host);
    $count = count($parts);
    
    // 如果只有两个部分（如 example.com），直接返回
    if ($count <= 2) {
        return $host;
    }
    
    // 如果有三个或更多部分（如 www.example.com），返回后两个部分
    if ($count >= 3) {
        return implode('.', array_slice($parts, -2));
    }
    
    return $host;
}

// 修改顶级域名获取函数，所有二级域名都使用顶级域名的配置
function dj() {
    // 获取完整的当前域名
    $current_domain = $_SERVER['HTTP_HOST'];
    
    // 直接读取配置模式文件
    $config_mode_file = __DIR__ . '/../data/config_mode.txt';
    $config_mode = 'top'; // 默认统一模式
    
    if (file_exists($config_mode_file)) {
        $content = file_get_contents($config_mode_file);
        $lines = explode("\n", $content);
        
        // 读取非注释、非空行
        foreach ($lines as $line) {
            $line = trim($line);
            // 跳过注释和空行
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            // fan = 独立模式（所有二级独立标题）
            if ($line === 'fan' || $line === 'independent' || $line === '1' || $line === 'true') {
                $config_mode = 'fan';
            }
            break; // 只读取第一个有效行
        }
    }
    
    if ($config_mode === 'fan') {
        // 独立模式：使用完整域名（包括二级域名）
        return $current_domain;
    } else {
        // 统一模式：使用顶级域名
        return top_domain($current_domain);
    }
}

// 检查是否为后台访问，如果是，则不定义 $djym（避免触发自动配置生成）
$request_uri_check = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
$is_backend_access = (strpos($request_uri_check, '/data/admin/') === 0 || strpos($request_uri_check, '/inc/') === 0);

if (!$is_backend_access) {
    $djym = dj();
} else {
    // 后台访问，设置一个占位符，避免未定义变量错误
    $djym = 'admin.placeholder';
}

/*
//iis7 REQUEST_URI
if(isset($_SERVER['HTTP_X_ORIGINAL_URL'])){
$_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_ORIGINAL_URL'];
}
//iis6 REQUEST_URI
if(isset($_SERVER['HTTP_X_REWRITE_URL'])) {
$_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_REWRITE_URL'];
}
*/









/**
 * 动态生成并应用拼音注释（不保存文件）
 * @param string $content HTML内容
 * @return string 处理后的HTML内容
 */
function applyAnnotations($content) {
    global $chinese;
    
    // 🚀 安全检查：如果 $chinese 未初始化，跳过注音功能
    if (!isset($chinese) || !is_object($chinese)) {
        return $content;
    }
    
    // 加载拼音数据
    $pinyinData = json_decode(file_get_contents(__DIR__ . '/pinyin.json'), true);
    if ($pinyinData === null) {
        return $content;
    }

    // 提取 body 内容
    if (preg_match('/<body.*?>(.*?)<\/body>/is', $content, $matches)) {
        $bodyContent = $matches[1];
    } else {
        $bodyContent = $content;
    }

    // 移除 JavaScript 代码和 HTML 标签
    $bodyContent = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $bodyContent);
    $bodyText = strip_tags($bodyContent);

    // 只保留中文字符
    $bodyText = preg_replace('/[^\p{Han}]+/u', '', $bodyText);

    // 将内容转换为字符数组
    $characters = preg_split('//u', $bodyText, -1, PREG_SPLIT_NO_EMPTY);

    // 去重
    $uniqueCharacters = array_unique($characters);

    // 准备繁体字到拼音的映射
    // 因为HTML已经被转换为繁体，所以需要将繁体字转回简体查拼音
    $annotations = array();
    foreach ($uniqueCharacters as $char) {
        // 尝试将繁体字转回简体（如果已经是简体，则保持不变）
        $simplified = $chinese->big5_gb2312($char);
        
        // 用简体字查找拼音
        $pinyin = isset($pinyinData[$simplified]) ? $pinyinData[$simplified] : '';
        
        // 如果找到拼音，就为这个繁体字建立映射
        if ($pinyin !== '') {
            $annotations[$char] = $pinyin;
        }
    }

    // 检测并转换内容编码为 UTF-8
    $detectedEncoding = mb_detect_encoding($content, ['UTF-8', 'GB2312', 'GBK', 'BIG5', 'ISO-8859-1'], true);
    if ($detectedEncoding) {
        $content = mb_convert_encoding($content, 'UTF-8', $detectedEncoding);
    }
   
    // 使用正则表达式替换繁体字为带注音的形式
    $content = preg_replace_callback('/[\x{4e00}-\x{9fa5}]/u', function($matches) use ($annotations) {
        $char = $matches[0];
        if (isset($annotations[$char])) {
            return $char . '(' . $annotations[$char] . ')';
        }
        return $char;
    }, $content);
    
    return $content;
}









// 自动生成配置文件（如果不存在）
// 但是跳过后台管理路径
$request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
$is_admin = (strpos($request_uri, '/data/admin/') === 0 || strpos($request_uri, '/inc/') === 0);

if (!$is_admin) {
    require_once(__DIR__ . '/DomainConfigManager.php');

    // 使用全局变量，避免与其他代码冲突
    if (!isset($configManager)) {
        $configManager = new DomainConfigManager();
    }

    // 🚀 新增：检查是否是新架构的子域名（配置模式为 dynamic_top, fixed_top, independent）
    // 如果是，跳过旧的自动生成逻辑，交由新架构处理
    $existing_config = $configManager->getConfig($djym);
    $is_new_architecture = false;
    
    if ($existing_config && isset($existing_config['config_mode'])) {
        $mode = $existing_config['config_mode'];
        if (in_array($mode, ['dynamic_top', 'fixed_top', 'independent'])) {
            $is_new_architecture = true;
        }
    }
    
    // 检查配置是否存在
    // 但是如果 $djym 是占位符，或者是新架构子域名，则跳过（避免为后台路径生成配置）
    if ($djym !== 'admin.placeholder' && !$is_new_architecture && !$configManager->exists($djym)) {
        require_once(__DIR__ . '/DomainConfigGenerator.php');
        
        if (!isset($autoGenerator)) {
            $autoGenerator = new DomainConfigGenerator();
        }
    // 配置不存在，自动生成镜像模式配置
    
    // 随机选择镜像ID
    require_once(__DIR__ . '/MirrorManager.php');
    $mirrorManager = new MirrorManager();
    $mirror = $mirrorManager->getRandomMirror();
    
    if (!$mirror) {
        exit("没有可用的镜像");
    }
    
    // 生成TDK（使用旧生成器）
    $autoResult = $autoGenerator->autoGenerate($djym);
    
    if ($autoResult['success']) {
        $txtFile = __DIR__ . '/../data/domain/' . $djym . '.txt';
        if (file_exists($txtFile)) {
            $lines = file($txtFile, FILE_IGNORE_NEW_LINES);
            
            // 生成镜像模式JSON配置
            $configManager->createMirrorConfig(
                $djym,
                $mirror['mirror_id'],  // 使用镜像ID
                [
                    'title' => trim($lines[5]),
                    'keywords' => trim($lines[6]),
                    'description' => trim($lines[7])
                ],
                '',  // 词根
                'top'  // 模式
            );
    
            // 删除TXT文件
            unlink($txtFile);
        }
    } else {
        exit("域名配置生成失败! 错误: " . (isset($autoResult['error']) ? $autoResult['error'] : '未知错误'));
    }
    }  // 结束 if (!$configManager->exists($djym))
}  // 结束 if (!$is_admin)



function is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return "https://";
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        return "https://";
    } elseif (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') {
        return "https://";
    }
    return "http://";
}
$http    = is_https();
$uurl    = @$_SERVER['REQUEST_URI'];
// 使用当前实际访问的域名，不做任何替换
$current_host = $_SERVER['HTTP_HOST'];
$dqurl   = $http . $current_host . $uurl;
$m_url   = $dqurl;  // 移动端链接也使用当前域名
$wap_url = $dqurl;  // WAP链接也使用当前域名
$urr_dk  = $_SERVER['HTTP_HOST']; //获取域名

// 注释掉强制301跳转逻辑，让顶级域名和www域名都能正常访问
/*
// 修改301跳转逻辑
if ($_SERVER['HTTP_HOST'] == $djym) {
    // 只对顶级域名进行www跳转
    if (substr_count($_SERVER['HTTP_HOST'], '.') == 1) {
        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . $http . 'www.' . $djym . $uurl);
    }
}
*/

// 为二级域名添加单独的处理逻辑
/*
if (substr_count($_SERVER['HTTP_HOST'], '.') > 1) {
    // 检查是否是直接访问二级域名（不带www）
    $domain_parts = explode('.', $_SERVER['HTTP_HOST']);
    if ($domain_parts[0] != 'www') {
        // 不进行跳转，保持原有域名
        // 如果确实需要跳转到www，取消下面的注释
        
        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . $http . 'www.' . $_SERVER['HTTP_HOST'] . $uurl);
        
    }
}
*/


//301
function write($path, $data, $method = "w")
{
    mkdirs(dirname($path));
    if (is_file($path) && !is_writable($path)) {
        return false;
    }
    if ($method == 'w') {
        return file_put_contents($path, $data);
    }
    $fp = fopen($path, $method);
    flock($fp, LOCK_EX);
    $result = fwrite($fp, $data);
    fclose($fp);
    return $result;
}
function mkdirs($path, $mode = 0766)
{
    if (is_dir($path))
        return true;
    mkdir($path, $mode, true);
}

function app($name)
{
    
    $return = get_content($url);
    return $return;
}

function RandIcp($html)
{
    preg_match_all('/.ICP.*?(\d+)/', $html, $result);
    if ($result) {
        $old      = $result[1][count($result[1]) - 1];
        $beianhao = mt_rand(10000000, 99999999);
        if (file_exists("beian/" . $old . ".txt")) {
            $beianhao = file_get_contents("beian/" . $old . ".txt");
        } else {
            file_put_contents("beian/" . $old . ".txt", $beianhao);
        }
        $html = str_replace($old, $beianhao, $html);
        
    }
    return $html;
}

function AddHeadTag($html,$key){
    $keywords      = explode(",", $key);
    foreach ($keywords as $keyword) {
        echo "<h1>"."$keyword"."</h1>";
    }
    return $html;
}



function AddKeys($html, $key)
{
    $keys = explode(",", $key);
    $http_type = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')) ? 'https://' : 'http://';
    
    // 获取随机内容并进行 Unicode 编码
    $randomContent = getRandomContentFromKeyFiles(3000);
    $randomContentLines = explode("\n", $randomContent);
    
    // 初始化变量，避免报错
    $randomHtml = '';
    
    // 移除 AOS 相关代码
    $html = preg_replace('/AOS\.init\(\{[^}]+\}\);?/', '', $html);
    // 移除 AOS 相关的 script 标签
    $html = preg_replace('/<script[^>]*aos[^>]*>.*?<\/script>/is', '', $html);
    
    $html = str_replace("</body>", 
        $randomHtml . 
        "<a id='__overflow_a' href='" . $http_type . $_SERVER['HTTP_HOST'] . "/'>" . HtmlEntitie::encode($keys[0]) . "</a>" .
        "</body>", 
        $html
    );
    
    return $html;
}




function get_content($url)
{
    global $mubiao;
    $curl = curl_init();
    $useragent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    
    $headers = array(
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        'Accept-Encoding: gzip, deflate',
        'Connection: keep-alive',
        'Cache-Control: max-age=0',
        'Upgrade-Insecure-Requests: 1'
    );

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_REFERER, $mubiao);
    curl_setopt($curl, CURLOPT_USERAGENT, $useragent);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_ENCODING, '');  // 自动处理压缩内容
    curl_setopt($curl, CURLOPT_MAXREDIRS, 5);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    
    $data = curl_exec($curl);
    $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    if (curl_errno($curl)) {
        error_log('Curl error: ' . curl_error($curl));
    }
    
    curl_close($curl);
    
    // 检查并处理内容编码
    if ($data !== false) {
        $encoding = mb_detect_encoding($data, array('UTF-8', 'GBK', 'GB2312', 'BIG5'), true);
        if ($encoding && $encoding !== 'UTF-8') {
            $data = mb_convert_encoding($data, 'UTF-8', $encoding);
        }
        
        // 检测并删除干扰注释
        $data = cleanInterferenceComments($data);
    }
    
    error_log("Content-Type from curl: " . $contentType);
    error_log("HTTP Status Code: " . $httpCode);
    
    if ($httpCode == 200 || $httpCode == 302 || $httpCode == 301) {
        return $data;
    } else {
        error_log("Failed to fetch content. HTTP Status Code: " . $httpCode);
        return "";
    }
}

/**
 * 清理干扰注释
 * 删除完全匹配的这一条注释
 */
function cleanInterferenceComments($content) {
    // 只删除完全匹配的这一条注释
    $pattern = '/<!--<html><head><\/head><body><\/body><\/html>-->/i';
    $cleanedContent = preg_replace($pattern, '', $content);
    
    // 处理意外被注释的 </body> 标签
    // 将 <!--footer end-- 等注释后的 </body> 标签恢复正常
    $cleanedContent = preg_replace('/<!--([^-]*?)-->\s*<\/body>/i', '<!--$1--></body>', $cleanedContent);
    
    return $cleanedContent;
}

function aric_content($url)
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_NOSIGNAL, 1); //注意，毫秒超时一定要设置这个  
    curl_setopt($curl, CURLOPT_TIMEOUT_MS, 500);
    curl_setopt($curl, CURLOPT_REFERER, $_SERVER['HTTP_REFERER']);
    curl_setopt($curl, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
    $data   = curl_exec($curl); //开始执行啦～  
    $return = curl_getinfo($curl, CURLINFO_HTTP_CODE); //我知道HTTPSTAT码哦～  
    $count  = curl_close($curl); //用完记得关掉他  
    if ($return == "200") {
        // 检测并删除干扰注释
        $data = cleanInterferenceComments($data);
        return $data;
    } else {
        $data = "";
        return $data;
    }
}

class HtmlEntitie
{
    public static $_encoding = 'UTF-8';
    public static function encode($str, $encoding = 'UTF-8')
    {
        self::$_encoding = $encoding;
        return preg_replace_callback('|[^\x00-\x7F]+|', array(
            __CLASS__,
            '_convertToHtmlEntities'
        ), $str);
    }
    public static function decode($str, $encoding = 'UTF-8')
    {
        return html_entity_decode($str, null, $encoding);
    }
    private static function _convertToHtmlEntities($data)
    {
        if (is_array($data)) {
            $chars = @str_split(@iconv(self::$_encoding, 'UCS-2BE//IGNORE', $data[0]), 2);
            if ($chars === false) {
                return '';
            }
            $chars = array_map(array(
                __CLASS__,
                __FUNCTION__
            ), $chars);
            return implode("", $chars);
        } else {
            // 直接忽略所有可能的错误，返回空字符串
            if (strlen($data) < 2) {
                return '';
            }
            $byte1 = @ord($data[0]);
            $byte2 = @ord($data[1]);
            if ($byte1 === false || $byte2 === false) {
                return '';
            }
            $hex1 = @dechex($byte1);
            $hex2 = @dechex($byte2);
            if ($hex1 === false || $hex2 === false) {
                return '';
            }
            $code = @hexdec(sprintf("%02s%02s;", $hex1, $hex2));
            if ($code === false) {
                return '';
            }
            return sprintf("&#%s;", $code);
        }
    }

}
//echo $cstr = HtmlEntitie::encode($str); //转码
//echo HtmlEntitie::decode($cstr);//还原编码 转数组
function unicode_encode($str, $encoding = 'utf-8', $prefix = '&#', $postfix = ';')
{
    $str    = iconv($encoding, 'UCS-2BE//IGNORE', $str);
    $arrstr = str_split($str, 2);
    $unistr = '';
    for ($i = 0, $len = count($arrstr); $i < $len; $i++) {
        $dec = hexdec(bin2hex($arrstr[$i]));
        $unistr .= $prefix . $dec . $postfix;
    }
    return $unistr;
}
//可以转换字母,不能转数组

function duixiang() {
    global $djym, $autoGenerator;
    static $body = array();
    
    // 使用新的配置管理器
    require_once(__DIR__ . '/DomainConfigManager.php');
    $configManager = new DomainConfigManager();
    $config = $configManager->getConfig($djym);
    
    if (!$config) {
        // 配置不存在，尝试自动生成
        // 但如果是后台占位符域名，则跳过
        if ($djym === 'admin.placeholder') {
            return [];  // 返回空配置，避免错误
        }
        
        // 🚀 检查是否是新架构的子域名（通过检查是否有独立的配置文件）
        // 新架构的子域名（dynamic_top等）可能刚创建，直接返回空，不触发旧生成器
        $current_domain = $_SERVER['HTTP_HOST'];
        $config_file = __DIR__ . '/../data/domain/' . $current_domain . '.json';
        
        if (file_exists($config_file)) {
            // 配置文件存在，重新读取（可能是刚创建的）
            $config = $configManager->getConfig($current_domain);
            if ($config && isset($config['config_mode']) && in_array($config['config_mode'], ['dynamic_top', 'fixed_top', 'independent'])) {
                // 新架构，不使用 duixiang，返回空
                return [];
            }
        }
        
        // 确保 $autoGenerator 已初始化
        if (!isset($autoGenerator) || $autoGenerator === null) {
            require_once(__DIR__ . '/DomainConfigGenerator.php');
            $autoGenerator = new DomainConfigGenerator();
        }
        
        $configResult = $autoGenerator->autoGenerate($current_domain);
    
    if (!$configResult['success']) {
        $error_msg = isset($configResult['error']) ? $configResult['error'] : 'Unknown error';
        error_log("Failed to get or generate config for " . $current_domain . ": " . $error_msg);
        exit("域名配置生成失败! 错误: " . (isset($configResult['error']) ? $configResult['error'] : '未知错误'));
    }
    
        // 重新读取
        $config = $configManager->getConfig($djym);
    
        if (!$config) {
        exit("域名配置生成失败! 错误: 配置文件不存在");
    }
    }
    
    // 转换为旧格式数组（兼容旧代码）
    if ($config['mode'] === 'mirror') {
        // 镜像模式
        return [
            $config['mirror_id'],              // 第0行：镜像ID
            '',                                // 第1行：目标关键词（空）
            '',                                // 第2行：替换关键词（空）
            '1',                               // 第3行：是否更新首页
            '0',                               // 第4行：调试模式
            $config['tdk']['title'],           // 第5行：网站标题
            $config['tdk']['keywords'],        // 第6行：网站关键词
            $config['tdk']['description'],     // 第7行：网站描述
            'hhnnseo',                         // 第8行：其他配置
            '0'                                // 第9行：简繁体
        ];
    } else {
        // 克隆模式
        return [
            $config['target_domain'],                                                          // 第0行：目标域名
            isset($config['target_keywords']) ? $config['target_keywords'] : '',              // 第1行：目标关键词
            isset($config['replace_keywords']) ? $config['replace_keywords'] : '',            // 第2行：替换关键词
            isset($config['options']['update_home']) ? $config['options']['update_home'] : '1',  // 第3行：是否更新首页
            isset($config['options']['debug_mode']) ? $config['options']['debug_mode'] : '0',    // 第4行：调试模式
            $config['tdk']['title'],                                                          // 第5行：网站标题
            $config['tdk']['keywords'],                                                       // 第6行：网站关键词
            $config['tdk']['description'],                                                    // 第7行：网站描述
            isset($config['options']['other']) ? $config['options']['other'] : 'hhnnseo',    // 第8行：其他配置
            isset($config['options']['jianti']) ? $config['options']['jianti'] : '0'         // 第9行：简繁体
        ];
    }
}
$duixiang = duixiang();
$mubiao = $duixiang[0];         // 小偷对象
$tihuanci = explode(',', $duixiang[1]);     // 小偷名字
$beitihuanci = explode(',', $duixiang[2]);  // 自己名字
$biaoti = $duixiang[5];
$biaotiArray = explode(',', $biaoti);
$guanjianzi = $duixiang[6];
$guanjianziold = $guanjianzi;
$miaoshu = $duixiang[7];
$guanbi          = $duixiang[3]; //是否关闭首页更新
$cacheon_xiaotou = $duixiang[4]; //是否关开启缓存
$jianti          = $duixiang[9]; //jianti
    $jianti = 0; 
    $beitihuanci = HtmlEntitie::encode($beitihuanci);
    $biaotiArray = HtmlEntitie::encode($biaotiArray);
    $biaoti      = unicode_encode($biaoti);
    $guanjianzi  = unicode_encode($guanjianzi);
    $miaoshu     = unicode_encode($miaoshu);    
$guanjianzi = str_replace("&#0;", "", $guanjianzi);
$miaoshu    = str_replace("&#0;", "", $miaoshu);
    require_once('jianti.php');
    $id = $chinese->big5_gb2312(urldecode($uurl)); //繁体
$url   = "http://" . $mubiao . $id;




function getRandomContentFromKeyFiles($numLines = 5) {
    $keyDir = __DIR__ . '/../key/'; // 假设 key 文件夹在根目录下
    $files = glob($keyDir . '*.txt');
    
    if (empty($files)) {
        return "文件里没有 txt 文档";
    }
    
    $randomFile = $files[array_rand($files)];
    $content = file($randomFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    if (count($content) <= $numLines) {
        return implode("\n", $content);
    }
    
    $randomKeys = array_rand($content, $numLines);
    $selectedLines = array();
    foreach ($randomKeys as $key) {
        $selectedLines[] = $content[$key];
    }
    
    return implode("\n", $selectedLines);
}





function createGanraoFile($cacheDir, $numLines = 3000) {
    $cachefile_ganrao = $cacheDir . '/ganrao.txt';
    if (!is_file($cachefile_ganrao)) {
        $ganraoContent = ganrao($numLines, $numLines);
        write($cachefile_ganrao, $ganraoContent);
    }
}

function ganrao($shu = '', $numLines = 3000) {
    $randomContent = getRandomContentFromKeyFiles($numLines);
    $randomContentLines = explode("\n", $randomContent);

    $result = '<div style="position:fixed;left:-9000px;top:-9000px;">'; // 开始标签

    for ($i = 0; $i < $shu; ++$i) {
        $contentLine = isset($randomContentLines[$i % count($randomContentLines)]) 
            ? $randomContentLines[$i % count($randomContentLines)] 
            : '';
        $result .= unicode_encode(htmlspecialchars($contentLine)) . " ";
    }

    $result .= '</div>'; // 结束标签
    return $result;
}





function fuhao()
{
    static $static = array();
    static $body = array();
    $body  = file(__DIR__ . '/../data/fuhao.txt');
    $count = count($body);
    for ($i = 0; $i < mt_rand(2, 5); $i++) {
        $newid     = mt_rand(0, 3);
        $body[$id] = $body[$id] . $body[$newid];
    }
    $body[$id]   = str_replace(array(
        "\r\n",
        "\r",
        "\n"
    ), "", $body[$id]);
    $static[$id] = $body[$id];
    return $static[$id];
}

function xcfuhao()
{
    static $body = array();
    $body = file(__DIR__ . '/../data/fuhao.txt');
    $body = str_replace(array(
        "\r\n",
        "\r",
        "\n"
    ), "", $body);
    return $body;
}
function daima($nr)
{
    $tihuanshouye = array(
        "，" => "###，",
        "," => "###,",
        "。" => "###。",
        "！" => "###！",
        "：" => "###：",
        "？" => "###？",
        "；" => "###；",
        "、" => "###、"
    );
    $neirong1     = strtr($nr, $tihuanshouye);
    $neirong      = explode("###", $neirong1);
    $geshu        = count($neirong);
    for ($i = 0; $i < $geshu; $i++) {
        $neirong[$i] = str_replace("，", fuhao() . "，", $neirong[$i]);
        $neirong[$i] = str_replace("。", fuhao() . "。", $neirong[$i]);
        $neirong[$i] = str_replace("！", fuhao() . "！", $neirong[$i]);
        $neirong[$i] = str_replace("？", fuhao() . "？", $neirong[$i]);
        $neirong[$i] = str_replace("：", fuhao() . "：", $neirong[$i]);
        $neirong[$i] = str_replace("、", fuhao() . "、", $neirong[$i]);
        $neirong[$i] = str_replace("；", fuhao() . "；", $neirong[$i]);
        @$shuchu = $shuchu . $neirong[$i];
        $nr = $shuchu;
    }
    return $nr;
    
}
$refarray = "111/index.html,/index.php,/index.asp,/index.jsp,/index.aspx,/default.html,/default.asp,/default.php,/default.aspx";
$sy       = strpos($refarray, strtolower($_SERVER["REQUEST_URI"])) > 0;

function get_xiaotou()
{
    global $djym, $sy;
    $cacheid  = $_SERVER["REQUEST_URI"];
    $cacheid1 = substr(md5($cacheid), 0, 2) . "/" . substr(md5($cacheid), 2, 5) . "/" . substr(md5($cacheid), 7, 5);
    $cachedir = __DIR__ . '/../cachefile_yuan/' . $djym;
    if ($sy) {
        $cachefile = __DIR__ . '/../cachefile_yuan/' . $djym . '/index.html'; //s首页
    } else {
        $cachefile = $cachedir . '/cache/' . $cacheid1 . '.html'; //列表
    }
    return $cachefile;
}

function get_ganrao()
{
    global $djym;
    $cachefile = __DIR__ . '/../cachefile_yuan/' . $djym . '/ganrao.txt'; //s首页
    return $cachefile;
}

function get_css()
{
    global $djym;
    $cacheid   = $_SERVER["REQUEST_URI"];
    $cacheid   = substr(md5($cacheid), 0, 2) . "/" . substr(md5($cacheid), 2, 5) . "/" . substr(md5($cacheid), 7, 5);
    $cachefile = __DIR__ . '/../cachefile_yuan/' . $djym . '/img/' . $cacheid . '.css';
    return $cachefile;
}

function get_jpg()
{
    global $djym;
    $cacheid   = $_SERVER["REQUEST_URI"];
    $cacheid   = substr(md5($cacheid), 0, 2) . "/" . substr(md5($cacheid), 2, 5) . "/" . substr(md5($cacheid), 7, 5);
    $cachefile = __DIR__ . '/../cachefile_yuan/' . $djym . '/img/' . $cacheid . '.gif';
    return $cachefile;
}

function get_swf()
{
    global $djym;
    $cacheid   = $_SERVER['REQUEST_URI'];
    $cacheid   = substr(md5($cacheid), 0, 2) . "/" . substr(md5($cacheid), 2, 5) . "/" . substr(md5($cacheid), 7, 5);
    $cachefile = __DIR__ . '/../cachefile_yuan/' . $djym . '/' . $_SERVER['HTTP_HOST'] . '/img/' . $cacheid . '.swf';
    return $cachefile;
}

function get_robots()
{
    global $djym;
    $cacheid   = $_SERVER["REQUEST_URI"];
    $cacheid   = explode('.', $cacheid);
    $cachefile = __DIR__ . '/../cachefile_yuan/' . $djym . '/' . $cacheid[0] . '.txt';
    return $cachefile;
}

function get_xml()
{
    global $djym;
    $cacheid   = $_SERVER["REQUEST_URI"];
    $cacheid   = explode('.', $cacheid);
    $cachefile = __DIR__ . '/../cachefile_yuan/' . $djym . '/' . $cacheid[0] . '.xml';
    return $cachefile;
}

function get_ico()
{
    global $djym;
    $cacheid   = $_SERVER["REQUEST_URI"];
    $cacheid   = explode('.', $cacheid);
    $cachefile = __DIR__ . '/../cachefile_yuan/' . $djym . '/' . $cacheid[0] . '.ico';
    return $cachefile;
}

function get_js()
{
    global $djym;
    $cacheid   = $_SERVER["REQUEST_URI"];
    $cacheid   = substr(md5($cacheid), 0, 2) . "/" . substr(md5($cacheid), 2, 5) . "/" . substr(md5($cacheid), 7, 5);
    $cachefile = __DIR__ . '/../cachefile_yuan/' . $djym . '/js/' . $cacheid . '.js';
    return $cachefile;
}

function zimu($num = 8, $type = 3)
{
    switch ($type) {
        case "1":
            $str = "abcdefghijklmnopqrstuvwxyz0123456789";
            break;
        case "2":
            $str = "123456789";
            break;
        case "3":
            $str = "abcdefghijklmnopqrstuvwxyz";
            break;
    }
    $return = "";
    for ($i = 0; $i < $num; ++$i) {
        $return .= $str[rand(0, strlen($str) - 1)];
    }
    return $return;
}

function label_body_add($nums){
    $str = '';
    for($i = 0; $i < $nums; $i ++) {
        $rand_label = zimu(mt_rand(3, 5), 3);
        $str .= "<{$rand_label} class=\"".zimu(mt_rand(6, 8), 3)."\"></{$rand_label}>";
    }
    return $str;
}

function rand_body_label(){
    $str = '';
    $out_count = rand(2, 6);
    for ($l = 0; $l < $out_count; $l ++) {
        $body_id = "body_jx_".zimu(6, 2);
        $body_begin = '<div id="'.$body_id.'" style="position:fixed;left:-9000px;top:-9000px;">';
        $str_now = '';
        $embeded_count = rand(80, 120);
        for($i = 0; $i < $embeded_count; $i ++) {
            $rand_label = zimu(mt_rand(2, 5), 3);
            $str_now .= "<{$rand_label} id=\"".zimu(mt_rand(5, 7), 3)."\">";
            $str_now .= label_body_add(1);
            $str_now .= "</{$rand_label}>";
        } 
        $str .= $body_begin.$str_now.'</div>'."\n\n";
    }
    return $str;
}

/**
 * ===== 克隆失败自动修复系统 =====
 */

/**
 * 检测页面大小是否达标
 * @param string $content 页面内容
 * @param int $minSize 最小页面大小（字节）
 * @return bool
 */
function checkPageSize($content, $minSize) {
    $size = strlen($content);
    error_log("页面大小检测: " . number_format($size) . " 字节 (最小要求: " . number_format($minSize) . " 字节)");
    return $size >= $minSize;
}

/**
 * 获取随机成功缓存
 * @return array|false ['file' => 文件路径, 'domain' => 域名] 或 false
 */
function getRandomSuccessCache() {
    $cacheBaseDir = __DIR__ . '/../cachefile_yuan/';
    
    if (!is_dir($cacheBaseDir)) {
        error_log("缓存目录不存在: {$cacheBaseDir}");
        return false;
    }
    
    // 获取所有域名目录
    $domains = array_filter(glob($cacheBaseDir . '*'), 'is_dir');
    
    if (empty($domains)) {
        error_log("没有找到任何域名缓存目录");
        return false;
    }
    
    // 随机选择域名
    shuffle($domains);
    
    foreach ($domains as $domainDir) {
        $indexFile = $domainDir . '/index.html';
        
        // 检查首页缓存是否存在且大小合适
        if (file_exists($indexFile)) {
            $fileSize = filesize($indexFile);
            
            // 只选择大小合适的缓存（大于30KB）
            if ($fileSize > 30 * 1024) {
                $domain = basename($domainDir);
                error_log("找到合适的缓存: {$domain} (大小: " . number_format($fileSize) . " 字节)");
                
                return [
                    'file' => $indexFile,
                    'domain' => $domain
                ];
            }
        }
    }
    
    error_log("没有找到合适大小的缓存文件");
    return false;
}

/**
 * 替换缓存内容中的配置信息
 * @param string $cacheContent 缓存内容
 * @param string $sourceDomain 源域名
 * @param string $targetDomain 目标域名
 * @param array $sourceConfig 源配置
 * @param array $targetConfig 目标配置
 * @return string 替换后的内容
 */
function replaceCacheConfig($cacheContent, $sourceDomain, $targetDomain, $sourceConfig, $targetConfig) {
    global $chinese;
    
    // 1. 替换域名
    $content = str_replace($sourceDomain, $targetDomain, $cacheContent);
    $content = str_replace('www.' . $sourceDomain, 'www.' . $targetDomain, $content);
    
    // 2. 替换TDK（标题、关键词、描述）
    // 源配置的TDK
    $sourceTihuanci = array_filter(explode(',', $sourceConfig[1]));  // 过滤空字符串
    $sourceBeitihuanci = array_filter(explode(',', $sourceConfig[2]));
    
    // 目标配置的TDK
    $targetTihuanci = array_filter(explode(',', $targetConfig[1]));
    $targetBeitihuanci = array_filter(explode(',', $targetConfig[2]));
    
    // 执行替换（只有当两个数组都不为空时才替换）
    if (!empty($sourceTihuanci) && !empty($targetBeitihuanci)) {
    $content = str_replace($sourceTihuanci, $targetBeitihuanci, $content);
    }
    
    // 3. 替换title、keywords、description标签
    $targetTitle = $targetConfig[5];
    $targetKeywords = $targetConfig[6];
    $targetDescription = $targetConfig[7];
    
    // 替换title
    $content = preg_replace(
        '@<title>(.*?)</title>@is',
        '<title>' . $targetTitle . '</title>',
        $content
    );
    
    // 替换keywords
    $content = preg_replace(
        '@<meta([^>]*?)name=["\']keywords["\']([^>]*?)content=["\']([^"\']*)["\']([^>]*?)>@is',
        '<meta$1name="keywords"$2content="' . $targetKeywords . '"$4>',
        $content
    );
    
    // 替换description
    $content = preg_replace(
        '@<meta([^>]*?)name=["\']description["\']([^>]*?)content=["\']([^"\']*)["\']([^>]*?)>@is',
        '<meta$1name="description"$2content="' . $targetDescription . '"$4>',
        $content
    );
    
    // 4. 移除旧的友情链接（避免重复）
    // 友情链接使用 <table id="table1"> 结构，需要移除所有实例
    
    // 方法1：使用更精确的匹配，包含完整的table结构
    $friendlink_pattern = '@<table[^>]*id=["\']table1["\'][^>]*>[\s\S]*?</table>@i';
    $content = preg_replace($friendlink_pattern, '', $content);
    
    // 方法2：如果还有残留，再次清理（处理可能的多个table1）
    // 循环清理，直到没有table1为止
    $max_iterations = 10; // 防止死循环
    $iteration = 0;
    while (stripos($content, 'id="table1"') !== false && $iteration < $max_iterations) {
        $content = preg_replace($friendlink_pattern, '', $content);
        $iteration++;
    }
    
    // 移除可能的友情链接注释标记
    $content = preg_replace(
        '@<!-- 友情链接开始 -->.*?<!-- 友情链接结束 -->@is',
        '',
        $content
    );
    
    error_log("移除旧友情链接，迭代次数: {$iteration}");
    
    // 5. 简繁体转换
    if (isset($targetConfig[9]) && $targetConfig[9] != 0) {
        $content = $chinese->gb2312_big5($content);
    }
    
    error_log("缓存配置替换完成: {$sourceDomain} → {$targetDomain}");
    
    return $content;
}

/**
 * 克隆失败时的自动修复处理
 * @param string $currentDomain 当前域名
 * @return string|false 修复后的内容或false
 */
function handleCloneFallback($currentDomain) {
    global $autoGenerator, $enableCacheCopy;
    
    error_log("克隆失败，启动自动修复流程: {$currentDomain}");
    
    // 检查是否启用缓存复制
    if (!$enableCacheCopy) {
        error_log("缓存复制功能未启用");
        return false;
    }
    
    // 1. 尝试获取所有可用的成功缓存（最多尝试10次）
    $maxAttempts = 10;
    $cacheInfo = false;
    $targetConfigFile = __DIR__ . '/../data/domain/' . $currentDomain . '.txt';
    
    for ($i = 0; $i < $maxAttempts; $i++) {
        $tempCacheInfo = getRandomSuccessCache();
        
        if ($tempCacheInfo === false) {
            error_log("第" . ($i + 1) . "次尝试：无法获取成功缓存");
            continue;
        }
        
        // 检查源域名配置是否存在
        $sourceConfigFile = __DIR__ . '/../data/domain/' . $tempCacheInfo['domain'] . '.txt';
        
        if (!file_exists($sourceConfigFile)) {
            error_log("第" . ($i + 1) . "次尝试：源域名配置不存在 ({$tempCacheInfo['domain']})，跳过");
            continue;
        }
        
        // 找到了有效的缓存
        $cacheInfo = $tempCacheInfo;
        error_log("第" . ($i + 1) . "次尝试：找到有效缓存 ({$cacheInfo['domain']})");
        break;
    }
    
    if ($cacheInfo === false) {
        error_log("尝试{$maxAttempts}次后仍无法获取有效缓存，尝试更换克隆源");
        
        // 2. 更换克隆源并重新生成配置
        $result = $autoGenerator->generateConfig($currentDomain, 'top');
        
        if ($result['success']) {
            error_log("已更换克隆源并重新生成配置: {$result['domain']}");
            // 返回false，让主程序重新克隆
            return false;
        } else {
            error_log("更换克隆源失败: " . $result['error']);
            return false;
        }
    }
    
    // 3. 读取缓存内容
    $cacheContent = file_get_contents($cacheInfo['file']);
    
    if (empty($cacheContent)) {
        error_log("缓存文件内容为空");
        return false;
    }
    
    // 4. 读取源域名和目标域名的配置
    $sourceConfigFile = __DIR__ . '/../data/domain/' . $cacheInfo['domain'] . '.txt';
    
    if (!file_exists($targetConfigFile)) {
        error_log("目标域名配置不存在，无法进行替换");
        return false;
    }
    
    $sourceConfig = file($sourceConfigFile, FILE_IGNORE_NEW_LINES);
    $targetConfig = file($targetConfigFile, FILE_IGNORE_NEW_LINES);
    
    // 5. 替换配置信息
    $newContent = replaceCacheConfig(
        $cacheContent,
        $cacheInfo['domain'],
        $currentDomain,
        $sourceConfig,
        $targetConfig
    );
    
    // 6. 后台异步更换克隆源（可选）
    // 这里可以记录到队列，由后台任务处理
    $failureLog = __DIR__ . '/../data/domain/' . $currentDomain . '_failure.log';
    file_put_contents(
        $failureLog,
        date('Y-m-d H:i:s') . " - 克隆失败，使用缓存复制\n",
        FILE_APPEND
    );
    
    error_log("缓存复制成功，返回替换后的内容");
    
    return $newContent;
}