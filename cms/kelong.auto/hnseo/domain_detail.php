<?php
/**
 * 单个域名详细统计页面
 * 采用与tongji.php相同的样式和维度
 * 支持AJAX分页、日期切换、折线图联动
 */

// 引入域名统计处理文件
include_once('domain_stats.php');

// 获取参数
$domain = isset($_GET['domain']) ? $_GET['domain'] : '';
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : (isset($_GET['date']) ? $_GET['date'] : date('Ymd'));

if (empty($domain)) {
    die('域名参数缺失');
}

// AJAX请求：获取分页数据
if (isset($_GET['ajax']) && $_GET['ajax'] == 'visits') {
    header('Content-Type: application/json; charset=utf-8');
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['pageSize']) ? max(10, min(100, intval($_GET['pageSize']))) : 50;
    $spiderFilter = isset($_GET['spider']) ? $_GET['spider'] : 'all';
    $dateRangeParam = isset($_GET['date_range']) ? $_GET['date_range'] : (isset($_GET['current_date']) ? $_GET['current_date'] : date('Ymd'));
    
    try {
        $db = getSpiderDB();
        list($start_date, $end_date) = parseDateRange($dateRangeParam);
        
        // 判断是否为日期范围模式
        $isRange = ($start_date !== $end_date);
        
        // 构建蜘蛛过滤条件
        $spiderCondition = "";
        if ($spiderFilter !== 'all') {
            switch ($spiderFilter) {
                case '百度':
                    $spiderCondition = " AND (spider_name = '百度' OR spider_name = '百度PC' OR spider_name = '百度移动')";
                    break;
                case '谷歌':
                    $spiderCondition = " AND spider_name = '谷歌'";
                    break;
                case '360':
                    $spiderCondition = " AND spider_name = '360'";
                    break;
                case '搜狗':
                    $spiderCondition = " AND spider_name = '搜狗'";
                    break;
                case '神马':
                    $spiderCondition = " AND spider_name = '神马'";
                    break;
                case '今日头条':
                    $spiderCondition = " AND spider_name = '今日头条'";
                    break;
            }
        }
        
        // 如果是日期范围，需要遍历多个数据库
        $totalCount = 0;
        $allVisits = [];
        $stats = [
            'total' => 0, 'baidu' => 0, 'baidu_pc' => 0, 'baidu_mobile' => 0,
            'google' => 0, 'sogou' => 0, 's360' => 0, 'yisou' => 0, 'byte' => 0
        ];
        
        $current = strtotime($start_date);
        $end = strtotime($end_date);
        
        // 先统计总数和蜘蛛分布
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $pdo = $db->getDBByDate($date);
            
            if ($pdo) {
                // 获取当天总数
                $countSql = "SELECT COUNT(*) as cnt FROM spider_visits WHERE domain = ?" . $spiderCondition;
                $stmt = $pdo->prepare($countSql);
                $stmt->execute([$domain]);
                $totalCount += (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
                
                // 获取蜘蛛统计
                $statsSql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN spider_name = '百度' OR spider_name = '百度PC' OR spider_name = '百度移动' THEN 1 ELSE 0 END) as baidu,
                    SUM(CASE WHEN spider_name = '百度PC' THEN 1 ELSE 0 END) as baidu_pc,
                    SUM(CASE WHEN spider_name = '百度移动' THEN 1 ELSE 0 END) as baidu_mobile,
                    SUM(CASE WHEN spider_name = '谷歌' THEN 1 ELSE 0 END) as google,
                    SUM(CASE WHEN spider_name = '搜狗' THEN 1 ELSE 0 END) as sogou,
                    SUM(CASE WHEN spider_name = '360' THEN 1 ELSE 0 END) as s360,
                    SUM(CASE WHEN spider_name = '神马' THEN 1 ELSE 0 END) as yisou,
                    SUM(CASE WHEN spider_name = '今日头条' THEN 1 ELSE 0 END) as byte
                FROM spider_visits WHERE domain = ?";
                $stmt = $pdo->prepare($statsSql);
                $stmt->execute([$domain]);
                $dayStats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                foreach ($stats as $key => $val) {
                    $stats[$key] += (int)($dayStats[$key] ?? 0);
                }
            }
            
            $current = strtotime('+1 day', $current);
        }
        
        // 获取分页数据（从最新日期开始）
        $offset = ($page - 1) * $pageSize;
        $needed = $pageSize;
        $skipped = 0;
        
        $current = strtotime($end_date);
        $start = strtotime($start_date);
        
        while ($current >= $start && count($allVisits) < $needed) {
            $date = date('Y-m-d', $current);
            $pdo = $db->getDBByDate($date);
            
            if ($pdo) {
                // 获取当天符合条件的记录数
                $countSql = "SELECT COUNT(*) as cnt FROM spider_visits WHERE domain = ?" . $spiderCondition;
                $stmt = $pdo->prepare($countSql);
                $stmt->execute([$domain]);
                $dayCount = (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
                
                if ($skipped + $dayCount <= $offset) {
                    // 这一天的数据全部跳过
                    $skipped += $dayCount;
                } else {
                    // 需要从这一天取数据
                    $dayOffset = max(0, $offset - $skipped);
                    $dayLimit = min($needed - count($allVisits), $dayCount - $dayOffset);
                    
                    $dataSql = "SELECT visit_time as time, ip, spider_name as spider, url 
                                FROM spider_visits 
                                WHERE domain = ?" . $spiderCondition . "
                                ORDER BY visit_time DESC 
                                LIMIT $dayLimit OFFSET $dayOffset";
                    $stmt = $pdo->prepare($dataSql);
                    $stmt->execute([$domain]);
                    $dayVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $allVisits = array_merge($allVisits, $dayVisits);
                    $skipped += $dayOffset;
                }
            }
            
            $current = strtotime('-1 day', $current);
        }
        
        echo json_encode([
            'success' => true,
            'data' => $allVisits,
            'total' => $totalCount,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => ceil($totalCount / $pageSize),
            'stats' => $stats
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// AJAX请求：获取图表数据
if (isset($_GET['ajax']) && $_GET['ajax'] == 'charts') {
    header('Content-Type: application/json; charset=utf-8');
    
    $dateRangeParam = isset($_GET['date_range']) ? $_GET['date_range'] : (isset($_GET['current_date']) ? $_GET['current_date'] : date('Ymd'));
    
    try {
        $db = getSpiderDB();
        list($start_date, $end_date) = parseDateRange($dateRangeParam);
        
        // 初始化统计
        $stats = [
            'total' => 0, 'baidu' => 0, 'baidu_pc' => 0, 'baidu_mobile' => 0,
            'google' => 0, 'sogou' => 0, 's360' => 0, 'yisou' => 0, 'byte' => 0
        ];
        $hourlyData = array_fill(0, 24, 0);
        $baiduPcHourly = array_fill(0, 24, 0);
        $baiduMobileHourly = array_fill(0, 24, 0);
        
        // 遍历日期范围
        $current = strtotime($start_date);
        $end = strtotime($end_date);
        $hasData = false;
        
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $pdo = $db->getDBByDate($date);
            
            if ($pdo) {
                $hasData = true;
                
                // 获取蜘蛛统计
                $statsSql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN spider_name = '百度' OR spider_name = '百度PC' OR spider_name = '百度移动' THEN 1 ELSE 0 END) as baidu,
                    SUM(CASE WHEN spider_name = '百度PC' THEN 1 ELSE 0 END) as baidu_pc,
                    SUM(CASE WHEN spider_name = '百度移动' THEN 1 ELSE 0 END) as baidu_mobile,
                    SUM(CASE WHEN spider_name = '谷歌' THEN 1 ELSE 0 END) as google,
                    SUM(CASE WHEN spider_name = '搜狗' THEN 1 ELSE 0 END) as sogou,
                    SUM(CASE WHEN spider_name = '360' THEN 1 ELSE 0 END) as s360,
                    SUM(CASE WHEN spider_name = '神马' THEN 1 ELSE 0 END) as yisou,
                    SUM(CASE WHEN spider_name = '今日头条' THEN 1 ELSE 0 END) as byte
                FROM spider_visits WHERE domain = ?";
                $stmt = $pdo->prepare($statsSql);
                $stmt->execute([$domain]);
                $dayStats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                foreach ($stats as $key => $val) {
                    $stats[$key] += (int)($dayStats[$key] ?? 0);
                }
                
                // 获取小时分布
                $hourlySql = "SELECT visit_hour as hour, COUNT(*) as count 
                              FROM spider_visits 
                              WHERE domain = ? 
                              GROUP BY visit_hour";
                $stmt = $pdo->prepare($hourlySql);
                $stmt->execute([$domain]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $hourlyData[$row['hour']] += (int)$row['count'];
                }
                
                // 获取百度PC和百度移动的小时分布
                $baiduHourlySql = "SELECT visit_hour as hour, spider_name, COUNT(*) as count 
                                  FROM spider_visits 
                                  WHERE domain = ? AND spider_name IN ('百度PC', '百度移动')
                                  GROUP BY visit_hour, spider_name";
                $stmt = $pdo->prepare($baiduHourlySql);
                $stmt->execute([$domain]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($row['spider_name'] === '百度PC') {
                        $baiduPcHourly[$row['hour']] += (int)$row['count'];
                    } else if ($row['spider_name'] === '百度移动') {
                        $baiduMobileHourly[$row['hour']] += (int)$row['count'];
                    }
                }
            }
            
            $current = strtotime('+1 day', $current);
        }
        
        if (!$hasData) {
            echo json_encode(['success' => false, 'message' => '无数据']);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'stats' => $stats,
            'hourly' => $hourlyData,
            'baidu_pc_hourly' => $baiduPcHourly,
            'baidu_mobile_hourly' => $baiduMobileHourly,
            'date' => $start_date,
            'end_date' => $end_date
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 初始页面加载 - 获取基本数据
$domain_detail = getDomainDetailByRange($domain, $date_range);
$display_label = getDateRangeLabel($date_range);

// 解析当前日期
list($start_date, $end_date) = parseDateRange($date_range);

// 判断是否为日期范围模式（week/month或自定义范围YYYYMMDD-YYYYMMDD）
$isRangeMode = ($date_range === 'week' || $date_range === 'month' || (strlen($date_range) == 17 && $date_range[8] == '-'));

// 如果是范围模式，使用结束日期（今天）作为当前日期；否则使用开始日期
$currentDateYmd = $isRangeMode ? date('Ymd', strtotime($end_date)) : date('Ymd', strtotime($start_date));

// 保存原始的date_range用于AJAX请求
$originalDateRange = $date_range;

// 初始统计数据
$zongshu = $domain_detail['total'];
$baiduspidersa = isset($domain_detail['spiders']['百度']) ? $domain_detail['spiders']['百度'] : 0;
$Googlebotsa = isset($domain_detail['spiders']['谷歌']) ? $domain_detail['spiders']['谷歌'] : 0;
$Sogouspidersa = isset($domain_detail['spiders']['搜狗']) ? $domain_detail['spiders']['搜狗'] : 0;
$liuSpidersa = isset($domain_detail['spiders']['360']) ? $domain_detail['spiders']['360'] : 0;
$Yisouspidersa = isset($domain_detail['spiders']['神马']) ? $domain_detail['spiders']['神马'] : 0;
$Bytespidersa = isset($domain_detail['spiders']['今日头条']) ? $domain_detail['spiders']['今日头条'] : 0;
?>
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>域名统计 - <?php echo htmlspecialchars($domain); ?></title>
<link href="static/js/skin/WdatePicker.css" rel="stylesheet" type="text/css" />
<link href="static/css/admin.css" rel="stylesheet" type="text/css" />
<script type="text/javascript" charset="utf-8" src="static/js/jquery.js"></script>
<script type="text/javascript" src="static/js/highcharts.js"></script>
<script type="text/javascript" src="static/js/lingduseo.js"></script>
<script type="text/javascript" src="static/js/DatePicker/WdatePicker.js"></script>
<style>
.pagination-container {
    text-align: center;
    margin: 15px 0;
    font-size: 12px;
    line-height: 24px;
}
.pagination-info {
    display: inline-block;
    margin-right: 15px;
    color: #666;
    font-size: 12px;
    vertical-align: middle;
}
.pagination-buttons {
    display: inline-block;
    vertical-align: middle;
}
.pagination-buttons a, .pagination-buttons .current-page {
    display: inline-block;
    padding: 2px 8px;
    margin: 0 1px;
    text-decoration: none;
    border: 1px solid #ccc;
    color: #333;
    background: #f8f8f8;
    font-size: 12px;
    line-height: 18px;
    min-width: 16px;
    text-align: center;
    vertical-align: middle;
}
.pagination-buttons a:hover {
    background: #e8e8e8;
    border-color: #999;
}
.pagination-buttons .current-page {
    background: #4CAF50;
    color: white;
    border-color: #4CAF50;
    font-weight: bold;
}
.export-option:hover { background: #f5f5f5 !important; }
.domain-date-range-btn:hover { border-color: #4CAF50 !important; color: #4CAF50 !important; }
.domain-date-range-btn.active { background: #4CAF50 !important; color: #fff !important; border-color: #4CAF50 !important; }
</style>
</head>
<body class="body-main">

<ul id="admin_sub_title">
    <li class="sub"><a href="tongji.php">返回总览</a></li>
    <li class="sub"><a href="javascript:">域名详情：<?php echo htmlspecialchars($domain); ?></a></li>
    <li class="tips"><a href="javascript:void(0)" style="color:blue">时间：<span id="currentDateDisplay"><?php echo date('Y-m-d', strtotime($start_date)); ?></span></a></li>
    <li class="tips">
        <a href="javascript:void(0)" onclick="changeDay(-1)" style="color:green;">◀上一天</a> | 
        <a href="javascript:void(0)" onclick="changeDay(1)" style="color:green;">下一天▶</a>
    </li>
    <li class="tips"><a href="javascript:void(0)" style="color:green">百度PC:<span id="baiduPcCount">0</span> | 移动:<span id="baiduMobileCount">0</span></a></li>
    <li class="tips" style="position: relative;">
        <div class="export-dropdown" style="display: inline-block;">
            <a href="javascript:void(0)" onclick="toggleDomainExportDropdown()" style="color:orange; cursor: pointer;">📥 导出数据 ▼</a>
            <div id="domain_export_dropdown_menu" style="display: none; position: absolute; top: 100%; left: 0; background: #fff; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 150px; z-index: 1001; margin-top: 5px;">
                <div onclick="showDomainExportModal('all')" class="export-option" style="padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #eee; color: #333;">导出所有链接</div>
                <div onclick="showDomainExportModal('mobile')" class="export-option" style="padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #eee; color: #333;">导出移动链接</div>
                <div onclick="showDomainExportModal('pc')" class="export-option" style="padding: 10px 15px; cursor: pointer; color: #333;">导出PC链接</div>
            </div>
        </div>
    </li>
</ul>

<!-- 导出选项弹窗 -->
<div id="domain_export_modal" class="domain-modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
    <div class="domain-modal" style="background: #fff; border-radius: 8px; width: 500px; max-width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
        <div class="domain-modal-header" style="background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%); padding: 15px 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: #fff; font-size: 16px;">📥 导出设置</h3>
            <button onclick="closeDomainExportModal()" style="background: none; border: none; color: #fff; font-size: 24px; cursor: pointer; line-height: 1;">&times;</button>
        </div>
        <div class="domain-modal-body" style="padding: 25px;">
            <p id="domain_export_modal_info" style="margin-bottom: 20px; color: #666; font-size: 14px; text-align: center;"></p>
            
            <!-- 日期范围选择 -->
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 10px; font-weight: bold; color: #333;">📅 选择日期范围：</label>
                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                    <button type="button" onclick="selectDomainDateRange(1)" class="domain-date-range-btn active" data-days="1" style="padding: 8px 16px; border: 1px solid #4CAF50; background: #4CAF50; color: #fff; border-radius: 4px; cursor: pointer; font-size: 13px;">当天</button>
                    <button type="button" onclick="selectDomainDateRange(3)" class="domain-date-range-btn" data-days="3" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">3天</button>
                    <button type="button" onclick="selectDomainDateRange(7)" class="domain-date-range-btn" data-days="7" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">7天</button>
                    <button type="button" onclick="selectDomainDateRange(10)" class="domain-date-range-btn" data-days="10" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">10天</button>
                    <button type="button" onclick="selectDomainDateRange(20)" class="domain-date-range-btn" data-days="20" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">20天</button>
                    <button type="button" onclick="selectDomainDateRange(30)" class="domain-date-range-btn" data-days="30" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">30天</button>
                    <button type="button" onclick="selectDomainDateRange(0)" class="domain-date-range-btn" data-days="0" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">自定义</button>
                </div>
                <div id="domain_custom_date_range" style="display: none; margin-top: 12px; padding: 12px; background: #f9f9f9; border-radius: 4px;">
                    <span>从</span>
                    <input type="text" id="domain_export_start_date" onclick="WdatePicker({ dateFmt:'yyyyMMdd'})" class="input Wdate" style="width: 100px; margin: 0 5px;" value="<?php echo date('Ymd', strtotime('-7 day')); ?>">
                    <span>到</span>
                    <input type="text" id="domain_export_end_date" onclick="WdatePicker({ dateFmt:'yyyyMMdd'})" class="input Wdate" style="width: 100px; margin: 0 5px;" value="<?php echo date('Ymd'); ?>">
                </div>
                <p id="domain_date_range_hint" style="margin-top: 8px; color: #888; font-size: 12px;">将导出 <?php echo date('Y-m-d'); ?> 的数据</p>
            </div>
            
            <!-- 格式选择 -->
            <div>
                <label style="display: block; margin-bottom: 10px; font-weight: bold; color: #333;">📁 选择导出格式：</label>
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button onclick="doDomainExport('txt')" style="background: #2196F3; color: #fff; border: none; padding: 15px 25px; border-radius: 8px; cursor: pointer; font-size: 14px; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.3s; min-width: 110px;">
                        <span style="font-size: 24px;">📄</span>
                        <span>TXT 格式</span>
                        <span style="font-size: 10px; opacity: 0.8;">仅URL列表</span>
                    </button>
                    <button onclick="doDomainExport('csv')" style="background: #4CAF50; color: #fff; border: none; padding: 15px 25px; border-radius: 8px; cursor: pointer; font-size: 14px; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.3s; min-width: 110px;">
                        <span style="font-size: 24px;">📊</span>
                        <span>CSV 格式</span>
                        <span style="font-size: 10px; opacity: 0.8;">含抓取统计</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="admin_right_b">

<div style="height: 300px;">
    <div style="position: relative;">
        <div id="line_tab" class="chart_tab" style="margin-left:31%;">
            <span class="cur" data="0">今日</span>
        </div>
        <div id="chart_line_day_box" class="chart_box"></div>
    </div>
    <div style="position: relative;text-align:right">
        <div id="pie_tab" class="chart_tab" style="text-align: right;width: 30%;left: -10px;">
            <span class="cur" data="0">今日</span>
        </div>
        <div id="chart_pie_day_box" class="chart_box"></div>
    </div>
</div>

<br>

<table border="0" align="center" cellpadding="3" cellspacing="0" class="table_b" style="margin-top:10px">
    <tbody>
        <tr class='tdbg item_title'>
            <td colspan='7'>
            <i class="typcn typcn-globe"></i> 域名蜘蛛访问明细 - <?php echo htmlspecialchars($domain); ?> (<span id="dateRangeLabel"><?php echo htmlspecialchars($display_label); ?></span>)
                <span style='margin-left: 20px;'>
                <span class='glist'><a href='javascript:void(0)' onclick='filterSpider("all")'><font color='red' id='spider_all'>全部(<span id="count_all"><?php echo $zongshu; ?></span>)</font></a></span>&nbsp;
                <span class='glist'><a href='javascript:void(0)' onclick='filterSpider("百度")'><font id='spider_baidu'>百度(<span id="count_baidu"><?php echo $baiduspidersa; ?></span>)</font></a></span>&nbsp;
                <span class='glist'><a href='javascript:void(0)' onclick='filterSpider("谷歌")'><font id='spider_google'>Google(<span id="count_google"><?php echo $Googlebotsa; ?></span>)</font></a></span>&nbsp;
                <span class='glist'><a href='javascript:void(0)' onclick='filterSpider("360")'><font id='spider_360'>360蜘蛛(<span id="count_360"><?php echo $liuSpidersa; ?></span>)</font></a></span>&nbsp;
                <span class='glist'><a href='javascript:void(0)' onclick='filterSpider("搜狗")'><font id='spider_sogou'>搜狗(<span id="count_sogou"><?php echo $Sogouspidersa; ?></span>)</font></a></span>&nbsp;
                <span class='glist'><a href='javascript:void(0)' onclick='filterSpider("神马")'><font id='spider_shenma'>神马(<span id="count_shenma"><?php echo $Yisouspidersa; ?></span>)</font></a></span>&nbsp;
                <span class='glist'><a href='javascript:void(0)' onclick='filterSpider("今日头条")'><font id='spider_toutiao'>今日头条(<span id="count_toutiao"><?php echo $Bytespidersa; ?></span>)</font></a></span>&nbsp;
                </span>
            </td>
        </tr>
    <tr>
      <td width='50' align='center' class='title_bg'>id</td>
      <td width='100' align='center' class='title_bg'>蜘蛛名称</td>
      <td width='110' align='center' class='title_bg'>IP地址</td>
       <td width='80' align='center' class='title_bg'>国家/城市</td>
          <td class='title_bg'>访问地址</td>
      <td width='60' align='center' class='title_bg'>模型</td>
      <td width='140' align='center' class='title_bg'>访问时间</td>
    </tr>
    </tbody>
    <tbody id='rlist'>
        <tr bgcolor='#ffffff'>
            <td colspan='7' height='25' align='center'>加载中...</td>
        </tr>
    </tbody>
    <tbody>
    <tr>
          <td colspan="7" class="tdbg content_page" align="center">
              <a>共 <font id="total">0</font> 条记录</a>
          </td>
    </tr>
    </tbody>
</table>

<!-- 分页容器 -->
<div id="pagination" style="margin: 20px 0; text-align: center;"></div>

<div class="runtime"></div>  

<script type="text/javascript">
// 全局变量
var currentDomain = '<?php echo addslashes($domain); ?>';
var currentDate = '<?php echo $currentDateYmd; ?>'; // YYYYMMDD格式
var originalDateRange = '<?php echo addslashes($originalDateRange); ?>'; // 原始日期范围参数
var isRangeMode = <?php echo $isRangeMode ? 'true' : 'false'; ?>; // 是否为日期范围模式
var currentFilter = 'all';
var currentPage = 1;
var pageSize = 50;
var isLoading = false;

// 页面加载完成后初始化
$(document).ready(function() {
    loadCharts();
    loadVisits();
});

// 显示/隐藏加载遮罩
function showLoading() {
    $('#loadingOverlay').css('display', 'flex');
}
function hideLoading() {
    $('#loadingOverlay').hide();
}

// 切换日期（上一天/下一天）
function changeDay(offset) {
    // 切换日期时，退出范围模式，进入单日模式
    isRangeMode = false;
    
    var dateStr = currentDate;
    var year = parseInt(dateStr.substring(0, 4));
    var month = parseInt(dateStr.substring(4, 6)) - 1;
    var day = parseInt(dateStr.substring(6, 8));
    
    var date = new Date(year, month, day);
    date.setDate(date.getDate() + offset);
    
    // 不能超过今天
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    if (date > today) {
        alert('不能查看未来日期的数据');
        return;
    }
    
    // 格式化新日期
    var newYear = date.getFullYear();
    var newMonth = ('0' + (date.getMonth() + 1)).slice(-2);
    var newDay = ('0' + date.getDate()).slice(-2);
    
    currentDate = newYear + newMonth + newDay;
    currentPage = 1;
    
    updateDateDisplay();
    loadCharts();
    loadVisits();
}


// 更新日期显示
function updateDateDisplay() {
    var displayDate = currentDate.substring(0, 4) + '-' + currentDate.substring(4, 6) + '-' + currentDate.substring(6, 8);
    $('#currentDateDisplay').text(displayDate);
    $('#chartDateLabel').text(displayDate);
    $('#pieChartLabel').text(displayDate);
    $('#dateRangeLabel').text('单日 (' + displayDate + ')');
}

// 获取当前日期范围参数
function getDateRangeParam() {
    // 如果是范围模式且未手动切换日期，使用原始日期范围
    if (isRangeMode) {
        return originalDateRange;
    }
    return currentDate;
}

// 加载图表数据
function loadCharts() {
    $.ajax({
        url: 'domain_detail.php',
        data: {
            domain: currentDomain,
            ajax: 'charts',
            date_range: getDateRangeParam()
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                renderPieChart(res.stats);
                renderLineChart(res.hourly, res.baidu_pc_hourly, res.baidu_mobile_hourly);
                
                // 更新百度PC和移动数量
                $('#baiduPcCount').text(res.stats.baidu_pc || 0);
                $('#baiduMobileCount').text(res.stats.baidu_mobile || 0);
            }
        }
    });
}

// 渲染饼图（沿用原样式）
function renderPieChart(stats) {
    var data = [
        { name: '百度 ' + stats.baidu, y: parseInt(stats.baidu) || 0, sliced: true, selected: true },
        ['Google ' + stats.google, parseInt(stats.google) || 0],
        ['360蜘蛛 ' + stats.s360, parseInt(stats.s360) || 0],
        ['搜狗 ' + stats.sogou, parseInt(stats.sogou) || 0],
        ['神马 ' + stats.yisou, parseInt(stats.yisou) || 0],
        ['今日头条 ' + stats.byte, parseInt(stats.byte) || 0],
        ['其他 0', 0]
    ];
    
    $('#chart_pie_day_box').html('<div id="chart_pie_day" style="width: 30%; height: 300px; margin: 10px 0;float:left;position: relative;"></div>');
    
    $('#chart_pie_day').highcharts({
        chart: { plotBackgroundColor: null, plotBorderWidth: null, plotShadow: false },
        credits: { enabled: false },
        title: { text: '访问比率', align: 'left' },
        legend: { layout: 'vertical', backgroundColor: '#FFFFFF', verticalAlign: 'middle', align: 'right' },
        tooltip: { pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b>' },
        plotOptions: {
            pie: { allowPointSelect: true, cursor: 'pointer', dataLabels: { enabled: false }, showInLegend: true }
        },
        series: [{ type: 'pie', name: '比例', data: data }]
    });
}

// 渲染折线图（沿用原样式）
function renderLineChart(hourly, baiduPcHourly, baiduMobileHourly) {
    var total = 0;
    for (var i = 0; i < hourly.length; i++) {
        total += hourly[i];
    }
    
    $('#chart_line_day_box').html('<div id="chart_line_day" style="width:69%;height: 300px;margin: 10px 0;float:right;position: relative;"></div>');
    
    $('#chart_line_day').highcharts({
        chart: { type: 'line' },
        credits: { enabled: false },
        title: { text: '蜘蛛时段走势图' },
        subtitle: { text: '蜘蛛访问数量：' + total },
        xAxis: { categories: ['00','01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23'] },
        yAxis: { title: { text: '' } },
        plotOptions: { line: { dataLabels: { enabled: true }, enableMouseTracking: false } },
        series: [{ name: '蜘蛛访问次数（次/小时）', data: hourly }]
    });
}

// 加载访问记录（AJAX分页）
function loadVisits() {
    if (isLoading) return;
    isLoading = true;
    
    $('#rlist').html("<tr bgcolor='#ffffff'><td colspan='7' height='25' align='center'>加载中...</td></tr>");
    
    $.ajax({
        url: 'domain_detail.php',
        data: {
            domain: currentDomain,
            ajax: 'visits',
            date_range: getDateRangeParam(),
            page: currentPage,
            pageSize: pageSize,
            spider: currentFilter
        },
        dataType: 'json',
        success: function(res) {
            isLoading = false;
            
            if (res.success) {
                renderVisitsTable(res.data, res.page, res.pageSize);
                renderPagination(res.total, res.totalPages);
                updateSpiderCounts(res.stats);
                $('#total').text(res.total);
            } else {
                $('#rlist').html("<tr bgcolor='#ffffff'><td colspan='7' height='25' align='center'>" + (res.message || '加载失败') + "</td></tr>");
            }
        },
        error: function() {
            isLoading = false;
            $('#rlist').html("<tr bgcolor='#ffffff'><td colspan='7' height='25' align='center'>加载失败，请重试</td></tr>");
        }
    });
}

// 渲染访问记录表格
function renderVisitsTable(data, page, pageSize) {
    var html = '';
    
    if (!data || data.length === 0) {
        html = "<tr bgcolor='#ffffff'><td colspan='7' height='25' align='center'>暂无该蜘蛛的访问记录！</td></tr>";
    } else {
        var startNum = (page - 1) * pageSize + 1;
        for (var i = 0; i < data.length; i++) {
            var v = data[i];
            var num = startNum + i;
            var ip = v.ip ? v.ip.split('--')[0] : '';
        
        html += "<tr class='tdbg'>";
            html += "<td align='center'>" + num + "</td>";
            html += "<td align='center'>" + (v.spider || '') + "</td>";
            html += "<td align='center'><a title='点击查询IP归属' href='https://www.ip138.com/iplookup.asp?ip=" + ip + "&action=2' target='_blank'>" + ip + "</a></td>";
        html += "<td align='center'><font color='green'>中国</font></td>";
            html += "<td><a target='_blank' title='打开此链接' href='" + (v.url || '') + "'>" + (v.url || '') + "</a></td>";
        html += "<td align='center'>文章新闻</td>";
            html += "<td align='center'><font color='red'>" + (v.time || '') + "</font></td>";
        html += "</tr>";
        }
    }
    
    $('#rlist').html(html);
}

// 渲染分页
function renderPagination(total, totalPages) {
    if (totalPages <= 1) {
        $('#pagination').html('');
        return;
    }
    
    var html = '<div class="pagination-container"><div class="pagination-buttons">';
    
    // 首页、上一页
    if (currentPage > 1) {
        html += '<a href="javascript:void(0)" onclick="goToPage(1)">首页</a>';
        html += '<a href="javascript:void(0)" onclick="goToPage(' + (currentPage - 1) + ')">上一页</a>';
    }
    
    // 页码
    var startPage, endPage;
    if (totalPages <= 9) {
        startPage = 1;
        endPage = totalPages;
    } else {
        startPage = Math.max(1, currentPage - 4);
        endPage = Math.min(totalPages, currentPage + 4);
        if (endPage - startPage < 8) {
            if (startPage === 1) endPage = Math.min(totalPages, 9);
            else startPage = Math.max(1, totalPages - 8);
        }
    }
    
    for (var i = startPage; i <= endPage; i++) {
        if (i === currentPage) {
            html += '<span class="current-page">' + i + '</span>';
        } else {
            html += '<a href="javascript:void(0)" onclick="goToPage(' + i + ')">' + i + '</a>';
        }
    }
    
    // 下一页、尾页
    if (currentPage < totalPages) {
        html += '<a href="javascript:void(0)" onclick="goToPage(' + (currentPage + 1) + ')">下一页</a>';
        html += '<a href="javascript:void(0)" onclick="goToPage(' + totalPages + ')">尾页</a>';
    }
    
    html += '</div></div>';
    $('#pagination').html(html);
}

// 跳转到指定页
function goToPage(page) {
    currentPage = page;
    loadVisits();
    
    // 滚动到表格位置
    $('html, body').animate({
        scrollTop: $('#rlist').offset().top - 100
    }, 300);
}

// 蜘蛛过滤
function filterSpider(spiderType) {
    currentFilter = spiderType;
    currentPage = 1;
    
    // 更新选中状态
    updateSpiderSelection(spiderType);
    
    // 重新加载数据
    loadVisits();
}

// 更新蜘蛛选择状态
function updateSpiderSelection(selected) {
    var ids = ['spider_all', 'spider_baidu', 'spider_google', 'spider_360', 'spider_sogou', 'spider_shenma', 'spider_toutiao'];
    ids.forEach(function(id) {
        $('#' + id).css('color', '#666');
    });
    
    var idMap = {
        'all': 'spider_all',
        '百度': 'spider_baidu',
        '谷歌': 'spider_google',
        '360': 'spider_360',
        '搜狗': 'spider_sogou',
        '神马': 'spider_shenma',
        '今日头条': 'spider_toutiao'
    };
    
    if (idMap[selected]) {
        $('#' + idMap[selected]).css('color', 'red');
    }
}

// 更新蜘蛛统计数量
function updateSpiderCounts(stats) {
    if (!stats) return;
    
    $('#count_all').text(stats.total || 0);
    $('#count_baidu').text(stats.baidu || 0);
    $('#count_google').text(stats.google || 0);
    $('#count_360').text(stats.s360 || 0);
    $('#count_sogou').text(stats.sogou || 0);
    $('#count_shenma').text(stats.yisou || 0);
    $('#count_toutiao').text(stats.byte || 0);
    
    // 更新百度PC和移动
    $('#baiduPcCount').text(stats.baidu_pc || 0);
    $('#baiduMobileCount').text(stats.baidu_mobile || 0);
}

// ============== 导出功能 ==============
var domainExportType = '';
var domainExportDateDays = 1;

function toggleDomainExportDropdown() {
    var menu = document.getElementById('domain_export_dropdown_menu');
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

function showDomainExportModal(type) {
    domainExportType = type;
    domainExportDateDays = 1;
    
    var typeNames = { 'all': '全部链接', 'mobile': '移动端链接', 'pc': 'PC端链接' };
    var typeName = typeNames[type] || '全部链接';
    
    document.getElementById('domain_export_modal_info').innerHTML = '域名：<b>' + currentDomain + '</b> | 类型：<b>' + typeName + '</b>';
    
    // 重置按钮状态
    var btns = document.querySelectorAll('.domain-date-range-btn');
    btns.forEach(function(btn) {
        btn.classList.remove('active');
        btn.style.background = '#fff';
        btn.style.color = '#333';
        btn.style.borderColor = '#ddd';
    });
    var activeBtn = document.querySelector('.domain-date-range-btn[data-days="1"]');
    if (activeBtn) {
        activeBtn.classList.add('active');
        activeBtn.style.background = '#4CAF50';
        activeBtn.style.color = '#fff';
        activeBtn.style.borderColor = '#4CAF50';
    }
    document.getElementById('domain_custom_date_range').style.display = 'none';
    updateDomainDateRangeHint();
    
    document.getElementById('domain_export_modal').style.display = 'flex';
    document.getElementById('domain_export_dropdown_menu').style.display = 'none';
}

function closeDomainExportModal() {
    document.getElementById('domain_export_modal').style.display = 'none';
}

function selectDomainDateRange(days) {
    domainExportDateDays = days;
    
    var btns = document.querySelectorAll('.domain-date-range-btn');
    btns.forEach(function(btn) {
        btn.classList.remove('active');
        btn.style.background = '#fff';
        btn.style.color = '#333';
        btn.style.borderColor = '#ddd';
    });
    var activeBtn = document.querySelector('.domain-date-range-btn[data-days="' + days + '"]');
    if (activeBtn) {
        activeBtn.classList.add('active');
        activeBtn.style.background = '#4CAF50';
        activeBtn.style.color = '#fff';
        activeBtn.style.borderColor = '#4CAF50';
    }
    
    document.getElementById('domain_custom_date_range').style.display = days === 0 ? 'block' : 'none';
    updateDomainDateRangeHint();
}

function updateDomainDateRangeHint() {
    var hint = '';
    var today = new Date();
    
    if (domainExportDateDays === 0) {
        var startDate = document.getElementById('domain_export_start_date').value || '';
        var endDate = document.getElementById('domain_export_end_date').value || '';
        hint = startDate && endDate ? '将导出 ' + formatDateDisplay(startDate) + ' 至 ' + formatDateDisplay(endDate) + ' 的数据' : '请选择开始和结束日期';
    } else if (domainExportDateDays === 1) {
        hint = '将导出当前选中日期 ' + currentDate.substring(0,4) + '-' + currentDate.substring(4,6) + '-' + currentDate.substring(6,8) + ' 的数据';
    } else {
        hint = '将导出最近 ' + domainExportDateDays + ' 天的数据';
    }
    
    document.getElementById('domain_date_range_hint').innerHTML = hint;
}

function formatDateDisplay(dateStr) {
    if (dateStr.length === 8) {
        return dateStr.substring(0,4) + '-' + dateStr.substring(4,6) + '-' + dateStr.substring(6,8);
    }
    return dateStr;
}

function doDomainExport(format) {
    var rangeParam = '';
    if (domainExportDateDays === 0) {
        var startDate = document.getElementById('domain_export_start_date').value;
        var endDate = document.getElementById('domain_export_end_date').value;
        if (!startDate || !endDate) {
            alert('请选择开始和结束日期');
            return;
        }
        rangeParam = startDate + '-' + endDate;
    } else if (domainExportDateDays === 1) {
        rangeParam = currentDate;
    } else {
        rangeParam = 'days_' + domainExportDateDays;
    }
    
    var exportUrl = 'export_domain_urls.php?domain=' + encodeURIComponent(currentDomain) + '&type=' + domainExportType + '&format=' + format + '&range=' + rangeParam;
    
    closeDomainExportModal();
    window.location.href = exportUrl;
}

// 点击其他区域关闭下拉菜单
document.addEventListener('click', function(event) {
    var dropdown = document.querySelector('.export-dropdown');
    var menu = document.getElementById('domain_export_dropdown_menu');
    if (dropdown && menu && !dropdown.contains(event.target)) {
        menu.style.display = 'none';
    }
});
</script>

</div>
</body>
</html>
