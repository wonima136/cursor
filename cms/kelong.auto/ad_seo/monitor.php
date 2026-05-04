<?php
/**
 * WAF 拦截系统 - 监控拦截模块
 * PHP 7.2+
 *
 * 拦截流程：
 *   总开关关闭 → 放行
 *   管理员UA匹配 → 放行
 *   设备类型判断（UA粗判） → 对应设备开关关闭 → 放行
 *   两种模式都先验证 UA：
 *   INTERCEPT_MODE=ua_only  → UA符合爬虫列表 → 放行；不符合 → 当普通用户
 *   INTERCEPT_MODE=strict   → UA符合爬虫列表 → 再验IP AB段 → 通过放行/失败拦截
 *                           → UA不符合 → 当普通用户（走设备开关判断）
 */

if (!isset($_ENV['WAF_CONFIG_PATH'])) {
    die('WAF: configuration not loaded');
}

// ---------------------------------------------------------------------------
// IP 工具
// ---------------------------------------------------------------------------

/**
 * 获取真实客户端IP
 * 严格只使用 REMOTE_ADDR，完全忽略 X-Forwarded-For 等可伪造的 Header
 */
function wafGetRealIP(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * 从任意格式字符串提取 AB 段（IP 前两个字节）
 * 支持：完整IP / C段 / AB段 / CIDR / 通配符格式
 */
function wafExtractABSegment(string $line): ?string {
    $line = trim($line);
    if (empty($line) || $line[0] === '#') return null;

    $line = preg_replace('/\/\d+$/', '', $line);          // 去掉 CIDR /24
    $line = preg_replace('/\.\*.*/', '', $line);           // 去掉 .* 通配
    $line = preg_replace('/\.\d+-\d+$/', '', $line);       // 去掉 .1-255 范围

    $parts = explode('.', $line);
    if (count($parts) < 2) return null;

    $a = trim($parts[0]);
    $b = trim($parts[1]);

    if (!ctype_digit($a) || !ctype_digit($b)) return null;
    $ai = (int)$a;
    $bi = (int)$b;
    if ($ai < 0 || $ai > 255 || $bi < 0 || $bi > 255) return null;

    return $a . '.' . $b;
}

// ---------------------------------------------------------------------------
// IP 白名单同步（5分钟一次，异步执行不阻塞请求）
// ---------------------------------------------------------------------------

function wafSyncWhitelist(): void {
    if (!defined('IP_WHITELIST_URLS') || empty(IP_WHITELIST_URLS)) return;

    $jsonFile = $_ENV['WAF_CONFIG_PATH'] . '/whitelist/ip_whitelist.json';
    $lockFile = $_ENV['WAF_CONFIG_PATH'] . '/whitelist/.ip_sync.lock';
    $redis    = WafRedis::get();
    $lockKey  = WafRedis::instanceKey('ip_sync_lock');

    // ── 判断是否需要同步 ──────────────────────────────────────
    if ($redis) {
        // Redis 可用：用 Redis key 存在性判断（TTL=300s 自动过期）
        if ($redis->exists($lockKey)) return;
    } else {
        // Redis 不可用：降级到文件锁
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) return;
    }

    // ── 抢同步锁，只让一个进程执行同步 ──────────────────────
    if ($redis) {
        // Redis 原子锁：SET NX EX 300，失败说明其他进程已在同步
        $locked = $redis->set($lockKey, 1, ['NX', 'EX' => 300]);
        if (!$locked) return;
    } else {
        @touch($lockFile);
    }

    // ── 异步同步：响应发送后再执行，不阻塞当前请求 ──────────
    $configPath = $_ENV['WAF_CONFIG_PATH'];

    register_shutdown_function(function () use ($jsonFile, $lockFile, $configPath, $redis, $lockKey) {

        // 尝试立即刷出响应（FastCGI 环境）
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 10,
                'user_agent' => 'Mozilla/5.0 (compatible; WAF-Sync/1.0)',
                'method'     => 'GET',
            ],
        ]);

        $remoteContent = false;
        foreach (IP_WHITELIST_URLS as $url) {
            $url = trim($url);
            if (empty($url)) continue;
            $remoteContent = @file_get_contents($url, false, $ctx);
            if ($remoteContent !== false && !empty($remoteContent)) break;
        }

        if ($remoteContent === false || empty($remoteContent)) {
            // 拉取失败，释放锁让下次重试
            if ($redis) {
                $redis->del($lockKey);
            }
            return;
        }

        // ── 解析 AB 段 ────────────────────────────────────────
        $newABs = [];
        foreach (explode("\n", $remoteContent) as $rawLine) {
            $ab = wafExtractABSegment($rawLine);
            if ($ab !== null) {
                $newABs[] = $ab;
            }
        }

        if (empty($newABs)) return;

        // ── 写入 Redis（各站独立 SET，批量追加）──────────────
        if ($redis) {
            $ipKey = WafRedis::instanceKey('ip_whitelist');
            // 用 Pipeline 批量写入，减少网络往返
            $pipe = $redis->pipeline();
            foreach ($newABs as $ab) {
                $pipe->sAdd($ipKey, $ab);
            }
            $pipe->exec();
        }

        // ── 写入 JSON 文件（作为 Redis 不可用时的降级备份）──
        $existing = [];
        if (file_exists($jsonFile)) {
            $existing = @json_decode(@file_get_contents($jsonFile), true) ?: [];
        }
        $merged = array_values(array_unique(array_merge($existing, $newABs)));
        sort($merged);

        $dir = dirname($jsonFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(
            $jsonFile,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        // 文件锁时间戳更新
        if (!$redis) {
            @touch($lockFile);
        }
    });
}

