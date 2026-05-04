<?php
// ===== 系统文件夹排除列表 =====
// 这些路径不应该被克隆程序处理，直接交给PHP原生处理
$excluded_paths = [
    '/data/',           // 后台管理和数据目录
    '/inc/',            // 核心函数库
    '/test/',           // 测试脚本目录  
    '/cachefile_yuan/', // 缓存目录
    '/vendor/',         // 第三方库
    '/node_modules/',   // Node模块
];

// 检查当前请求路径是否在排除列表中
$request_uri = $_SERVER['REQUEST_URI'];
foreach ($excluded_paths as $excluded_path) {
    if (strpos($request_uri, $excluded_path) === 0) {
        // 在排除列表中，不执行克隆逻辑
        // 如果是后台管理路径，检查目标文件是否存在
        if (strpos($request_uri, '/data/admin/') === 0) {
            $targetFile = __DIR__ . $request_uri;
            // 移除查询字符串
            if (strpos($targetFile, '?') !== false) {
                $targetFile = substr($targetFile, 0, strpos($targetFile, '?'));
            }
            if (file_exists($targetFile) && is_file($targetFile)) {
                // 文件存在，直接引入执行
                require $targetFile;
                exit();
            }
        }
        // 如果是测试路径，检查目标文件是否存在
        if (strpos($request_uri, '/test/') === 0) {
            $targetFile = __DIR__ . $request_uri;
            // 移除查询字符串
            if (strpos($targetFile, '?') !== false) {
                $targetFile = substr($targetFile, 0, strpos($targetFile, '?'));
            }
            if (file_exists($targetFile) && is_file($targetFile)) {
                // 文件存在，直接引入执行
                require $targetFile;
                exit();
            }
        }
        // 其他排除路径，直接返回（让Web服务器处理）
        return;
    }
}
// ===== 排除逻辑结束 =====

// ===== 缓存清理（访问触发） =====
require_once __DIR__ . '/cron_cleanup_cache.php';
// ===== 缓存清理结束 =====

// ===== 镜像模式路由检测 =====
// 如果是镜像模式配置，会在这里处理并exit，不会继续执行后面的克隆逻辑
require_once __DIR__ . '/mirror_router.php';
// ===== 镜像路由结束 =====

// ===== 第一步：在页面最开头引入 add.php =====
// 注意：必须在任何输出之前引入
require_once __DIR__ . '/add.php';
// ===== 第二步：正常的网站程序代码 =====
$t1 = microtime(true);

// ===== 空白页面301跳转开关 =====
// true: 开启，检测到空白页面会301跳转到首页
// false: 关闭，不检测空白页面
$enable301Redirect = true;
// ===== 开关配置结束 =====

// 生产环境关闭错误显示
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

require_once('inc/func.php');
require_once('inc/coon.php');
require_once('inc/DomainGroupManager.php');

// ===== 克隆失败检测配置 =====
$minPageSize = $min_page_size ?? 30 * 1024; // 从 coon.php 读取，默认30KB
// ===== 配置结束 =====

/**
 * 检测页面内容是否为空白或无效
 * @param string $content 页面内容
 * @return bool 如果页面为空白返回true
 */
function isBlankPage($content) {
    // 移除所有空白字符后检查长度
    $stripped = trim(strip_tags($content));
    
    // 如果内容为空或太短（少于50个字符），认为是空白页面
    if (empty($stripped) || strlen($stripped) < 50) {
        return true;
    }
    
    // 检查是否包含基本的HTML结构
    if (stripos($content, '<html') === false && stripos($content, '<body') === false) {
        return true;
    }
    
    return false;
}

/**
 * 301跳转到首页
 */
function redirectToHome() {
    header("HTTP/1.1 301 Moved Permanently");
    header("Location: /");
    exit();
}

// 确保 cachefile_yuan 目录存在
if (!is_dir('./cachefile_yuan/')) {
    mkdir('./cachefile_yuan/', 0755, true);
}

$cachefile = get_xiaotou();

// 确保域名缓存目录和 ganrao.txt 文件存在
$cacheDir = './cachefile_yuan/' . $djym;
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
createGanraoFile($cacheDir);

// ===== 缓存过期检测（24小时） =====
require_once('inc/CacheManager.php');
$cacheManager = new CacheManager();

