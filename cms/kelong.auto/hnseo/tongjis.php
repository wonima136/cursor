<?php
/**
 * 图表数据接口 - SQLite版本（按天分库）
 * 提供饼图(a)、小时分布图(b)、趋势折线图(c)的数据
 */
error_reporting(0);

require_once __DIR__ . '/spider_db.php';

/**
 * 饼图数据 - 蜘蛛占比
 * $days 可以是天数(0,1,7,30,365)或日期字符串(yyyyMMdd)
 */
function a($days, $group_id = null) {
    try {
        $db = getSpiderDB();
        
        // 判断是天数还是日期字符串
        $isDateStr = false;
        $targetDate = null;
        
        if (!is_numeric($days) || strlen($days) > 3) {
            // 日期字符串模式：yyyyMMdd
            $dateStr = str_replace('-', '', $days);
            if (strlen($dateStr) == 8) {
                $year = substr($dateStr, 0, 4);
                $month = substr($dateStr, 4, 2);
                $day = substr($dateStr, 6, 2);
                $targetDate = "{$year}-{$month}-{$day}";
                $isDateStr = true;
                $dateLabel = "{$month}月{$day}日";
            }
        }
        
        // 根据模式获取数据
        if ($isDateStr && $targetDate) {
            // 指定日期模式：获取单日数据
            $data = $db->getSpiderRatioByDate($targetDate, $group_id);
        } else {
            // 天数模式：0=今日，1=昨日，7=近7日，30=近30日
            $data = $db->getSpiderRatio($days, $group_id);
        }
        
        // 计算总数
        $total = 0;
        foreach ($data as $item) {
            $total += $item['value'];
        }
        
        // 构造图表数据
        $chart_data = array();
        foreach ($data as $item) {
            $chart_data[] = "['" . $item['name'] . " " . $item['value'] . "', " . $item['value'] . "]";
        }
        
        // 如果有数据，第一个设为选中状态
        if (!empty($chart_data)) {
            $first_item = $data[0];
            $chart_data[0] = "{name: '" . $first_item['name'] . " " . $first_item['value'] . "', y: " . $first_item['value'] . ", sliced: false, selected: false}";
        }
        
        // 如果没有数据
        if (empty($chart_data)) {
            $chart_data[] = "['暂无数据', 0]";
        }
        
        // 设置标题文本
        if ($isDateStr) {
            $text = $dateLabel . "访问比率";
        } else {
            $text = "今日访问比率";
            if ($days == 1) $text = '昨日访问比率';
            if ($days == 7) $text = '近七日内访问比率';
            if ($days == 30) $text = '近三十日内访问比率';
            if ($days == 365) $text = '近一年内访问比率';
        }
        
        $chart_data_str = implode(",\n", $chart_data);
        
        $html = "<div id=\"chart_pie_day\" style=\"width: 30%; height: 300px; margin: 10px 0;float:left;position: relative;\"></div>
	<script type=\"text/javascript\">
	\$('#chart_pie_day').highcharts({
		chart: {
			plotBackgroundColor: null,
			plotBorderWidth: null,
			plotShadow: false
		},
		credits:{
			enabled:false
		},
		title: {
            text: '{$text}',
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
            data: [{$chart_data_str}]
		}]
	});
	</script>";
        
        exit(json_encode(array('html' => $html, 'msg' => '1')));
        
    } catch (Exception $e) {
        exit(json_encode(array('html' => '<div>数据加载失败</div>', 'msg' => '0', 'error' => $e->getMessage())));
    }
}

/**
 * 小时分布图数据
 * $days 可以是天数(0,1,2)或日期字符串(yyyyMMdd或yyyy-MM-dd)
 */