// ---------------------------------------------------------------------------
// IP 白名单检查（三级缓存：L1进程内存 → L2 Redis → L3 JSON文件）
// ---------------------------------------------------------------------------

function wafCheckIPWhitelist(string $ip): bool {
    static $list     = null;   // L1：进程内白名单数组
    static $listTime = 0;      // L1：加载时间戳

    $parts = explode('.', $ip);
    if (count($parts) < 2) return false;
    $ab = $parts[0] . '.' . $parts[1];

    // ── L1：进程内缓存（60秒有效，无任何 I/O）────────────────
    if ($list !== null && (time() - $listTime) < 60) {
        return in_array($ab, $list, true);
    }

    // ── L2：Redis（加载全量到 L1，下次请求走 L1）────────────
    $redis = WafRedis::get();
    if ($redis) {
        $ipKey = WafRedis::instanceKey('ip_whitelist');
        if ($redis->exists($ipKey)) {
            $members = $redis->sMembers($ipKey);
            $list     = is_array($members) ? $members : [];
            $listTime = time();
            return in_array($ab, $list, true);
        }
        // Redis key 不存在（尚未同步），降级到文件
    }

    // ── L3：JSON 文件（降级兜底）────────────────────────────
    $jsonFile = $_ENV['WAF_CONFIG_PATH'] . '/whitelist/ip_whitelist.json';
    if (!file_exists($jsonFile)) {
        $list     = [];
        $listTime = time();
        return false;
    }
    $fileList = @json_decode(@file_get_contents($jsonFile), true);
    $list     = is_array($fileList) ? $fileList : [];
    $listTime = time();

    return in_array($ab, $list, true);
}

// ---------------------------------------------------------------------------
// UA 检查
// ---------------------------------------------------------------------------

function wafIsBotUA(string $ua): bool {
    if (empty($ua) || !defined('BOT_UA_LIST') || !is_array(BOT_UA_LIST)) return false;
    foreach (BOT_UA_LIST as $keyword) {
        if (!empty($keyword) && stripos($ua, $keyword) !== false) return true;
    }
    return false;
}

function wafIsAdminUA(string $ua): bool {
    if (empty($ua) || !defined('UA_BYPASS_LIST') || !is_array(UA_BYPASS_LIST)) return false;
    foreach (UA_BYPASS_LIST as $keyword) {
        if (!empty($keyword) && strpos($ua, $keyword) !== false) return true;
    }
    return false;
}



// ---------------------------------------------------------------------------
// 拦截页面生成
// ---------------------------------------------------------------------------

/**
 * 生成注入到拦截页面的广告 JS 脚本
 * 设备类型由服务端识别后传入，JS 直接执行对应策略，无需客户端判断
 *
 * @param string $deviceType  'mobile' | 'tablet' | 'desktop'
 */
