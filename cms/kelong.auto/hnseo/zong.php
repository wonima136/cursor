<?php	
/**
 * 首页数据加载文件 - SQLite版本（按天分库）
 * 为tongji.php提供初始化数据
 */
error_reporting(0);

require_once __DIR__ . '/spider_db.php';

$time = date('Ymd');

try {
    $db = getSpiderDB();
    $today = date('Y-m-d');
    
    // 获取今日各蜘蛛统计
    $stats = $db->getDayStats($today);
    
    // 初始化统计变量
    $baiduspidersa = 0;
    $Googlebotsa = 0;
    $Sogouspidersa = 0;
    $liuSpidersa = 0;
    $Yisouspidersa = 0;
    $Bytespidersa = 0;
    
    foreach ($stats as $stat) {
        switch ($stat['spider_type']) {
            case 'Baiduspider':
                $baiduspidersa = $stat['count'];
                break;
            case 'Googlebot':
                $Googlebotsa = $stat['count'];
                break;
            case 'Sogou':
                $Sogouspidersa = $stat['count'];
                break;
            case '360Spider':
                $liuSpidersa = $stat['count'];
                break;
            case 'Yisouspider':
                $Yisouspidersa = $stat['count'];
                break;
            case 'Bytespider':
                $Bytespidersa = $stat['count'];
                break;
							 }
    }
    
    // 获取今日小时分布
    $hourly = $db->getHourlyStats($today);
    $countb = array_sum($hourly);
    $categoriesb = implode(',', $hourly);
    
    // 获取近10日趋势（百度）
    $trend = $db->getTrendStats(10, 'Baiduspider');
    $dates = array();
    $values = array();
    foreach ($trend as $date => $count) {
        $dates[] = str_replace('-', '', $date);
        $values[] = $count;
    }
    $countc = array_sum($values);
    $categoriesc = implode(',', $dates);
    $seriesc = implode(',', $values);
	
	// 统计百度PC和移动端
    $baidu_stats = $db->getBaiduPCMobileStats(10);
    $baidu_pc_total = $baidu_stats['pc'];
    $baidu_mobile_total = $baidu_stats['mobile'];
	
    // 获取今日访问列表（前50条）
    $today_db = $db->getDBByDate($today);
    $list = array();
    $counts = 0;
    
    if ($today_db) {
        $stmt = $today_db->query("SELECT visit_time, ip, spider_name, url FROM spider_visits ORDER BY visit_time DESC LIMIT 51");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $idx = 0;
        foreach ($rows as $row) {
            $list[$idx] = array(
                $row['visit_time'],
                $row['ip'],
                $row['spider_name'],
                $row['url']
            );
            $idx++;
        }
        
        $stmt = $today_db->query("SELECT COUNT(*) FROM spider_visits");
        $counts = $stmt->fetchColumn();
    }
    
} catch (Exception $e) {
    // 如果数据库失败，设置默认值
    $baiduspidersa = 0;
    $Googlebotsa = 0;
    $Sogouspidersa = 0;
    $liuSpidersa = 0;
    $Yisouspidersa = 0;
    $Bytespidersa = 0;
    $countb = 0;
    $categoriesb = '0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0';
    $countc = 0;
    $categoriesc = '';
    $seriesc = '';
    $baidu_pc_total = 0;
    $baidu_mobile_total = 0;
    $list = array();
    $counts = 0;
}

// 生成图表HTML
$a = "<div id='chart_pie_day' style='width: 30%; height: 300px; margin: 10px 0;float:left;position: relative;'></div>
		<script type='text/javascript'>
		$('#chart_pie_day').highcharts({
			chart: {
				plotBackgroundColor: null,
				plotBorderWidth: null,
				plotShadow: false
			},
			credits:{
				enabled:false
			},
			title: {
				text: '今日访问比率',
				align:'left',
			},
			legend: {
				layout: 'vertical',
				backgroundColor: '#FFFFFF',
				verticalAlign: 'middle',
				align: 'right'
			},
			tooltip: {
				pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b>'	
			},
			plotOptions: {
				pie: {
					allowPointSelect: true,
					cursor: 'pointer',
					dataLabels: {
						enabled: false
					},
					showInLegend: true
				}
			},
			series: [{
				type: 'pie',
				name: '比例',
				data: [
				{
                name: '百度 {$baiduspidersa}',
                y: {$baiduspidersa},
					sliced: false,
					selected: false
            },
            ['Google {$Googlebotsa}', {$Googlebotsa}],
            ['360蜘蛛 {$liuSpidersa}', {$liuSpidersa}],
            ['搜狗 {$Sogouspidersa}', {$Sogouspidersa}],
            ['神马 {$Yisouspidersa}', {$Yisouspidersa}],
            ['今日头条 {$Bytespidersa}', {$Bytespidersa}],
            ['其他 0', 0]
        ]
			}]
		});
		</script>";

$b = "<div id='chart_line_day' style='width:69%;height: 300px;margin: 10px 0;float:right;position: relative;'></div>
		<script type='text/javascript'>
		$('#chart_line_day').highcharts({
			chart: {
				type: 'line'
			},
			credits:{
				enabled:false
			},
			title: {
				text: '今日蜘蛛时段走势图'
			},
			subtitle: {
        text: '今日蜘蛛访问数量：{$countb}'
			},
			xAxis: {
				categories: ['00','01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23']
			},
			yAxis: {
				title: {
					text: ''
				}
			},
			plotOptions: {
				line: {
					dataLabels: {
						enabled: true
					},
					enableMouseTracking: false
				}
			},
			series: [
				{
					name: '蜘蛛访问次数（次/小时）',
            data: [{$categoriesb}]
				}
			]
		});
		</script>";

$c = "<div id='chart_line_week' style='min-width: 310px; height: 300px; margin: 10px auto;position: relative;'></div>
		<script type='text/javascript'>
		$('#chart_line_week').highcharts({
			chart: {
				type: 'line',
				style: {
						'border-top':'1px solid #40AA52'
					}
			},
			credits:{
				enabled:false
			},
		title: {
			text: '近10日蜘蛛走势图 - 百度'
		},
		subtitle: {
        text: '近10日蜘蛛数量：{$countc} (PC：{$baidu_pc_total} | 移动：{$baidu_mobile_total})'
		},
			xAxis: {
        categories: [{$categoriesc}]
					},
			yAxis: {
				title: {
					text: ''
				}
			},
			legend: {
				layout: 'vertical',
				backgroundColor: '#FFFFFF',
				verticalAlign: 'middle',
				align: 'right'
			},
			plotOptions: {
				line: {
					dataLabels: {
						enabled: true
					},
					enableMouseTracking: false
				}
			},
    series: [{ name: '百度', data: [{$seriesc}] }]
		});
		</script>";	

// 分页
$paged = '';
if ($counts > 0) {
    $pages = ceil($counts / 10);
    $paged = "<a href=\"5000.php?zong--{$time}--p--1\">首页</a>";
    for ($i = 1; $i <= min($pages, 9); $i++) {
        if ($i == 1) {
            $paged .= "<a class=\"current\">{$i}</a>";
        } else {
            $paged .= "<a href=\"5000.php?zong--{$time}--p--{$i}\">{$i}</a>";
			} 
									} 
    if ($pages > 1) {
        $paged .= "<a class=\"next\" href=\"5000.php?zong--{$time}--p--2\">下一页</a>";
        $paged .= "<a href=\"5000.php?zong--{$time}--p--{$pages}\">末页</a>";
						} 
}
