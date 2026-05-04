<?php
/**
 * 汇总统计 API
 * GET  ?[filter params]        → 返回缓存（若有）
 * POST ?[filter params]        → 强制重新计算，保存缓存，返回结果
 */
ob_start();
require_once dirname(dirname(__DIR__)) . '/core/db.php';
require_once dirname(dirname(__DIR__)) . '/core/functions.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION)) @session_start();

// ── 解析过滤参数（与 admin/index.php 完全一致）─────────────────
$filters = [
    'search'          => trim($_REQUEST['search'] ?? ''),
    'status'          => (array)($_REQUEST['status'] ?? []),
    'registrar_id'    => (array)($_REQUEST['registrar_id'] ?? []),
    'icp_type'        => (array)($_REQUEST['icp_type'] ?? []),
    'group_name'      => $_REQUEST['group_name'] ?? '',
    'dns'             => $_REQUEST['dns'] ?? '',
    'account_id'      => $_REQUEST['account_id'] ?? '',
    'expire_warn'     => $_REQUEST['expire_warn'] ?? '',
    'cf'              => (array)($_REQUEST['cf'] ?? []),
    'date_field'      => 'expire_date',
    'date_from'       => $_REQUEST['date_from'] ?? '',
    'date_to'         => $_REQUEST['date_to']   ?? '',
    'exclude_expired' => !empty($_REQUEST['excl_exp']) ? '1' : '',
    'excl_days'       => max(0, (int)($_REQUEST['excl_days'] ?? 0)),
];
foreach (['status','registrar_id','icp_type'] as $_fk) {
    if (isset($_REQUEST[$_fk]) && is_string($_REQUEST[$_fk]) && $_REQUEST[$_fk] !== '') {
        $filters[$_fk] = [$_REQUEST[$_fk]];
    }
}
$filters['status']       = array_values(array_filter($filters['status']));
$filters['registrar_id'] = array_values(array_filter($filters['registrar_id']));
$filters['icp_type']     = array_values(array_filter($filters['icp_type']));
$filters['cf']           = array_filter($filters['cf'], function($v){ return $v !== ''; });
$tagFilter = array_values(array_filter(array_map('intval', explode(',', $_REQUEST['tags'] ?? ''))));

// ── 域名列表搜索：dl=1(session) 或 sr=jobId(文件) ────────────
$dlActive = false;
$dlList   = [];

$_srJobId = trim($_REQUEST['sr'] ?? '');
if ($_srJobId) {
    $srFile = dirname(dirname(__DIR__)) . '/data/search_results/sr_' . preg_replace('/[^a-zA-Z0-9_.]/', '', $_srJobId) . '.json';
    if (file_exists($srFile)) {
        $srData = json_decode(file_get_contents($srFile), true);
        if (!empty($srData['domains'])) {
            $dlActive = true;
            $dlList   = $srData['domains'];
        }
    }
} elseif (!empty($_REQUEST['dl']) && !empty($_SESSION['domain_list_filter'])) {
    $dlActive = true;
    $dlList   = $_SESSION['domain_list_filter'];
}

// 使用 dl/sr 时不走缓存（内容依赖 session/文件，组合无限）
$bypassCache = $dlActive || !empty($filters['date_from']) || !empty($filters['date_to']);

// ── 缓存文件路径 ──────────────────────────────────────────────
$cacheDir  = dirname(dirname(__DIR__)) . '/data/agg';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
$cacheKey  = md5(serialize(['f' => $filters, 't' => $tagFilter]));
$cacheFile = $cacheDir . '/agg_' . $cacheKey . '.json';

// ── GET 请求：有 bypass 条件时实时计算；否则走缓存 ──────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!$bypassCache && file_exists($cacheFile)) {
        readfile($cacheFile);
    } else {
        if ($bypassCache) {
            try {
                $groups = getFilterAggregates($filters, $tagFilter, 20, $dlList);
                echo json_encode(['cached' => false, 'generated_at' => date('Y-m-d H:i:s'), 'groups' => $groups], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['cached' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['cached' => false]);
        }
    }
    exit;
}

// POST 请求：强制重新计算，全局视图下同时预热所有第一层筛选缓存 ──
set_time_limit(300); // 预热可能耗时，最多允许 5 分钟

try {
    // 判断是否为无筛选的全局视图
    $isGlobalView = empty($tagFilter) && !array_filter($filters, function($v) {
        return is_array($v) ? !empty($v) : ($v !== '' && $v !== null);
    });

    // 全局视图：先清空所有旧缓存，保证本次更新全部新鲜
    if ($isGlobalView) {
        $oldFiles = glob($cacheDir . '/agg_*.json') ?: [];
        foreach ($oldFiles as $_f) @unlink($_f);
    }

    // Step 1：计算当前视图（全局 or 带筛选）统计
    $groups  = getFilterAggregates($filters, $tagFilter, 20, $dlList);
    $payload = [
        'cached'       => true,
        'generated_at' => date('Y-m-d H:i:s'),
        'groups'       => $groups,
        'prewarmed'    => 0,
        'is_global'    => $isGlobalView,
    ];
    // dl=1 或 日期筛选 时不写缓存（内容依赖 session/时间，组合无限）
    if (!$bypassCache) {
        file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    // Step 2：预热所有第一层筛选缓存（仅在全局视图且无日期筛选时执行）
    $hasDateFilter = !empty($filters['date_from']) || !empty($filters['date_to']);
    if ($isGlobalView && !$hasDateFilter) {
        $prewarmed = 0;
        foreach ($groups as $group) {
            if (empty($group['rows'])) continue;
            foreach ($group['rows'] as $row) {
                $f2 = $filters;
                $t2 = [];

                if ($group['type'] === 'multi') {
                    $f2[$group['field']] = [$row['val']];
                } elseif ($group['type'] === 'plain') {
                    $f2[$group['field']] = $row['val'];
                } elseif ($group['type'] === 'tag') {
                    $t2 = [(int)$row['id']];
                } elseif ($group['type'] === 'cf') {
                    if (!isset($f2['cf'])) $f2['cf'] = [];
                    $f2['cf'][$group['cf_name']] = $row['val'];
                } else {
                    continue;
                }

                $k2  = md5(serialize(['f' => $f2, 't' => $t2]));
                $cf2 = $cacheDir . '/agg_' . $k2 . '.json';
                try {
                    $g2 = getFilterAggregates($f2, $t2);
                    $p2 = [
                        'cached'       => true,
                        'generated_at' => date('Y-m-d H:i:s'),
                        'groups'       => $g2,
                    ];
                    file_put_contents($cf2, json_encode($p2, JSON_UNESCAPED_UNICODE));
                    $prewarmed++;
                } catch (Exception $e) {
                    // 单项预热失败不影响整体
                }
            }
        }
        $payload['prewarmed'] = $prewarmed;
        // 更新主缓存文件（写入预热数量）
        file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['cached' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