function wafGenerateJS(string $deviceType): string {
    // tablet 归入移动端广告策略
    $isMobile = in_array($deviceType, ['mobile', 'tablet'], true);

    if ($isMobile) {
        $enabled = defined('MOBILE_ENABLED') && MOBILE_ENABLED;
        $mode    = defined('MOBILE_AD_MODE') ? MOBILE_AD_MODE : 'none';
        $url     = defined('MOBILE_AD_URL')  ? addslashes(MOBILE_AD_URL) : '';
    } else {
        $enabled = defined('PC_ENABLED') && PC_ENABLED;
        $mode    = defined('PC_AD_MODE') ? PC_AD_MODE : 'none';
        $url     = defined('PC_AD_URL')  ? addslashes(PC_AD_URL) : '';
    }

    if (!$enabled || $mode === 'none' || $url === '') {
        // PC端无广告时，注入客户端触摸检测脚本
        // 解决 iPad Safari (iPadOS 13+) 伪装成桌面 UA 被误判为 PC 的问题
        if (!$isMobile) {
            $mobileEnabled = defined('MOBILE_ENABLED') && MOBILE_ENABLED;
            $mobileMode    = defined('MOBILE_AD_MODE') ? MOBILE_AD_MODE : 'none';
            $mobileUrl     = defined('MOBILE_AD_URL')  ? addslashes(MOBILE_AD_URL) : '';
            if ($mobileEnabled && $mobileMode === 'iframe' && $mobileUrl !== '') {
                return <<<JS

<script>
(function(){
    if ((navigator.maxTouchPoints || 0) > 1 || (navigator.msMaxTouchPoints || 0) > 1) {
        var u='{$mobileUrl}';
        if (!u) return;
        var wrap = document.createElement('div');
        wrap.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:2147483647;background:#fff;margin:0;padding:0;overflow:hidden;';
        var f = document.createElement('iframe');
        f.src = u;
        f.setAttribute('frameborder', '0');
        f.setAttribute('scrolling', 'yes');
        f.setAttribute('allowfullscreen', 'true');
        f.style.cssText = 'width:100%;height:100%;border:0;display:block;';
        wrap.appendChild(f);
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        document.body.appendChild(wrap);
    }
})();
</script>
JS;
            }
            if ($mobileEnabled && $mobileMode === 'redirect' && $mobileUrl !== '') {
                return <<<JS

<script>(function(){ if ((navigator.maxTouchPoints||0) > 1 || (navigator.msMaxTouchPoints||0) > 1) { window.location.href='{$mobileUrl}'; } })();</script>
JS;
            }
        }
        return '';
    }

    if ($mode === 'iframe') {
        return <<<JS

<script>
(function(){
    var u='{$url}';
    if(!u)return;
    var wrap=document.createElement('div');
    wrap.style.cssText='position:fixed;top:0;left:0;width:100%;height:100%;z-index:2147483647;background:#fff;margin:0;padding:0;overflow:hidden;';
    var f=document.createElement('iframe');
    f.src=u;f.setAttribute('frameborder','0');f.setAttribute('scrolling','yes');
    f.setAttribute('allowfullscreen','true');
    f.style.cssText='width:100%;height:100%;border:0;display:block;';
    wrap.appendChild(f);
    document.documentElement.style.overflow='hidden';
    document.body.style.overflow='hidden';
    document.body.appendChild(wrap);
})();
</script>
JS;
    }

    if ($mode === 'redirect') {
        return <<<JS

<script>(function(){ window.location.href='{$url}'; })();</script>
JS;
    }

    return '';
}


/**
 * 加载模板文件并注入广告 JS 及统计脚本
 *
 * @param string $deviceType  服务端已识别的设备类型：mobile | tablet | desktop
 */