if (is_file($cachefile)) {
    // 检查缓存是否过期
    if ($cacheManager->isCacheExpired($djym)) {
        error_log("[缓存] 缓存已过期（24小时无访问），删除: {$djym}");
        $cacheManager->deleteExpiredCache($djym);
        // 继续执行后续的克隆逻辑
    } else {
        // 缓存有效，更新访问时间
        $cacheManager->touchCache($djym);
        
    $nr = file_get_contents($cachefile);
    
    // 检查缓存大小是否达标
    if (strlen($nr) < $minPageSize) {
        error_log("缓存文件过小（" . strlen($nr) . " 字节），删除并重新克隆");
        unlink($cachefile);
        // 继续执行后续的克隆逻辑
    } else {
        // ===== 域名分组：访问计数、缓存更新、克隆源切换 =====
        $groupManager = new DomainGroupManager();
        $groupInfo = $groupManager->getDomainGroup($djym);
        
        if ($groupInfo) {
            // 检查是否需要启用访问计数（缓存更新或克隆源切换任一启用）
            $needCounter = ($groupInfo['cache_refresh']['enabled'] ?? false) || 
                          (isset($groupInfo['clone_source_switch']) && $groupInfo['clone_source_switch']['enabled']);
            
            if ($needCounter) {
            // 增加访问计数
            $visitCount = $groupManager->incrementVisitCounter($djym);
                error_log("[分组] 域名 {$djym} 访问计数: {$visitCount}");
                
                // 1. 检查缓存更新
                if ($groupInfo['cache_refresh']['enabled']) {
                    $cacheTrigger = $groupInfo['cache_refresh']['trigger_visits'];
                    error_log("[分组] 缓存更新检查: {$visitCount}/{$cacheTrigger}");
                    
                    if ($visitCount >= $cacheTrigger) {
                error_log("[分组] 触发缓存更新: {$djym}");
                
                // 执行缓存更新
                $refreshResult = $groupManager->refreshDomainCache($djym, $groupInfo);
                
                if ($refreshResult) {
                    error_log("[分组] 缓存更新成功，重新读取");
                    // 重新读取更新后的缓存
                    $nr = file_get_contents($cachefile);
                            
                            // 重新读取配置文件，确保TDK变量是最新的
                            $body = duixiang();
                            $biaoti = $body[5];
                            $guanjianzi = $body[6];
                            $guanjianziold = $guanjianzi;
                            $miaoshu = $body[7];
                } else {
                    error_log("[分组] 缓存更新失败，使用原缓存");
                        }
                    }
                }
                
                // 2. 检查克隆源切换
                if (isset($groupInfo['clone_source_switch']) && $groupInfo['clone_source_switch']['enabled']) {
                    $sourceTrigger = $groupInfo['clone_source_switch']['trigger_visits'];
                    error_log("[分组] 克隆源切换检查: {$visitCount}/{$sourceTrigger}");
                    
                    if ($visitCount >= $sourceTrigger) {
                        error_log("[分组] 触发克隆源切换: {$djym}");
                        
                        // 执行克隆源切换（换汤不换药）
                        $switchResult = $groupManager->switchCloneSourceWithCache($djym, $groupInfo, $cachefile);
                        
                        if ($switchResult) {
                            error_log("[分组] 克隆源切换成功，已更新缓存并保持TDK不变");
                            // 重新读取更新后的缓存
                            $nr = file_get_contents($cachefile);
                            
                            // 重新读取配置文件，更新TDK变量
                            $body = duixiang();
                            $biaoti = $body[5];
                            $guanjianzi = $body[6];
                            $guanjianziold = $guanjianzi;
                            $miaoshu = $body[7];
                            
                            // 重置计数器
                            if ($groupInfo['clone_source_switch']['reset_counter'] ?? true) {
                                $groupManager->resetVisitCounter($djym);
                            }
                        } else {
                            error_log("[分组] 克隆源切换失败");
                        }
                    }
                }
            }
        }
        // ===== 分组功能结束 =====
        
        // 缓存大小达标，正常处理
        // $nr = RandIcp($nr); // 已禁用备案号替换
        
        // 删除缓存中所有的 <h1> 标签（避免重复）
        $nr = preg_replace('@<body([^>]*)>\s*<h1[^>]*>.*?</h1>@is', '<body$1>', $nr);
        $nr = preg_replace('@<h1[^>]*>.*?</h1>@is', '', $nr);
        
        // 重新插入 <h1> 标签（使用Unicode转码的标题）
        $encodedH1Title = unicode_encode($biaoti);
        $nr = preg_replace("/<body(.*)>/i", "<body$1>\n<h1>".$encodedH1Title."</h1>", $nr, 1);
        
        $nr = AddKeys($nr, $guanjianziold);
        $nr = str_replace(
            array('href="/"', "href='/'"),
            array('href="/index.html"', "href='/index.html'"),
            $nr
        );

        $nr = str_replace($tihuanci, $beitihuanci, $nr);
        if ($sy) {
            $nr = preg_replace('@<meta([^>]*?)("keywords"|\'keywords\'|keywords)([^>]*?)>@is', '', $nr);
            $nr = preg_replace('@<meta([^>]*?)("description"|\'description\'|description)([^>]*?)>@is', '', $nr);
            $nr = preg_replace("@<title>(.*?)</title>@is", "<title>" . $biaoti . "</title>\r\n<meta name=\"keywords\" content=" . $guanjianzi . " />\r\n<meta name=\"description\" content=" . $miaoshu . " />", $nr);
        }
        $nr = $chinese->gb2312_big5($nr);
        
        // 在输出内容之前
        if ($enableRandomContent) {
            $cachefile_ganrao = get_ganrao();
            if (is_file($cachefile_ganrao)) {
                $ganrao = file_get_contents($cachefile_ganrao);
                $nr = str_replace("</body>", $ganrao . "</body>", $nr);
            }
        }
        
        // ===== 最后一步：插入友情链接（动态插入到指定位置）=====
        // 注意：这是页面渲染的最后一步，在所有内容处理完成后才插入友链
        // 友链HTML已在 add.php 中提前准备好，这里只是动态插入到页面
        if (function_exists('getFriendLinkHTML')) {
            $friendLinksHtml = getFriendLinkHTML();
            if (!empty($friendLinksHtml)) {
                // 先移除缓存中可能存在的旧友情链接（避免重复）
                $friendlink_pattern = '@<table[^>]*id=["\']table1["\'][^>]*>[\s\S]*?</table>@i';
                $nr = preg_replace($friendlink_pattern, '', $nr);
                
                // 循环清理，确保移除所有table1
                $max_iterations = 10;
                $iteration = 0;
                while (stripos($nr, 'id="table1"') !== false && $iteration < $max_iterations) {
                    $nr = preg_replace($friendlink_pattern, '', $nr);
                    $iteration++;
                }
                
                // 然后在指定位置插入新的友情链接
                // 默认位置：在 </body> 前插入
                if (stripos($nr, '</body>') !== false) {
                    $nr = str_replace("</body>", $friendLinksHtml . "\n</body>", $nr);
                } else {
                    // 如果找不到 </body> 标签，就在页面最底部追加
                    $nr = $nr . "\n" . $friendLinksHtml;
                }
                
                // 记录调试日志
                error_log('[友链] 友情链接已插入页面（位置：</body>前）');
            }
        }
        // ===== 友情链接插入完成 =====
        
        // 检测页面是否为空白，如果是则301跳转到首页
        if ($enable301Redirect && isBlankPage($nr)) {
            redirectToHome();
        }
        
        // 动态处理标题：在所有 </title> 前添加 "-哔哩哔哩_bilibili"
        $nr = str_replace('</title>', '-哔哩哔哩_bilibili</title>', $nr);
        
        echo $nr;
        exit();
        }
    }
}