function b($days, $group_id = null) {
    try {
        $db = getSpiderDB();
        
        // 判断是天数还是日期字符串
        if (is_numeric($days) && strlen($days) <= 3) {
            // 天数模式：0=今日，1=昨日，2=前日
            $date = date('Y-m-d', strtotime("-{$days} days"));
            $text = '今日';
            if ($days == 1) $text = '昨日';
            if ($days == 2) $text = '前日';
        } else {
            // 日期字符串模式：yyyyMMdd 或 yyyy-MM-dd
            $dateStr = str_replace('-', '', $days);
            if (strlen($dateStr) == 8) {
                $year = substr($dateStr, 0, 4);
                $month = substr($dateStr, 4, 2);
                $day = substr($dateStr, 6, 2);
                $date = "{$year}-{$month}-{$day}";
                $text = "{$month}月{$day}日";
            } else {
                $date = date('Y-m-d');
                $text = '今日';
            }
        }
        
        // 获取小时分布数据
        $hourly_data = $db->getHourlyStats($date, null, $group_id);
        
        // 计算总数
        $count = array_sum($hourly_data);
        
        // 构造数据字符串
        $categories_str = implode(',', $hourly_data);
        
        $html = "<div id='chart_line_day' style='width:69%;height: 300px;margin: 10px 0;float:right;position: relative;'></div>
	<script type='text/javascript'>
	\$('#chart_line_day').highcharts({
		chart: {
			type: 'line'
		},
		credits:{
			enabled:false
		},
		title: {
            text: '{$text}蜘蛛时段走势图'
		},
		subtitle: {
            text: '{$text}蜘蛛访问数量：{$count}'
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
                data: [{$categories_str}]
			}
		]
	});
	</script>";
        
        exit(json_encode(array('html' => $html, 'msg' => '1')));
        
    } catch (Exception $e) {
        exit(json_encode(array('html' => '<div>数据加载失败</div>', 'msg' => '0', 'error' => $e->getMessage())));
    }
}

/**
 * 趋势折线图数据
 */
function c($days, $spider_type = '', $group_id = null) {
    if (empty($days)) $days = 10;
    
    try {
        $db = getSpiderDB();
        
        // 蜘蛛类型映射
        $spider_map = array(
            'baidu' => 'Baiduspider',
            'google' => 'Googlebot',
            'sogou' => 'Sogou',
            '360' => '360Spider',
            'yisou' => 'Yisouspider',
            'byte' => 'Bytespider'
        );
        
        $spider_names = array(
            'baidu' => '百度',
            'google' => '谷歌',
            'sogou' => '搜狗',
            '360' => '360',
            'yisou' => '神马',
            'byte' => '今日头条',
            '' => '全部'
        );
        
        $spider_name = isset($spider_names[$spider_type]) ? $spider_names[$spider_type] : '全部';
        $db_spider_type = isset($spider_map[$spider_type]) ? $spider_map[$spider_type] : null;
        
        // 获取趋势数据
        $trend_data = $db->getTrendStats($days, $db_spider_type, $group_id);
        
        // 构造日期和数据数组
        $dates = array();
        $values = array();
        foreach ($trend_data as $date => $count) {
            $dates[] = str_replace('-', '', $date);
            $values[] = $count;
        }
        
        $total = array_sum($values);
        
        // 统计PC和移动端（百度）
        $pc_total = 0;
        $mobile_total = 0;
        
        if ($spider_type === 'baidu' || $spider_type === '') {
            $baidu_stats = $db->getBaiduPCMobileStats($days, $group_id);
            $pc_total = $baidu_stats['pc'];
            $mobile_total = $baidu_stats['mobile'];
        }
        
        $dates_str = implode(',', $dates);
        $values_str = implode(',', $values);
        
        $html = "<div id='chart_line_week' style='min-width: 310px; height: 300px; margin: 10px auto;position: relative;'></div>
		<script type='text/javascript'>
		\$('#chart_line_week').highcharts({
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
            text: '近{$days}日蜘蛛走势图 - {$spider_name}'
			},
	subtitle: {
            text: '近{$days}日蜘蛛数量：{$total} (PC：{$pc_total} | 移动：{$mobile_total})'
			  },
		xAxis: {
            categories: [{$dates_str}]
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
        series: [{ name: '{$spider_name}', data: [{$values_str}] }]
				});
				</script>";
        
        exit(json_encode(array('html' => $html, 'msg' => '1')));
        
    } catch (Exception $e) {
        exit(json_encode(array('html' => '<div>数据加载失败: ' . $e->getMessage() . '</div>', 'msg' => '0')));
    }
}

// 解析请求参数
// URL格式: tongjis.php?a-0&group_id=1 或 tongjis.php?c-10-baidu&group_id=1
$query_string = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';

// 分离 group_id 参数
$query_parts = explode('&', $query_string);
$main_query = $query_parts[0]; // 例如 a-0 或 c-10-baidu

$req = explode("-", $main_query);
$req[0] = strtolower(strip_tags(trim($req[0])));
$fn = in_array($req[0], array('a', 'b', 'c')) ? $req[0] : 'a';
$param = empty($req[1]) ? 0 : (int)$req[1];
$spider_type = isset($req[2]) ? trim($req[2]) : '';

// 从GET参数获取分组ID
$group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : null;

// 调用对应函数
if ($fn === 'c') {
    c($param, $spider_type, $group_id);
} elseif ($fn === 'b') {
    b($param, $group_id);
} else {
    a($param, $group_id);
}