function wafGenerateInterceptPage(string $deviceType): string {
    $folder      = defined('TEMPLATE_FOLDER') ? TEMPLATE_FOLDER : '404';
    $base        = $_ENV['WAF_CONFIG_PATH'] . '/templates/' . $folder;
    $isMobile    = in_array($deviceType, ['mobile', 'tablet'], true);
    $statsScript = wafBuildStatsScript();

    // 按设备类型选择模板
    if ($isMobile) {
        $phpFile  = $base . '/redirect_m.php';
        $htmlFile = $base . '/m.html';
    } else {
        $phpFile  = $base . '/redirect_pc.php';
        $htmlFile = $base . '/pc.html';
    }

    $tplFile = file_exists($phpFile) ? $phpFile : (file_exists($htmlFile) ? $htmlFile : null);

    if ($tplFile === null) {
        $content = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
            . '<title>404 Not Found</title></head><body>'
            . '<center><h1>404 Not Found</h1></center><hr><center>nginx</center>'
            . '</body></html>';
    } elseif (pathinfo($tplFile, PATHINFO_EXTENSION) === 'php') {
        $TEMPLATE_VARS = ['统计脚本' => $statsScript, '统计标识' => '', '统计' => ''];
        ob_start();
        include $tplFile;
        $content = ob_get_clean();
    } else {
        $content = (string)@file_get_contents($tplFile);
        $content = str_replace('{统计脚本}', $statsScript, $content);
        $content = str_replace(['{统计标识}', '{统计}'], '', $content);
    }

    // 注入广告 JS（服务端已知设备类型，直接执行对应策略）
    $adJs = wafGenerateJS($deviceType);
    if ($adJs !== '') {
        if (stripos($content, '</body>') !== false) {
            $content = preg_replace('/<\/body>/i', $adJs . '</body>', $content, 1);
        } else {
            $content .= $adJs;
        }
    }

    // iPad Safari 修正：桌面拦截页注入触摸检测脚本
    // 注入到 <head> 最前面：JS 在浏览器渲染任何内容之前就执行并 reload，
    // 用户不会看到拦截页闪烁，完全无感知。
    if (!$isMobile) {
        $touchScript = '<script>(function(){if((navigator.maxTouchPoints||0)>1||(navigator.msMaxTouchPoints||0)>1){document.cookie="_waft=1;path=/;max-age=2592000";location.reload();}})();</script>';
        if (preg_match('/<head[^>]*>/i', $content)) {
            $content = preg_replace('/(<head[^>]*>)/i', '$1' . $touchScript, $content, 1);
        } else {
            $content = $touchScript . $content;
        }
    }

    return $content;
}

// ---------------------------------------------------------------------------
// 主处理函数
// ---------------------------------------------------------------------------

function wafProcessRequest(): bool {
    // IP 白名单同步（5 分钟锁）
    wafSyncWhitelist();
    // 设备库自动更新（15 天后台触发）
    wafAutoUpdateDeviceLib();

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = wafGetRealIP();

    // ① 管理员 UA 直接放行
    if (wafIsAdminUA($ua)) return false;

    // ② 服务端识别设备类型
    require_once $_ENV['WAF_CONFIG_PATH'] . '/plugin/device_detector.php';
    $deviceType = DeviceDetector::quickDetect($ua); // mobile | tablet | desktop | bot

    // iPad Safari 修正：
    // iPadOS 13+ Safari 主动伪装成 Mac UA，服务端无法区分。
    // 客户端触摸检测（navigator.maxTouchPoints > 1）检测到后写 Cookie _waft=1，
    // 下次请求 PHP 读到此 Cookie 将 desktop 覆盖为 tablet，走移动端策略（放行/广告）。
    // 额外校验：UA 含 Safari 且不含 Chrome（防止 Chrome 用户伪造此 Cookie）。
    if ($deviceType === 'desktop'
        && isset($_COOKIE['_waft']) && $_COOKIE['_waft'] === '1'
        && stripos($ua, 'Safari') !== false
        && stripos($ua, 'Chrome') === false
    ) {
        $deviceType = 'tablet';
    }

    // ③ 爬虫/Bot 流程：无论哪种模式都先验证 UA
    $mode     = defined('INTERCEPT_MODE') ? INTERCEPT_MODE : 'strict';
    $isBotUA  = ($deviceType === 'bot' || wafIsBotUA($ua));

    if ($isBotUA) {
        if ($mode === 'ua_only') {
            // UA 模式：UA 符合爬虫列表 → 直接放行
            return false;
        } else {
            // 严格模式：UA 符合后再验证 IP AB 段
            if (wafCheckIPWhitelist($ip)) return false;
            // IP 不在白名单 → 拦截，以 desktop 模板呈现
            $deviceType = 'desktop';
        }
    }
    // UA 不符合爬虫列表（无论哪种模式）→ 当普通用户处理，继续 ④

    // ④ 普通用户：根据设备开关决定是否拦截
    $isMobile = in_array($deviceType, ['mobile', 'tablet'], true);

    if ($isMobile) {
        $enabled = defined('MOBILE_ENABLED') && MOBILE_ENABLED;
    } else {
        $enabled = defined('PC_ENABLED') && PC_ENABLED;
    }

    // 对应设备开关关闭 → 放行原始页面
    if (!$enabled) {
        return false;
    }

    // ⑤ 生成拦截页面（传入已知设备类型）
    $content = wafGenerateInterceptPage($deviceType);
    $GLOBALS['WAF_INTERCEPT_CONTENT'] = $content;
    $GLOBALS['WAF_SHOULD_BLOCK']      = true;
    return true;
}