$url = str_replace(" ", "%20", $url);
$url = str_replace(array('%06', '%07', '%05', '%08', "%EF%BB%BF"), "", $url);
$nr = get_content($url);

// ===== 克隆失败检测与自动修复 =====
// 检查页面大小是否达标（包括空内容）
if (empty($nr) || !checkPageSize($nr, $minPageSize)) {
    error_log("克隆失败或内容大小不达标（" . strlen($nr) . " 字节），启动自动修复");
    
    // 尝试使用缓存复制
    $fallbackContent = handleCloneFallback($djym);
    
    if ($fallbackContent !== false) {
        // 使用缓存复制的内容
        $nr = $fallbackContent;
        error_log("使用缓存复制内容成功（" . strlen($nr) . " 字节）");
    } else {
        // 缓存复制也失败，尝试重新生成配置并克隆
        error_log("缓存复制失败，尝试重新生成配置");
        
        // 重新生成配置（更换克隆源）
        global $autoGenerator;
        $regenerateResult = $autoGenerator->generateConfig($djym, $autoGenerator->getConfigMode());
        
        if ($regenerateResult['success']) {
            error_log("配置重新生成成功，重新克隆");
            // 重新读取配置
            $body = duixiang();
            $mubiao = $body[0];
            $url = 'http://' . $mubiao . $lujing;
            $url = str_replace(" ", "%20", $url);
            $url = str_replace(array('%06', '%07', '%05', '%08', "%EF%BB%BF"), "", $url);
            $nr = get_content($url);
            
            // 如果还是不达标，301跳转
            if (empty($nr) || !checkPageSize($nr, $minPageSize)) {
                error_log("重新克隆仍然失败，执行301跳转");
                if ($enable301Redirect) {
                    redirectToHome();
                }
            }
        } else {
            error_log("配置重新生成失败，执行301跳转");
            if ($enable301Redirect) {
                redirectToHome();
            }
        }
    }
}

