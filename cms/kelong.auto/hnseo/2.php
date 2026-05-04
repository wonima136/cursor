<?php
//-------------------------这是唯一进行蜘蛛统计的核心代码-----------------------------------------------------------		
// 引入IP验证功能和数据库支持
include_once(__DIR__ . '/spider_ip_verify.php');
include_once(__DIR__ . '/cf_ip_helper.php');
include_once(__DIR__ . '/ip_auto_update.php'); // IP列表自动更新（每2分钟检查一次）
include_once(__DIR__ . '/spider_db.php'); // SQLite数据库支持（按天分库）

// 获取访问信息
$key = isset($_SERVER["HTTP_USER_AGENT"]) ? $_SERVER["HTTP_USER_AGENT"] : '';
		$ip = CloudflareIPHelper::getRealIP();
		
// 静态资源过滤 - 排除不需要统计的文件类型
		$static_extensions = array(
			// 图片
			'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif',
			// 样式和脚本
			'css', 'js', 'map',
			// 字体
			'woff', 'woff2', 'ttf', 'eot', 'otf',
			// 视频音频
			'mp4', 'avi', 'mov', 'wmv', 'flv', 'mp3', 'wav', 'ogg', 'm4a',
			// 文档和压缩包
			'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z', 'tar', 'gz',
			// 其他
			'xml', 'txt', 'log', 'swf'
		);
		
		// 获取URL路径并提取文件扩展名
		$request_uri = $_SERVER['REQUEST_URI'];
		$url_path = parse_url($request_uri, PHP_URL_PATH);
		$path_info = pathinfo($url_path);
		$file_extension = isset($path_info['extension']) ? strtolower($path_info['extension']) : '';
		
		// 如果是静态资源，直接退出，不进行统计
		if (!empty($file_extension) && in_array($file_extension, $static_extensions)) {
    return;
}

// URL路径过滤 - 排除JS渲染/系统接口等无意义的URL
$excluded_paths = array(
    // 帝国CMS系统路径
    '/e/public/',                 // 公共接口目录（含ViewClick等）
    '/e/extend/',                 // 扩展模块（如IgyPI2.0等）
    '/e/action/',                 // 操作接口
    '/e/member/',                 // 会员相关
    '/e/search/',                 // 搜索接口
    '/e/tool/',                   // 工具接口
    '/e/admin/',                  // 后台管理
    '/e/data/',                   // 数据目录
    '/e/class/',                  // 类文件
    '/e/enews/',                  // 新闻发布
    // 通用系统路径
    '/api/',                      // API接口
    '/ajax/',                     // AJAX请求
    '/wp-admin/',                 // WordPress后台
    '/wp-json/',                  // WordPress API
    '/wp-includes/',              // WordPress核心
    '/admin/',                    // 通用后台
    '/cgi-bin/',                  // CGI目录
    '/qrcode/',
    '/comment/',    
);

// 如果URL路径匹配排除规则，直接退出
foreach ($excluded_paths as $excluded_path) {
    if (stripos($url_path, $excluded_path) !== false) {
        return;
    }
		}
		
// 蜘蛛识别 - 对百度和谷歌进行IP验证，其他蜘蛛按原逻辑
$Sogouspider = preg_match('/Sogou/', $key);
$baiduspider = SpiderIPVerify::isRealBaiduSpider($key, $ip) ? 1 : 0;
$Googlebot = SpiderIPVerify::isRealGoogleBot($key, $ip) ? 1 : 0;
$liulingSpider = preg_match('/360Spider/', $key);
$Yisouspider = preg_match('/Yisouspider/', $key);
$Bytespider = preg_match('/Bytespider/', $key);

// 如果没有任何蜘蛛访问，直接退出
if (!$Sogouspider && !$baiduspider && !$Googlebot && !$liulingSpider && !$Yisouspider && !$Bytespider) {
    return;
}

// 获取访问的页面地址
		$urls = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 
         (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')) 
        . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// 获取当前访问的时间
$zhizhutime = date('Y-m-d H:i:s');

// 判断百度蜘蛛是PC还是移动端
$useragent = strtolower($key);
if (strpos($useragent, "android") !== false || strpos($useragent, "iphone") !== false || strpos($useragent, "mobile") !== false) {
    $baidu = '百度移动';
} else {
    $baidu = '百度PC';
		}

// 提取顶级域名用于数据库存储
$_suffixes_config = include(__DIR__ . '/domain_suffixes.php');
$_double_suffixes = $_suffixes_config['double_suffixes'];
$_single_suffixes = $_suffixes_config['single_suffixes'];

function _extractTopDomain($domain) {
    global $_double_suffixes, $_single_suffixes;
    $domain = strtolower(trim($domain));
    $domain = rtrim($domain, '.');
    if (empty($domain)) return $domain;
    
    // 优先匹配双后缀
    foreach ($_double_suffixes as $suffix) {
        if (substr($domain, -strlen($suffix)) === $suffix) {
            $without = rtrim(substr($domain, 0, -strlen($suffix)), '.');
            if (!empty($without)) {
                $parts = explode('.', $without);
                $main = end($parts);
                if (!empty($main)) return $main . $suffix;
            }
        }
    }
    // 匹配单后缀
    foreach ($_single_suffixes as $suffix) {
        if (substr($domain, -strlen($suffix)) === $suffix) {
            $without = rtrim(substr($domain, 0, -strlen($suffix)), '.');
            if (!empty($without)) {
                $parts = explode('.', $without);
                $main = end($parts);
                if (!empty($main)) return $main . $suffix;
            }
        }
    }
    // 默认取最后两部分
    $parts = explode('.', $domain);
    if (count($parts) >= 2) {
        return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
    }
    return $domain;
}

// 从URL中提取域名
$parsed_url = parse_url($urls);
$full_domain = isset($parsed_url['host']) ? $parsed_url['host'] : '';
$top_domain = _extractTopDomain($full_domain);

// 获取数据库实例并写入
try {
    $spiderDB = getSpiderDB();
    
    if ($Sogouspider) {
        $spiderDB->insertVisit($zhizhutime, $ip, 'Sogou', $urls, $top_domain);
    }
    if ($baiduspider) {
        $spiderDB->insertVisit($zhizhutime, $ip, 'Baiduspider', $urls, $top_domain, $baidu);
			} 
    if ($Googlebot) {
        $spiderDB->insertVisit($zhizhutime, $ip, 'Googlebot', $urls, $top_domain);
    }
    if ($liulingSpider) {
        $spiderDB->insertVisit($zhizhutime, $ip, '360Spider', $urls, $top_domain);
			}
    if ($Yisouspider) {
        $spiderDB->insertVisit($zhizhutime, $ip, 'Yisouspider', $urls, $top_domain);
			}
    if ($Bytespider) {
        $spiderDB->insertVisit($zhizhutime, $ip, 'Bytespider', $urls, $top_domain);
    }
} catch (Exception $e) {
    // error_log('Spider DB Error: ' . $e->getMessage());
			}	