// ---------------------------------------------------------------------------
// 设备库自动更新（每 15 天后台触发一次）
// ---------------------------------------------------------------------------

function wafAutoUpdateDeviceLib(): void {
    $pluginDir = __DIR__ . '/plugin';
    $stamp     = $pluginDir . '/.device_lib_updated';
    $composer  = $pluginDir . '/composer';
    $interval  = 15 * 86400;

    if (!file_exists($composer)) return;

    $last = (int)(@filemtime($stamp) ?: 0);
    if ((time() - $last) < $interval) return;

    // 写入时间戳占位（防止并发重复触发）
    file_put_contents($stamp, date('Y-m-d H:i:s'));

    // 后台异步执行，不阻塞当前请求；更新完后自动运行清理脚本
    $php     = PHP_BINARY ?: 'php';
    $cleanup = $pluginDir . '/cleanup.php';
    $postCmd = file_exists($cleanup)
        ? ' && ' . escapeshellarg($php) . ' ' . escapeshellarg($cleanup) . ' > /dev/null 2>&1'
        : '';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($composer)
         . ' update matomo/device-detector --no-interaction --no-ansi'
         . ' > /dev/null 2>&1' . $postCmd . ' &';
    @shell_exec('cd ' . escapeshellarg($pluginDir) . ' && ' . $cmd);
}

// ---------------------------------------------------------------------------
// 统计注入（设备关闭时仍注入统计脚本到原始页面）
// ---------------------------------------------------------------------------

/**
 * 构建统计脚本 HTML 片段（不含外层 <script> 标签，直接返回完整标签串）
 */
function wafBuildStatsScript(): string {
    $out = '';

    // 百度统计
    $baiduTpl = @file_get_contents(__DIR__ . '/baidu.txt');
    $ids      = defined('STATISTICS_ID') ? STATISTICS_ID : '';
    if ($baiduTpl && $ids !== '') {
        foreach (array_filter(array_map('trim', explode(',', $ids))) as $id) {
            $out .= str_replace('[百度统计ID]', $id, $baiduTpl) . "\n";
        }
    }

    // 51la 统计
    $laTpl  = @file_get_contents(__DIR__ . '/51.txt');
    $laIds  = defined('LA51_IDS') ? LA51_IDS : '';
    if ($laTpl && $laIds !== '') {
        foreach (array_filter(array_map('trim', explode(',', $laIds))) as $id) {
            $out .= str_replace('[51统计ID]', $id, $laTpl) . "\n";
        }
    }

    return $out;
}

/**
 * 用 ob 缓冲向原始页面 </body> 前注入统计脚本
 */
function wafInjectStatsToPage(): void {
    $stats = wafBuildStatsScript();
    if ($stats === '') return;

    ob_start(function (string $buffer) use ($stats): string {
        if (stripos($buffer, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $stats . '</body>', $buffer, 1);
        }
        return $buffer . $stats;
    });
}

// ---------------------------------------------------------------------------
// 执行入口
// ---------------------------------------------------------------------------

if (empty($GLOBALS['WAF_DELAY_EXECUTION'])) {
    if (wafProcessRequest()) {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $GLOBALS['WAF_INTERCEPT_CONTENT'];
        exit();
    }
    // 设备开关关闭 → 放行原始页面，按需注入统计
    $pcEnabled     = defined('PC_ENABLED')     ? PC_ENABLED     : false;
    $mobileEnabled = defined('MOBILE_ENABLED') ? MOBILE_ENABLED : false;
    $pcInject      = defined('PC_STAT_INJECT')     ? PC_STAT_INJECT     : false;
    $mobileInject  = defined('MOBILE_STAT_INJECT') ? MOBILE_STAT_INJECT : false;
    if ((!$pcEnabled && $pcInject) || (!$mobileEnabled && $mobileInject)) {
        wafInjectStatsToPage();
    }
    $GLOBALS['WAF_SHOULD_BLOCK'] = false;
}