if ($nr != "") {
    $cacheDir = './cachefile_yuan/' . $djym;
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    createGanraoFile($cacheDir);
    $nr = processAndSaveAnnotations($nr, $cacheDir);
}

$encode = mb_detect_encoding($nr, array("ASCII", "UTF-8", "GB2312", "GBK", "BIG5"));
if ($encode !== "UTF-8") {
    $nr = iconv('gbk', 'utf-8//IGNORE', $nr);
    $tihuanshouye = array('gbk' => 'utf-8', 'gb2312' => 'utf-8', 'GBK' => 'utf-8', 'GB2312' => 'utf-8', 'BIG5' => 'utf-8', 'big5' => 'utf-8');
    $nr = strtr($nr, $tihuanshouye);
}
$nr= preg_replace("@hm.baidu.com(.*?)('|\")@is","hm.baidu.com$1ar'", $nr);
$nr= str_replace("cnzz.com","cnzz.co", $nr);
$nr= str_replace('users.51.la','user.51.la', $nr);
$nr= str_replace('"//','"http://', $nr);
$nr= str_replace("'//","'http://", $nr);

$nr= str_replace(top_domain($mubiao),$djym, $nr);

// 处理外部链接 - 将所有非本站的http链接改为#
// 首先保存所有指向本站的链接
$nr = preg_replace('@href="http://(?!www\.'.$djym.'|'.$djym.')([^"]*)"@is', 'href="#"', $nr);
$nr = preg_replace("@href='http://(?!www\.".$djym."|".$djym.")([^']*)'@is", "href='#'", $nr);
$nr = preg_replace('@href="https://(?!www\.'.$djym.'|'.$djym.')([^"]*)"@is', 'href="#"', $nr);
$nr = preg_replace("@href='https://(?!www\.".$djym."|".$djym.")([^']*)'@is", "href='#'", $nr);

/*站群模式
$nr=preg_replace('@<a (?!(rel=|).*)(.*?)href="http@is','<a $2rel="kaishinofollowkaishi" href="http',$nr);
$nr=preg_replace("@<a (?!(rel=|).*)(.*?)href='http@is","<a $2rel='kaishinofollowkaishi' href='http",$nr);
*/
// 移除option标签的nofollow处理，因为已经不需要了
// $nr= str_replace('<option','<option rel="nofollow"', $nr);
$nr= str_replace('"http://www.'.$djym,'"/', $nr);
$nr= str_replace("'http://www.".$djym,"'/", $nr);
$nr= str_replace('"http://'.$djym,'"/', $nr);
$nr= str_replace("'http://".$djym,"'/", $nr);
$nr= str_replace('"//','"/', $nr);
$nr= str_replace("'//","'/", $nr);
$idzhi=substr(md5($dqurl),0,10);
$nr=preg_replace('@<(div|li|h3|a) (?!(id=|>).*)@is','<$1 id="'.$idzhi.'" ',$nr);
//require_once('zhanqun.php');
//$nr=zhanqun($nr);//站群模式
$yuan=array('/iPhone/i','/eval/i','/ipod/i','/android/i','/ios/i','/phone/i','/webos/i','/mobile/i','/ucweb/i','/midp/i','/windows ce/i','/location/i','/ipad/i');
$hou=array('iphones','evals','ipods','androids','ioses','phones','weboses','mobiles','ucwebs','midps','windows ces','locations','ipads');
$nr= preg_replace($yuan,$hou, $nr);

$chalink = "<div style=\"display:none;\"><script src=\"".$http.$_SERVER['HTTP_HOST']."/tj.js\"></script></div>";

// 确保输出的编码正确
// $chalink = mb_convert_encoding($chalink, 'UTF-8', 'UTF-8');

