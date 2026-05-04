<?php
/**
 * 龙虎榜 - 域名蜘蛛抓取排行榜
 * 简洁版：单独展示移动端或PC端排行
 */
ini_set('session.save_handler', 'files');
ini_set('session.save_path', '/tmp');
session_name('hnseo_tongji');
session_start();

// 登录验证
if (!isset($_SESSION['tongji_logged_in']) || $_SESSION['tongji_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/spider_db.php';

// 获取参数
$type = isset($_GET['type']) ? $_GET['type'] : 'mobile'; // mobile, pc, inner
$group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
$days = isset($_GET['days']) ? intval($_GET['days']) : 7;
if ($days < 1) $days = 1;
if ($days > 30) $days = 30;

// 类型配置
$type_config = array(
    'mobile' => array('spider' => '百度移动', 'title' => '移动龙虎榜', 'icon' => '📱', 'color' => '#e91e63', 'inner' => false),
    'pc' => array('spider' => '百度PC', 'title' => 'PC龙虎榜', 'icon' => '💻', 'color' => '#2196f3', 'inner' => false),
    'inner_mobile' => array('spider' => '百度移动', 'title' => '内页移动榜', 'icon' => '📱', 'color' => '#ff5722', 'inner' => true),
    'inner_pc' => array('spider' => '百度PC', 'title' => '内页PC榜', 'icon' => '💻', 'color' => '#ff9800', 'inner' => true)
);

$config = isset($type_config[$type]) ? $type_config[$type] : $type_config['mobile'];
$spider_name = $config['spider'];
$page_title = $config['title'];
$page_icon = $config['icon'];
$theme_color = $config['color'];
$is_inner_mode = $config['inner'];

// 加载分组信息
$groups = array();
$current_group = null;
$groups_file = __DIR__ . '/groups.json';
if (file_exists($groups_file)) {
    $groups_data = json_decode(file_get_contents($groups_file), true);
    if ($groups_data && isset($groups_data['groups'])) {
        $groups = $groups_data['groups'];
        foreach ($groups as $g) {
            if ($g['id'] == $group_id) {
                $current_group = $g;
                break;
            }
        }
    }
}

// 获取日期列表
$date_list = array();
for ($i = $days - 1; $i >= 0; $i--) {
    $date_list[] = date('Y-m-d', strtotime("-$i days"));
}

// 获取域名列表
$domains = array();
if ($current_group && !empty($current_group['domains'])) {
    $domains = $current_group['domains'];
}

// 判断URL是否为内页
function isInnerPage($url) {
    $parsed = parse_url($url);
    $path = isset($parsed['path']) ? $parsed['path'] : '/';
    // 首页判断：路径为空、/、/index.html、/index.php 等
    $path = rtrim($path, '/');
    if (empty($path)) return false;
    if (preg_match('/^\/?(index|default|home)\.(html?|php|asp|jsp)?$/i', $path)) return false;
    return true;
}

// 获取统计数据
function getLeaderboardData($domains, $date_list, $spider_name, $inner_only = false) {
    $db = getSpiderDB();
    $data = array();
    
    foreach ($date_list as $date) {
        $pdo = $db->getDBByDate($date);
        if (!$pdo) continue;
        
        // 构建查询
        $where = array();
        $params = array();
        
        if (!empty($domains)) {
            $placeholders = implode(',', array_fill(0, count($domains), '?'));
            $where[] = "domain IN ($placeholders)";
            $params = array_merge($params, $domains);
        }
        
        if ($spider_name !== null) {
            $where[] = "spider_name = ?";
            $params[] = $spider_name;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        if ($inner_only) {
            // 内页模式：需要逐条判断URL
            $sql = "SELECT domain, url FROM spider_visits $where_sql";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $domain = $row['domain'];
                $url = $row['url'];
                
                // 只统计内页
                if (!isInnerPage($url)) continue;
                
                if (!isset($data[$domain])) {
                    $data[$domain] = array(
                        'domain' => $domain,
                        'daily' => array_fill_keys($date_list, 0),
                        'total' => 0
                    );
                }
                $data[$domain]['daily'][$date]++;
                $data[$domain]['total']++;
            }
        } else {
            // 普通模式：直接COUNT
            $sql = "SELECT domain, COUNT(*) as count FROM spider_visits $where_sql GROUP BY domain";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $domain = $row['domain'];
                $count = (int)$row['count'];
                
                if (!isset($data[$domain])) {
                    $data[$domain] = array(
                        'domain' => $domain,
                        'daily' => array_fill_keys($date_list, 0),
                        'total' => 0
                    );
                }
                $data[$domain]['daily'][$date] = $count;
                $data[$domain]['total'] += $count;
            }
        }
    }
    
    // 按总数降序排序
    uasort($data, function($a, $b) {
        return $b['total'] - $a['total'];
    });
    
    return $data;
}

$leaderboard_data = getLeaderboardData($domains, $date_list, $spider_name, $is_inner_mode);

// 计算涨跌
function getChangeInfo($current, $previous) {
    $diff = $current - $previous;
    if ($diff > 0) return array('text' => '+' . $diff, 'class' => 'up');
    if ($diff < 0) return array('text' => $diff, 'class' => 'down');
    return array('text' => '-', 'class' => 'flat');
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $page_title; ?> - 蜘蛛统计</title>
<link href="static/css/admin.css" rel="stylesheet" type="text/css" />
<link href="static/js/skin/WdatePicker.css" rel="stylesheet" type="text/css" />
<script type="text/javascript" src="static/js/DatePicker/WdatePicker.js"></script>
<style>
* { box-sizing: border-box; }
body { 
    background: #f5f5f5;
    margin: 0;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
}

.toolbar {
    background: #fff;
    border-radius: 8px;
    padding: 15px 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.toolbar-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.toolbar-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.toolbar-title h1 {
    margin: 0;
    font-size: 20px;
    color: #333;
}

.toolbar-title .icon {
    font-size: 24px;
}

.type-tabs {
    display: flex;
    background: #f0f0f0;
    border-radius: 6px;
    padding: 3px;
}

.type-tabs a {
    padding: 6px 16px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    color: #666;
    transition: all 0.2s;
}

.type-tabs a.active {
    background: #fff;
    color: #333;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.type-tabs a.mobile.active { color: #e91e63; }
.type-tabs a.pc.active { color: #2196f3; }
.type-tabs a.inner_mobile.active { color: #ff5722; }
.type-tabs a.inner_pc.active { color: #ff9800; }

.toolbar-filters {
    display: flex;
    gap: 20px;
    align-items: center;
}

.filter-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: #666;
}

.filter-item select {
    padding: 6px 10px;
    border-radius: 4px;
    border: 1px solid #ddd;
    background: #fff;
    font-size: 13px;
    cursor: pointer;
}

.btn-back {
    color: #666;
    text-decoration: none;
    font-size: 13px;
    padding: 6px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fff;
}

.btn-back:hover {
    background: #f5f5f5;
}

.leaderboard {
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    position: relative;
    z-index: 1;
}

.leaderboard-header {
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.leaderboard-header h2 {
    margin: 0;
    font-size: 16px;
    color: #333;
}

.leaderboard-header .group-info {
    color: #999;
    font-size: 13px;
}

.table-wrapper {
    overflow-x: auto;
}

.leaderboard-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.leaderboard-table th {
    background: #fafafa;
    color: #666;
    padding: 12px 10px;
    text-align: center;
    font-weight: 600;
    border-bottom: 1px solid #eee;
    white-space: nowrap;
}

.leaderboard-table th.domain-col {
    text-align: left;
    padding-left: 20px;
    min-width: 180px;
}

.leaderboard-table th.rank-col {
    width: 50px;
}

.leaderboard-table th.date-col {
    min-width: 85px;
}

.leaderboard-table th.total-col {
    background: #f0f7ff;
    min-width: 80px;
}

.leaderboard-table td {
    padding: 12px 10px;
    text-align: center;
    border-bottom: 1px solid #f5f5f5;
    color: #333;
}

.leaderboard-table td.domain-col {
    text-align: left;
    padding-left: 20px;
}

.leaderboard-table td.domain-col a {
    color: #333;
    text-decoration: none;
}

.leaderboard-table td.domain-col a:hover {
    color: <?php echo $theme_color; ?>;
}

.leaderboard-table td.total-col {
    background: #f8fbff;
    font-weight: 600;
    color: <?php echo $theme_color; ?>;
}

.leaderboard-table tbody tr:hover {
    background: #fafafa;
}

.rank-badge {
    display: inline-block;
    width: 24px;
    height: 24px;
    line-height: 24px;
    border-radius: 50%;
    font-weight: bold;
    font-size: 12px;
}

.rank-1 { background: #FFD700; color: #333; }
.rank-2 { background: #C0C0C0; color: #333; }
.rank-3 { background: #CD7F32; color: #fff; }
.rank-normal { background: #f0f0f0; color: #666; }

.cell-value {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}

.cell-count {
    font-weight: 500;
}

.cell-change {
    font-size: 11px;
    padding: 1px 5px;
    border-radius: 3px;
}

.cell-change.up { color: #4CAF50; }
.cell-change.down { color: #f44336; }
.cell-change.flat { color: #999; }

.summary-row td {
    background: #f5f5f5 !important;
    font-weight: 600 !important;
    border-bottom: 2px solid #ddd;
    color: #333;
}

.summary-row td.summary-label {
    text-align: right;
    padding-right: 20px;
    color: #666;
}

.summary-row td.total-col {
    background: #e3f2fd !important;
    color: <?php echo $theme_color; ?>;
}

.no-data {
    text-align: center;
    padding: 60px 20px;
    color: #999;
    font-size: 15px;
}

.date-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}

.date-weekday {
    font-size: 11px;
    color: #999;
    font-weight: normal;
}

.footer-info {
    padding: 15px 20px;
    background: #fafafa;
    border-top: 1px solid #eee;
    font-size: 12px;
    color: #999;
    display: flex;
    justify-content: space-between;
}
</style>
</head>
<body>

<div class="container">
    <div class="toolbar">
        <div class="toolbar-row">
            <div class="toolbar-title">
                <span class="icon"><?php echo $page_icon; ?></span>
                <h1><?php echo $page_title; ?></h1>
                <div class="type-tabs">
                    <a href="?type=mobile&group_id=<?php echo $group_id; ?>&days=<?php echo $days; ?>" class="mobile <?php echo $type === 'mobile' ? 'active' : ''; ?>">📱 移动</a>
                    <a href="?type=pc&group_id=<?php echo $group_id; ?>&days=<?php echo $days; ?>" class="pc <?php echo $type === 'pc' ? 'active' : ''; ?>">💻 PC</a>
                    <span style="color:#ccc; margin: 0 5px;">|</span>
                    <a href="?type=inner_mobile&group_id=<?php echo $group_id; ?>&days=<?php echo $days; ?>" class="inner_mobile <?php echo $type === 'inner_mobile' ? 'active' : ''; ?>">📱 内页移动</a>
                    <a href="?type=inner_pc&group_id=<?php echo $group_id; ?>&days=<?php echo $days; ?>" class="inner_pc <?php echo $type === 'inner_pc' ? 'active' : ''; ?>">💻 内页PC</a>
                </div>
            </div>
            <div class="toolbar-filters">
                <div class="filter-item">
                    <span>分组</span>
                    <select id="group_select" onchange="applyFilters()">
                        <option value="0" <?php echo $group_id == 0 ? 'selected' : ''; ?>>全部域名</option>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?php echo $g['id']; ?>" <?php echo $group_id == $g['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($g['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <span>天数</span>
                    <select id="days_select" onchange="applyFilters()">
                        <option value="3" <?php echo $days == 3 ? 'selected' : ''; ?>>3天</option>
                        <option value="5" <?php echo $days == 5 ? 'selected' : ''; ?>>5天</option>
                        <option value="7" <?php echo $days == 7 ? 'selected' : ''; ?>>7天</option>
                        <option value="10" <?php echo $days == 10 ? 'selected' : ''; ?>>10天</option>
                        <option value="14" <?php echo $days == 14 ? 'selected' : ''; ?>>14天</option>
                        <option value="30" <?php echo $days == 30 ? 'selected' : ''; ?>>30天</option>
                    </select>
                </div>
                <a href="tongji.php" class="btn-back">← 返回</a>
            </div>
        </div>
    </div>

    <div class="leaderboard">
        <div class="leaderboard-header">
            <h2><?php echo $is_inner_mode ? $spider_name . '内页抓取排行' : $spider_name . '蜘蛛抓取排行'; ?></h2>
            <span class="group-info">
                <?php echo $current_group ? htmlspecialchars($current_group['name']) . ' (' . count($current_group['domains']) . '个域名)' : '全部域名'; ?>
            </span>
        </div>
        
        <div class="table-wrapper">
            <?php if (empty($leaderboard_data)): ?>
            <div class="no-data">暂无<?php echo $spider_name; ?>蜘蛛抓取数据</div>
            <?php else: ?>
            <table class="leaderboard-table">
                <thead>
                    <tr>
                        <th class="rank-col">排名</th>
                        <th class="domain-col">域名</th>
                        <?php 
                        $weekdays = array('日', '一', '二', '三', '四', '五', '六');
                        foreach ($date_list as $date): 
                            $weekday = $weekdays[date('w', strtotime($date))];
                            $is_today = ($date === date('Y-m-d'));
                        ?>
                        <th class="date-col" <?php echo $is_today ? 'style="background:#fff8e1;"' : ''; ?>>
                            <div class="date-header">
                                <span><?php echo date('m/d', strtotime($date)); ?></span>
                                <span class="date-weekday">周<?php echo $weekday; ?></span>
                            </div>
                        </th>
                        <?php endforeach; ?>
                        <th class="total-col">合计</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // 先计算汇总数据
                    $date_totals = array_fill_keys($date_list, 0);
                    $grand_total = 0;
                    foreach ($leaderboard_data as $domain => $info) {
                        foreach ($date_list as $date) {
                            $date_totals[$date] += $info['daily'][$date];
                        }
                        $grand_total += $info['total'];
                    }
                    ?>
                    <!-- 汇总行（置顶） -->
                    <tr class="summary-row">
                        <td colspan="2" class="summary-label">📊 合计</td>
                        <?php foreach ($date_list as $date): 
                            $is_today = ($date === date('Y-m-d'));
                        ?>
                        <td <?php echo $is_today ? 'style="background:#fff8e1 !important;"' : ''; ?>><?php echo $date_totals[$date]; ?></td>
                        <?php endforeach; ?>
                        <td class="total-col"><?php echo $grand_total; ?></td>
                    </tr>
                    
                    <?php 
                    $rank = 0;
                    foreach ($leaderboard_data as $domain => $info): 
                        $rank++;
                        
                        // 排名样式
                        if ($rank <= 3) {
                            $rank_class = 'rank-' . $rank;
                        } else {
                            $rank_class = 'rank-normal';
                        }
                    ?>
                    <tr>
                        <td><span class="rank-badge <?php echo $rank_class; ?>"><?php echo $rank; ?></span></td>
                        <td class="domain-col">
                            <a href="domain_detail.php?domain=<?php echo urlencode($domain); ?>&date_range=today" target="_blank">
                                <?php echo htmlspecialchars($domain); ?>
                            </a>
                        </td>
                        <?php 
                        $prev_value = null;
                        foreach ($date_list as $idx => $date): 
                            $value = $info['daily'][$date];
                            $is_today = ($date === date('Y-m-d'));
                            
                            // 计算涨跌
                            $change = null;
                            if ($prev_value !== null) {
                                $change = getChangeInfo($value, $prev_value);
                            }
                            $prev_value = $value;
                        ?>
                        <td <?php echo $is_today ? 'style="background:#fffde7;"' : ''; ?>>
                            <div class="cell-value">
                                <span class="cell-count"><?php echo $value; ?></span>
                                <?php if ($change && $value > 0): ?>
                                <span class="cell-change <?php echo $change['class']; ?>"><?php echo $change['text']; ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endforeach; ?>
                        <td class="total-col"><?php echo $info['total']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        
        <div class="footer-info">
            <span>共 <?php echo count($leaderboard_data); ?> 个域名</span>
            <span>数据范围：<?php echo $date_list[0]; ?> 至 <?php echo end($date_list); ?></span>
        </div>
    </div>
</div>

<script>
function applyFilters() {
    var groupId = document.getElementById('group_select').value;
    var days = document.getElementById('days_select').value;
    var type = '<?php echo $type; ?>';
    window.location.href = 'leaderboard.php?type=' + type + '&group_id=' + groupId + '&days=' + days;
}
</script>

</body>
</html>