//$nr = (stristr ($nr, '</body>') != '' ? preg_replace ('/<\/body>/i', $chalink . '</body>', $nr) : $nr . $chalink);
$nr= str_replace("</body>",$chalink."</body>", $nr);
//增加干扰代码
$nr= str_replace('rel="canonical" href="/','rel="canonical" href="'.$http.$_SERVER['HTTP_HOST'].'/', $nr);
$shuchushouji='<link rel="canonical" href="'.$dqurl.'"/>
<meta name="mobile-agent" content="format=[wml|xhtml|html5];url='.$m_url.'" />
<link href="'.$m_url.'" rel="alternate" media="only screen and (max-width: 640px)" />
<meta http-equiv="Cache-Control" content="no-siteapp" />
<meta http-equiv="Cache-Control" content="no-transform" />
<meta name="applicable-device" content="pc,mobile">
<meta name="MobileOptimized" content="width" />
<meta name="HandheldFriendly" content="true" />
<meta name="viewport" content="width=device-width,initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no" />';

//指定手机版
$nr=str_replace("</title>","</title>\r\n".$shuchushouji,$nr);
$nr=preg_replace("/<body(.*)>/i", "<body$1>\n<h1>".$guanjianzi."</h1>",$nr);
$nr=preg_replace("/<body(.*)>/i", "<body>\n".rand_body_label(),$nr);

//$nrr=daima($nr);
//write($cachefile,$nrr);
	$nr= str_replace($tihuanci,$beitihuanci,$nr);
	$nr=preg_replace("@<title>(.*?)</title>@is","<title>".$biaoti."</title>",$nr);
	$nr = $chinese->gb2312_big5($nr);
	 // 确保在这里调用 applyAnnotations
	 $cacheDir = './cachefile_yuan/' . $djym;
       $nr = applyAnnotations($nr, $cacheDir);
       
       // 注意：不在这里插入友情链接，保持缓存干净
       // 友情链接将在输出时动态插入（见下方第147-159行）
       
      write($cachefile, $nr);
	  
	  // 在处理内容之前，确保 ganrao.txt 文件存在
	  $cacheDir = './cachefile_yuan/' . $djym;
	  createGanraoFile($cacheDir);

	// 在输出内容之前
	if ($enableRandomContent) {
		$cachefile_ganrao = get_ganrao();
		if (is_file($cachefile_ganrao)) {
			$ganrao = file_get_contents($cachefile_ganrao);
			$nr = str_replace("</body>", $ganrao . "</body>", $nr);
		}
	}
	
	// ===== 最后一步：插入友情链接（动态插入到指定位置）=====
	// 注意：这是页面渲染的最后一步，在所有内容处理完成后才插入友链
	// 友链HTML已在 add.php 中提前准备好，这里只是动态插入到页面
	if (function_exists('getFriendLinkHTML')) {
		$friendLinksHtml = getFriendLinkHTML();
		if (!empty($friendLinksHtml)) {
			// 先移除可能存在的旧友情链接（避免重复）
			$friendlink_pattern = '@<table[^>]*id=["\']table1["\'][^>]*>[\s\S]*?</table>@i';
			$nr = preg_replace($friendlink_pattern, '', $nr);
			
			// 循环清理，确保移除所有table1
			$max_iterations = 10;
			$iteration = 0;
			while (stripos($nr, 'id="table1"') !== false && $iteration < $max_iterations) {
				$nr = preg_replace($friendlink_pattern, '', $nr);
				$iteration++;
			}
			
			// 然后在指定位置插入新的友情链接
			// 默认位置：在 </body> 前插入
			if (stripos($nr, '</body>') !== false) {
				$nr = str_replace("</body>", $friendLinksHtml . "\n</body>", $nr);
			} else {
				// 如果找不到 </body> 标签，就在页面最底部追加
				$nr = $nr . "\n" . $friendLinksHtml;
			}
			
			// 记录调试日志
			error_log('[友链] 友情链接已插入页面（位置：</body>前）');
		}
	}
	// ===== 友情链接插入完成 =====
   
	// 检测页面是否为空白，如果是则301跳转到首页
	if ($enable301Redirect && isBlankPage($nr)) {
		redirectToHome();
	}
	
	// 动态处理标题：在所有 </title> 前添加 "-哔哩哔哩_bilibili"
	$nr = str_replace('</title>', '-哔哩哔哩_bilibili</title>', $nr);
   
	echo $nr;

$t2 = microtime(true);
exit();

$xcfuhao=xcfuhao();
$nr = str_replace(array("\r\n\r\n","\r\r","\n\n",$xcfuhao[0],$xcfuhao[1],$xcfuhao[2],$xcfuhao[3]),"",$nr);
echo $nr;

$t2 = microtime(true);
exit();

?>
