<?php
/**
 * 后台任务处理器 — 只允许 CLI 调用
 * 用法: php worker/run.php <jobId>
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

set_time_limit(0);
ini_set('memory_limit', '512M');
ignore_user_abort(true);

// 尽量提高文件描述符上限（Linux 软限制）
if (function_exists('posix_setrlimit') && defined('POSIX_RLIMIT_NOFILE')) {
    @posix_setrlimit(POSIX_RLIMIT_NOFILE, ['soft' => 65535, 'hard' => 65535]);
}

$jobId = $argv[1] ?? '';
if (!$jobId) { fwrite(STDERR, "Usage: run.php <jobId>\n"); exit(1); }

require_once dirname(__DIR__) . '/core/functions.php';

$master = getMasterDB();
$job    = db_one($master, "SELECT * FROM jobs WHERE id=?", [$jobId]);
if (!$job)                        { fwrite(STDERR, "Job not found: $jobId\n"); exit(1); }
if ($job['status'] !== 'pending') { fwrite(STDERR, "Job not pending: {$job['status']}\n"); exit(1); }

// ── DNS-LA 错误信息 → 中文翻译 ──────────────────────────────────
function dnsla_errmsg(int $code, string $msg): string {
    // 先按 code 精确匹配
    $codeMap = [
        400 => '请求参数错误',
        401 => '认证失败，API 密钥无效',
        403 => '无权限执行此操作',
        404 => '资源不存在',
        408 => '请求超时',
        429 => '请求过于频繁，已被限速',
        500 => '服务器内部错误',
        601 => '域名格式错误',
        602 => '域名后缀不支持',
        603 => '域名在黑名单中',
        604 => '非顶级域名，不支持添加',
        605 => '该域名已存在于主账号中',
        606 => '该域名已存在于子账号中',
        607 => '账户或主账户中已存在该域名',
        608 => '域名数量已达上限（配额不足）',
        609 => '域名已被暂停',
        610 => '域名未找到',
        611 => '解析记录冲突',
        612 => '记录类型不支持',
        613 => '记录值格式错误',
        614 => '记录数量已达上限',
        615 => 'TTL 值不合法',
        616 => '线路不存在',
        617 => '解析记录不存在',
        618 => '域名未接入，无法操作',
    ];
    if (isset($codeMap[$code])) return $codeMap[$code];

    // 再按关键词模糊匹配
    $keyMap = [
        '上限'          => '数量已达上限（配额不足）',
        'already exist' => '域名已存在于账号中',
        '已存在'        => '域名已存在于账号中',
        '格式错误'      => '域名或参数格式错误',
        'not found'     => '资源不存在',
        '不存在'        => '资源不存在',
        '超时'          => '请求超时',
        'timeout'       => '请求超时',
        '频率'          => '请求过于频繁',
        'rate'          => '请求过于频繁',
        '权限'          => '无权限执行此操作',
        'permission'    => '无权限执行此操作',
        'invalid'       => '参数无效',
        '无效'          => '参数无效',
        '黑名单'        => '域名在黑名单中',
        '不支持'        => '该操作或域名后缀不支持',
        'required'      => '缺少必填参数',
        '找回 TXT'      => 'TXT 验证记录未找到（未添加或未传播）',
    ];
    $msgLower = mb_strtolower($msg);
    foreach ($keyMap as $kw => $zh) {
        if (mb_stripos($msg, $kw) !== false || mb_stripos($msgLower, mb_strtolower($kw)) !== false) {
            return $zh;
        }
    }

    // 处理 Go validator 格式："Key: '...' Error:Field validation for '...' failed on the '...' tag"
    if (preg_match('/Field validation for [\'"](\w+)[\'"] failed on the [\'"](\w+)[\'"] tag/i', $msg, $m)) {
        $fieldMap = [
            'Domain' => '域名', 'Type' => '类型', 'Host' => '主机记录', 'Data' => '记录值',
            'Ttl' => 'TTL', 'Id' => 'ID', 'DomainId' => '域名ID',
        ];
        $tagMap = [
            'required' => '必填', 'min' => '值太小', 'max' => '值太大',
            'email' => '邮箱格式错误', 'url' => 'URL格式错误',
        ];
        $field = $fieldMap[$m[1]] ?? $m[1];
        $tag   = $tagMap[$m[2]] ?? $m[2];
        return "参数校验失败：{$field} 字段 {$tag}";
    }

    // 最后：code + 原始消息
    if ($msg) return "错误({$code})：{$msg}";
    return "未知错误（错误码 {$code}）";
}

// ── 进度更新辅助 ────────────────────────────────────────────────
function _jProgress(PDO $db, string $id, int $done, int $total, string $msg = ''): void {
    db_exec($db,
        "UPDATE jobs SET progress=?,total=?,message=?,updated_at=datetime('now','localtime') WHERE id=?",
        [$done, $total, $msg, $id]
    );
}
function _jDone(PDO $db, string $id, array $result): void {
    db_exec($db,
        "UPDATE jobs SET status='done',result=?,progress=total,updated_at=datetime('now','localtime') WHERE id=?",
        [json_encode($result, JSON_UNESCAPED_UNICODE), $id]
    );
}
function _jFail(PDO $db, string $id, string $msg): void {
    db_exec($db,
        "UPDATE jobs SET status='failed',message=?,updated_at=datetime('now','localtime') WHERE id=?",
        [$msg, $id]
    );
}

// 标记运行中，并记录进程 PID
db_exec($master,
    "UPDATE jobs SET status='running',pid=?,updated_at=datetime('now','localtime') WHERE id=?",
    [getmypid(), $jobId]
);

$params = json_decode($job['params'], true);
$type   = $job['type'];

try {

    // ════════════════════════════════════════════════════════════
    // 批量导入
    // ════════════════════════════════════════════════════════════
    if ($type === 'import') {
        $rows        = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $registrarId = isset($params['registrar_id']) ? (int)$params['registrar_id'] ?: null : null;
        $accountId   = isset($params['account_id'])   ? (int)$params['account_id']   ?: null : null;
        $tagIds      = array_values(array_filter(array_map('intval', $params['tag_ids'] ?? [])));
        $update      = !empty($params['update_existing']);
        $total       = count($rows);
        $added = $updated = $skipped = 0;

        _jProgress($master, $jobId, 0, $total, '准备中…');

        foreach ($rows as $i => $row) {
            $r = _importRow($master, $row, $registrarId, $accountId, $tagIds, $update);
            if (!$r) continue;
            if ($r['status'] === 'added')       $added++;
            elseif ($r['status'] === 'updated') $updated++;
            else                                $skipped++;

            if (($i + 1) % 500 === 0 || $i === $total - 1) {
                _jProgress($master, $jobId, $i + 1, $total,
                    "已处理 " . ($i + 1) . " / $total 条");
            }
        }

        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, [
            'added'   => $added,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // 批量续费
    // ════════════════════════════════════════════════════════════
    elseif ($type === 'renew') {
        $rows  = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $total = count($rows);
        $ok = $notFound = 0;

        _jProgress($master, $jobId, 0, $total, '准备中…');

        foreach ($rows as $i => $row) {
            if (empty($row['domain']) || empty($row['expire'])) { $notFound++; continue; }
            $domain = trim($row['domain']);
            $expire = trim($row['expire']);
            $exist  = db_one($master, "SELECT id, expire_date FROM domains WHERE domain=?", [$domain]);
            if ($exist) {
                $old = $exist['expire_date'];
                updateDomain($exist['id'], ['expire_date' => $expire, 'updated_at' => date('Y-m-d H:i:s')]);
                addHistory($exist['id'], 'renewal', "批量续费：{$old} → {$expire}");
                $ok++;
            } else {
                $notFound++;
            }

            if (($i + 1) % 500 === 0 || $i === $total - 1) {
                _jProgress($master, $jobId, $i + 1, $total,
                    "已处理 " . ($i + 1) . " / $total 条");
            }
        }

        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, ['ok' => $ok, 'not_found' => $notFound]);
    }

    // ════════════════════════════════════════════════════════════
    // 续费N年（在原有过期时间基础上加 N 年）
    // ════════════════════════════════════════════════════════════
    elseif ($type === 'renew_add_years') {
        $rows  = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $years = max(1, min(10, (int)($params['years'] ?? 1)));
        $total = count($rows);
        $ok = $notFound = $noDate = 0;

        _jProgress($master, $jobId, 0, $total, '准备中…');

        foreach ($rows as $i => $row) {
            if (empty($row['domain'])) { $notFound++; continue; }
            $domain = trim($row['domain']);
            $exist  = db_one($master, "SELECT id, expire_date FROM domains WHERE domain=?", [$domain]);
            if (!$exist) { $notFound++; }
            elseif (empty($exist['expire_date'])) {
                // 现有过期时间为空，无法计算，跳过
                $noDate++;
            } else {
                $old    = $exist['expire_date'];
                $ts     = strtotime($old);
                if ($ts === false) { $noDate++; }
                else {
                    $newExp = date('Y-m-d', strtotime('+' . $years . ' year', $ts));
                    updateDomain($exist['id'], ['expire_date' => $newExp, 'updated_at' => date('Y-m-d H:i:s')]);
                    addHistory($exist['id'], 'renewal', "续费{$years}年：{$old} → {$newExp}");
                    $ok++;
                }
            }

            if (($i + 1) % 500 === 0 || $i === $total - 1) {
                _jProgress($master, $jobId, $i + 1, $total,
                    "已处理 " . ($i + 1) . " / $total 条");
            }
        }

        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, ['ok' => $ok, 'not_found' => $notFound, 'no_date' => $noDate]);
    }

    // ════════════════════════════════════════════════════════════
    // 批量状态变更 / 删除
    // ════════════════════════════════════════════════════════════
    elseif ($type === 'batch_action') {
        $rows   = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $action = $params['action'];
        $total  = count($rows);
        $done   = 0;

        _jProgress($master, $jobId, 0, $total, '准备中…');

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if ($action === 'delete') {
                deleteDomain($id);
            } else {
                $status  = $action === 'normal' ? 'normal' : 'paused';
                $current = db_one($master, "SELECT status FROM domains WHERE id=?", [$id]);
                if ($current) {
                    updateDomain($id, ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
                    addHistory($id, 'status_change',
                        '批量操作：' . (STATUS_LABELS[$current['status']]['label'] ?? $current['status'])
                        . ' → ' . (STATUS_LABELS[$status]['label'] ?? $status));
                }
            }
            $done++;
            if ($done % 500 === 0 || $done === $total) {
                _jProgress($master, $jobId, $done, $total, "已处理 {$done} / {$total}");
            }
        }

        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, ['processed' => $done]);
    }

    // ════════════════════════════════════════════════════════════
    // 清空全部域名（直接 SQL 删除，无文件操作）
    // ════════════════════════════════════════════════════════════
    elseif ($type === 'clear_all') {
        $total = (int)db_val($master, "SELECT COUNT(*) FROM domains");
        _jProgress($master, $jobId, 0, $total, '开始清空…');

        db_exec($master, "DELETE FROM domain_history");
        _jProgress($master, $jobId, (int)($total * 0.3), $total, '已清理历史记录…');

        db_exec($master, "DELETE FROM domain_tags");
        _jProgress($master, $jobId, (int)($total * 0.6), $total, '已清理标签关联…');

        db_exec($master, "DELETE FROM domains");
        _jProgress($master, $jobId, $total, $total, '已删除域名数据，整理中…');

        $master->exec("VACUUM");

        if (!empty($params['data_file']) && file_exists($params['data_file'])) {
            unlink($params['data_file']);
        }

        _jDone($master, $jobId, ['cleared' => $total]);
    }

    // ════════════════════════════════════════════════════════════
    // 批量修改字段
    // ════════════════════════════════════════════════════════════
    elseif ($type === 'batch_update') {
        $rows   = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $fields = $params['fields'] ?? [];
        $total  = count($rows);
        $done   = $skipped = 0;

        _jProgress($master, $jobId, 0, $total, '准备中…');

        // 预处理字段：提取基础字段更新和特殊操作
        $basicUpdate = [];
        if (isset($fields['status']))       $basicUpdate['status']       = $fields['status'];
        if (isset($fields['icp_type']))     $basicUpdate['icp_type']     = $fields['icp_type'];
        if (isset($fields['group_name']))   $basicUpdate['group_name']   = $fields['group_name'];
        if (isset($fields['dns_servers']))  $basicUpdate['dns_servers']  = $fields['dns_servers'];

        // 注册商：按名称查 ID，找不到自动创建（留空 = 清除）
        if (array_key_exists('registrar_name', $fields)) {
            $rName = trim($fields['registrar_name'] ?? '');
            if ($rName === '') {
                $basicUpdate['registrar_id'] = null;
            } else {
                $rRow = db_one($master, "SELECT id FROM registrars WHERE name=?", [$rName]);
                if ($rRow) {
                    $basicUpdate['registrar_id'] = (int)$rRow['id'];
                } else {
                    // 不存在则自动创建
                    $basicUpdate['registrar_id'] = (int)db_insert($master, 'registrars', ['name' => $rName]);
                }
            }
        }
        // 账号：按用户名查 ID（支持"注册商 / 账号"格式），找不到自动创建（留空 = 清除）
        if (array_key_exists('account_name', $fields)) {
            $aInput = trim($fields['account_name'] ?? '');
            if ($aInput === '') {
                $basicUpdate['account_id'] = null;
            } else {
                // 支持"注册商 / 账号"格式，取最后一段作为用户名
                $aParts = explode('/', $aInput);
                $aName  = trim(end($aParts));
                $aRow   = db_one($master, "SELECT id FROM accounts WHERE username=?", [$aName]);
                if ($aRow) {
                    $basicUpdate['account_id'] = (int)$aRow['id'];
                } else {
                    // 不存在则自动创建（关联已设置的注册商）
                    $newRegId = $basicUpdate['registrar_id'] ?? null;
                    $basicUpdate['account_id'] = (int)db_insert($master, 'accounts', [
                        'username'     => $aName,
                        'registrar_id' => $newRegId,
                    ]);
                }
            }
        }

        foreach ($rows as $row) {
            $id = (int)$row['id'];

            // 基础字段更新
            if ($basicUpdate) {
                $upd = $basicUpdate;
                $upd['updated_at'] = date('Y-m-d H:i:s');
                updateDomain($id, $upd);
            }

            // 标签操作
            if (isset($fields['tags'])) {
                $tagOp  = $fields['tags']['mode']  ?? 'add';
                $tagIds = array_values(array_filter(array_map('intval', $fields['tags']['ids'] ?? [])));

                if ($tagOp === 'clear') {
                    setDomainTags($id, []);
                } elseif ($tagOp === 'replace') {
                    setDomainTags($id, $tagIds);
                } elseif ($tagOp === 'remove') {
                    $current = array_column(
                        db_all($master, "SELECT tag_id FROM domain_tags WHERE domain_id=?", [$id]),
                        'tag_id'
                    );
                    setDomainTags($id, array_values(array_diff($current, $tagIds)));
                } else {
                    // add（默认）
                    $current = array_column(
                        db_all($master, "SELECT tag_id FROM domain_tags WHERE domain_id=?", [$id]),
                        'tag_id'
                    );
                    setDomainTags($id, array_values(array_unique(array_merge($current, $tagIds))));
                }
            }

            // 自定义字段更新
            if (!empty($fields['custom_data']) && is_array($fields['custom_data'])) {
                updateDomainCustomData($id, $fields['custom_data']);
            }

            // 添加备注
            if (!empty($fields['note']['content'])) {
                addHistory($id, $fields['note']['type'] ?? 'note', $fields['note']['content']);
            }

            $done++;
            if ($done % 500 === 0 || $done === $total) {
                _jProgress($master, $jobId, $done, $total, "已处理 {$done} / {$total}");
            }
        }

        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, [
            'processed'  => $done,
            'not_found'  => count($params['not_found'] ?? []),
        ]);
    }

    elseif ($type === 'domain_search') {
        // ── 大列表域名查找 ─────────────────────────────────────────
        $domainList = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $total = count($domainList);
        _jProgress($master, $jobId, 0, $total, '正在查询…');

        // 用临时表插入域名，规避 SQLite 999 参数上限
        $master->exec("CREATE TEMP TABLE IF NOT EXISTS _dl_search (domain TEXT PRIMARY KEY)");
        $master->exec("DELETE FROM _dl_search");
        foreach (array_chunk($domainList, 100) as $chunk) {
            $ph   = implode(',', array_fill(0, count($chunk), '(?)'));
            $stmt = $master->prepare("INSERT OR IGNORE INTO _dl_search(domain) VALUES $ph");
            $stmt->execute($chunk);
        }
        _jProgress($master, $jobId, 0, $total, '临时表已建，查询匹配中…');

        $rows    = db_all($master, "SELECT domain FROM domains WHERE domain IN (SELECT domain FROM _dl_search) ORDER BY domain");
        $matched = array_column($rows, 'domain');

        // 将结果写入文件
        $srDir = DATA_DIR . '/search_results';
        if (!is_dir($srDir)) mkdir($srDir, 0755, true);

        // 清理超过 24 小时的旧结果文件
        foreach (glob($srDir . '/sr_*.json') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) @unlink($f);
        }

        $srFile = $srDir . '/sr_' . $jobId . '.json';
        file_put_contents($srFile, json_encode([
            'domains'    => $matched,
            'created_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE));

        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, [
            'matched'      => count($matched),
            'not_found'    => $total - count($matched),
            'redirect_url' => '/domains/?sr=' . $jobId,
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // DNS-LA：查询解析记录（后台任务版）
    // ════════════════════════════════════════════════════════════
    elseif ($type === 'dns_la_query_records') {
        require_once dirname(__DIR__) . '/dns/dns_la/core/api.php';
        $domains = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $account = dnsla_getAccount((int)($params['account_id'] ?? 0));
        if (!$account) { _jFail($master, $jobId, 'DNS-LA 账号不存在'); return; }

        $recType = (int)($params['rec_type'] ?? 1);
        $token   = dnsla_buildToken($account['api_id'], $account['api_secret']);
        $base    = $account['api_base'] ?? '';
        $types   = dnsla_recordTypes();
        $total   = count($domains);
        $CONCUR  = 20;

        // Phase 1: 分批查域名 ID
        _jProgress($master, $jobId, 0, $total, '查询域名 ID…');
        $domainIds   = [];
        $notFound    = [];
        $queryFailed = [];
        $done        = 0;

        foreach (array_chunk($domains, $CONCUR) as $batch) {
            $batch = array_values($batch);
            $reqs  = array_map(
                fn($d) => ['method' => 'GET', 'path' => '/api/domain', 'query' => ['domain' => $d]],
                $batch
            );
            $resps = dnsla_requestMulti($reqs, $token, $base);
            foreach ($batch as $i => $domain) {
                $r = $resps[$i];
                if ($r['ok'] && !empty($r['data']['id'])) {
                    $domainIds[$domain] = $r['data']['id'];
                } elseif ((int)($r['code'] ?? 0) === 610 || (int)($r['code'] ?? 0) === 404) {
                    $notFound[] = $domain;
                } else {
                    $queryFailed[$domain] = $r['msg'] ?: ('API错误码 ' . ($r['code'] ?? 0));
                }
            }
            $done += count($batch);
            _jProgress($master, $jobId, $done, $total, "查询域名 ID {$done}/{$total}…");
        }

        // Phase 2: 分批查解析记录
        _jProgress($master, $jobId, 0, $total, '查询解析记录…');
        $foundDomains  = array_keys($domainIds);
        $domainRecords = [];
        $done2         = 0;

        foreach (array_chunk($foundDomains, $CONCUR) as $batch) {
            $batch = array_values($batch);
            $reqs  = array_map(function($domain) use ($domainIds, $recType) {
                $q = ['pageIndex' => 1, 'pageSize' => 200, 'domainId' => $domainIds[$domain]];
                if ($recType !== 0) $q['type'] = $recType;
                return ['method' => 'GET', 'path' => '/api/recordList', 'query' => $q];
            }, $batch);
            $resps = dnsla_requestMulti($reqs, $token, $base);
            foreach ($batch as $i => $domain) {
                $rr   = $resps[$i] ?? ['ok' => false];
                if (!$rr['ok']) { $domainRecords[$domain] = null; continue; }
                $recs = $rr['data']['results'] ?? [];
                if ($recType !== 0) {
                    $recs = array_values(array_filter($recs, fn($r) => (int)($r['type'] ?? 0) === $recType));
                }
                $rows = [];
                foreach ($recs as $rec) {
                    $rows[] = [
                        'host'     => $rec['host']     ?? '@',
                        'typeName' => $types[(int)($rec['type'] ?? 0)] ?? ('TYPE'.$rec['type']),
                        'data'     => $rec['data']     ?? '',
                        'ttl'      => $rec['ttl']      ?? '',
                        'disable'  => !empty($rec['disable']),
                        'lineName' => $rec['lineName'] ?? $rec['lineId'] ?? '默认',
                    ];
                }
                $domainRecords[$domain] = $rows;
            }
            $done2 += count($batch);
            _jProgress($master, $jobId, $done + $done2, $total * 2, "查询记录 {$done2}/".count($foundDomains)."…");
        }

        // 组装结果
        $results = [];
        $orderMap = array_flip($domains);
        foreach ($notFound as $domain) {
            $results[] = ['domain' => $domain, 'found' => false, 'error_type' => 'not_found', 'records' => []];
        }
        foreach ($queryFailed as $domain => $reason) {
            $results[] = ['domain' => $domain, 'found' => false, 'error_type' => 'api_error', 'error_msg' => $reason, 'records' => []];
        }
        foreach ($foundDomains as $domain) {
            $rows = $domainRecords[$domain] ?? null;
            $results[] = ['domain' => $domain, 'found' => true, 'records' => $rows ?? []];
        }
        usort($results, fn($a, $b) => ($orderMap[$a['domain']] ?? 999) - ($orderMap[$b['domain']] ?? 999));

        $totalRecs = array_sum(array_map(fn($r) => count($r['records']), $results));
        $stats = [
            'total_domains' => $total,
            'total_records' => $totalRecs,
            'not_found'     => count($notFound),
            'no_records'    => count(array_filter($results, fn($r) => $r['found'] && empty($r['records']))),
            'api_failed'    => count($queryFailed),
        ];

        // 保存结果到文件
        $resultFile = DATA_DIR . '/jobs/' . $jobId . '_qresult.json';
        file_put_contents($resultFile, json_encode(['results' => $results, 'stats' => $stats], JSON_UNESCAPED_UNICODE));

        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, [
            'total_domains' => $total,
            'total_records' => $totalRecs,
            'not_found'     => count($notFound),
            'api_failed'    => count($queryFailed),
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // DNS-LA：批量添加域名
    // ════════════════════════════════════════════════════════════
    elseif ($type === 'dns_la_add_domains') {
        require_once dirname(__DIR__) . '/dns/dns_la/core/api.php';
        $domains = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $account = dnsla_getAccount((int)($params['account_id'] ?? 0));
        if (!$account) { _jFail($master, $jobId, 'DNS-LA 账号不存在'); return; }

        $token      = dnsla_buildToken($account['api_id'], $account['api_secret']);
        $base       = $account['api_base'] ?? '';
        $total      = count($domains);
        $ok = $failed = $done = 0;
        $failDetails = [];
        $okDomains   = [];
        $CONCUR     = 20;

        _jProgress($master, $jobId, 0, $total, '准备中…');

        foreach (array_chunk($domains, $CONCUR) as $batch) {
            $batch   = array_values(array_map(fn($d) => rtrim(trim($d), '.'), $batch));
            $reqs    = array_map(fn($d) => [
                'method' => 'POST', 'path' => '/api/domain',
                'query'  => [], 'body' => ['domain' => $d, 'groupId' => ''],
            ], $batch);
            $results = dnsla_requestMulti($reqs, $token, $base);

            $stopNow = false;
            foreach ($results as $i => $r) {
                $done++;
                if ($r['ok']) {
                    $ok++;
                    $okDomains[] = $batch[$i];
                } else {
                    $failed++;
                    $reason = dnsla_errmsg((int)($r['code'] ?? 0), $r['msg'] ?? '');
                    $failDetails[] = [$batch[$i], $reason];
                    if ($r['code'] === 608 || strpos($r['msg'] ?? '', '上限') !== false) {
                        $stopNow = true;
                    }
                }
            }

            _jProgress($master, $jobId, $done, $total, "已处理 {$done} / {$total}，成功 {$ok}，失败 {$failed}");

            if ($stopNow) {
                if (file_exists($params['data_file'])) unlink($params['data_file']);
                _jDone($master, $jobId, ['ok' => $ok, 'failed' => $failed,
                    'fail_details' => $failDetails, 'ok_domains' => $okDomains, 'stopped_at' => $done]);
                return;
            }
        }

        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, ['ok' => $ok, 'failed' => $failed,
            'fail_details' => $failDetails, 'ok_domains' => $okDomains]);
    }

    // ════════════════════════════════════════════════════════════
    // DNS-LA：批量删除域名
    // ════════════════════════════════════════════════════════════
    elseif ($type === 'dns_la_del_domains') {
        require_once dirname(__DIR__) . '/dns/dns_la/core/api.php';
        $domains = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $account = dnsla_getAccount((int)($params['account_id'] ?? 0));
        if (!$account) { _jFail($master, $jobId, 'DNS-LA 账号不存在'); return; }

        $token  = dnsla_buildToken($account['api_id'], $account['api_secret']);
        $base   = $account['api_base'] ?? '';
        $total  = count($domains);
        $ok = $notFound = $failed = 0;

        $failDetails     = [];
        $notFoundDomains = [];
        $okDomains       = [];
        $CONCUR          = 20;
        $done            = 0;

        // 先全部清洗域名
        $domains = array_map(fn($d) => rtrim(strtolower(trim($d)), '.'), $domains);

        _jProgress($master, $jobId, 0, $total, '并发查询域名 ID…');

        // 分批并发查 domainId
        $idMap = [];
        foreach (array_chunk($domains, $CONCUR) as $batch) {
            $idMap += dnsla_findDomainIds($account, $batch);
        }

        _jProgress($master, $jobId, 0, $total, '开始并发删除…');

        // 分批并发删除
        foreach (array_chunk($domains, $CONCUR) as $batch) {
            $toDelete = [];
            foreach ($batch as $domain) {
                if (empty($idMap[$domain])) {
                    $notFound++;
                    $notFoundDomains[] = $domain;
                } else {
                    $toDelete[$domain] = $idMap[$domain];
                }
            }
            if ($toDelete) {
                $reqs = array_map(fn($id) => [
                    'method' => 'DELETE', 'path' => '/api/domain',
                    'query' => ['id' => $id], 'body' => [],
                ], array_values($toDelete));
                $domList = array_keys($toDelete);
                $results = dnsla_requestMulti($reqs, $token, $base);
                foreach ($results as $i => $r) {
                    if ($r['ok']) {
                        $ok++;
                        $okDomains[] = $domList[$i];
                    } else {
                        $failed++;
                        $failDetails[] = [$domList[$i], dnsla_errmsg((int)($r['code'] ?? 0), $r['msg'] ?? '')];
                    }
                }
            }
            $done += count($batch);
            _jProgress($master, $jobId, $done, $total, "已处理 {$done} / {$total}，删除 {$ok}，未找到 {$notFound}，失败 {$failed}");
        }

        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, ['ok' => $ok, 'not_found' => $notFound, 'failed' => $failed,
            'fail_details' => $failDetails, 'not_found_domains' => $notFoundDomains,
            'ok_domains' => $okDomains]);
    }

    // ════════════════════════════════════════════════════════════
    // DNS-LA：批量添加解析记录
    // ════════════════════════════════════════════════════════════
    elseif ($type === 'dns_la_add_records') {
        require_once dirname(__DIR__) . '/dns/dns_la/core/api.php';
        $domains = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $account = dnsla_getAccount((int)($params['account_id'] ?? 0));
        if (!$account) { _jFail($master, $jobId, 'DNS-LA 账号不存在'); return; }

        $token   = dnsla_buildToken($account['api_id'], $account['api_secret']);
        $base    = $account['api_base'] ?? '';
        $ips     = (array)($params['ips'] ?? []);
        $hosts   = (array)($params['hosts'] ?? ['@']);
        $ttl     = (int)($params['ttl'] ?? 600);
        $clearA  = !empty($params['clear_a']);
        $recType = (int)($params['rec_type'] ?? 1);
        $total   = count($domains);
        $ok = $notFound = $failed = $cleared = 0;
        $failDetails    = [];
        $notFoundDomains = [];

        $CONCUR = 20;
        $done   = 0;
        $domains = array_map(fn($d) => rtrim(strtolower(trim($d)), '.'), $domains);

        _jProgress($master, $jobId, 0, $total, '并发查询域名 ID…');
        $idMap = [];
        foreach (array_chunk($domains, $CONCUR) as $batch) {
            $idMap += dnsla_findDomainIds($account, $batch);
        }

        _jProgress($master, $jobId, 0, $total, '开始处理记录…');

        foreach ($domains as $i => $domain) {
            $dnsId = $idMap[$domain] ?? null;
            if (!$dnsId) {
                $notFound++;
                $notFoundDomains[] = $domain;
                $done++;
                continue;
            }

            // 随机取一个 IP（当前域名固定用这个 IP）
            $ip = $ips[array_rand($ips)];

            // 清空现有 A 记录
            if ($clearA) {
                $recs = dnsla_fetchAllRecords($account, $dnsId);
                if ($recs) {
                    foreach ($recs as $rec) {
                        if ((int)($rec['type'] ?? 0) === $recType) {
                            dnsla_request('DELETE', '/api/record', ['id' => $rec['id']], [], $token, $base);
                            $cleared++;
                        }
                    }
                }
            }

            // 添加每个主机头的记录
            foreach ($hosts as $host) {
                $r = dnsla_request('POST', '/api/record', [], [
                    'domainId' => $dnsId,
                    'type'     => $recType,
                    'host'     => $host ?: '@',
                    'data'     => $ip,
                    'ttl'      => $ttl,
                    'groupId'  => '',
                    'lineId'   => '',
                    'preference' => 10,
                    'weight'   => 1,
                    'dominant' => false,
                ], $token, $base);
                if ($r['ok']) {
                    $ok++;
                } else {
                    $failed++;
                    $key = "{$domain} [{$host}]";
                    $failDetails[] = [$key, dnsla_errmsg((int)($r['code'] ?? 0), $r['msg'] ?? '')];
                }
            }

            $done++;
            if ($done % 10 === 0 || $done === $total) {
                _jProgress($master, $jobId, $done, $total, "已处理 {$done} / {$total}，记录添加 {$ok}，失败 {$failed}");
            }
        }
        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, [
            'ok' => $ok, 'cleared' => $cleared, 'not_found' => $notFound, 'failed' => $failed,
            'fail_details' => $failDetails, 'not_found_domains' => $notFoundDomains,
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // DNS-LA：批量修改解析记录值
    // ════════════════════════════════════════════════════════════
    elseif ($type === 'dns_la_update_records') {
        require_once dirname(__DIR__) . '/dns/dns_la/core/api.php';
        $domains = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $account = dnsla_getAccount((int)($params['account_id'] ?? 0));
        if (!$account) { _jFail($master, $jobId, 'DNS-LA 账号不存在'); return; }

        $token   = dnsla_buildToken($account['api_id'], $account['api_secret']);
        $base    = $account['api_base'] ?? '';
        $ips     = (array)($params['ips'] ?? []);
        $hosts   = (array)($params['hosts'] ?? []);
        $recType = (int)($params['rec_type'] ?? 1);
        $total   = count($domains);
        $ok = $notFound = $failed = 0;
        $failDetails     = [];
        $notFoundDomains = [];
        $CONCUR  = 20;
        $done    = 0;

        $domains = array_map(fn($d) => rtrim(strtolower(trim($d)), '.'), $domains);
        _jProgress($master, $jobId, 0, $total, '并发查询域名 ID…');
        $idMap = [];
        foreach (array_chunk($domains, $CONCUR) as $batch) {
            $idMap += dnsla_findDomainIds($account, $batch);
        }
        _jProgress($master, $jobId, 0, $total, '开始处理记录…');

        foreach ($domains as $i => $domain) {
            $dnsId = $idMap[$domain] ?? null;
            if (!$dnsId) {
                $notFound++;
                $notFoundDomains[] = $domain;
                $done++;
                continue;
            }

            $ip   = $ips[array_rand($ips)];
            $recs = dnsla_fetchAllRecords($account, $dnsId);
            if (!$recs) {
                $notFound++;
                $notFoundDomains[] = $domain;
                continue;
            }

            foreach ($recs as $rec) {
                if ((int)($rec['type'] ?? 0) !== $recType) continue;
                if (!empty($hosts) && !in_array($rec['host'] ?? '', $hosts, true)) continue;

                $r = dnsla_request('PUT', '/api/record', [], [
                    'id'   => $rec['id'],
                    'type' => $recType,
                    'host' => $rec['host'],
                    'data' => $ip,
                    'ttl'  => $rec['ttl'] ?? 600,
                ], $token, $base);
                if ($r['ok']) {
                    $ok++;
                } else {
                    $failed++;
                    $host = $rec['host'] ?? '@';
                    $failDetails[] = ["{$domain} [{$host}]", dnsla_errmsg((int)($r['code'] ?? 0), $r['msg'] ?? '')];
                }
            }

            $done++;
            if ($done % 10 === 0 || $done === $total) {
                _jProgress($master, $jobId, $done, $total, "已处理 {$done} / {$total}，修改 {$ok}，失败 {$failed}");
            }
        }
        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, [
            'ok' => $ok, 'not_found' => $notFound, 'failed' => $failed,
            'fail_details' => $failDetails, 'not_found_domains' => $notFoundDomains,
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // DNS-LA：批量删除解析记录
    // ════════════════════════════════════════════════════════════
    elseif ($type === 'dns_la_del_records') {
        require_once dirname(__DIR__) . '/dns/dns_la/core/api.php';
        $domains = json_decode(file_get_contents($params['data_file']), true) ?: [];
        $account = dnsla_getAccount((int)($params['account_id'] ?? 0));
        if (!$account) { _jFail($master, $jobId, 'DNS-LA 账号不存在'); return; }

        $token   = dnsla_buildToken($account['api_id'], $account['api_secret']);
        $base    = $account['api_base'] ?? '';
        $hosts   = (array)($params['hosts'] ?? []);
        $recType = (int)($params['rec_type'] ?? 1);
        $total   = count($domains);
        $ok = $notFound = $failed = 0;
        $failDetails     = [];
        $notFoundDomains = [];
        $CONCUR  = 20;
        $done    = 0;

        $domains = array_map(fn($d) => rtrim(strtolower(trim($d)), '.'), $domains);
        _jProgress($master, $jobId, 0, $total, '并发查询域名 ID…');
        $idMap = [];
        foreach (array_chunk($domains, $CONCUR) as $batch) {
            $idMap += dnsla_findDomainIds($account, $batch);
        }
        _jProgress($master, $jobId, 0, $total, '开始处理记录…');

        foreach ($domains as $i => $domain) {
            $dnsId = $idMap[$domain] ?? null;
            if (!$dnsId) {
                $notFound++;
                $notFoundDomains[] = $domain;
                $done++;
                continue;
            }

            $recs = dnsla_fetchAllRecords($account, $dnsId);
            if (!$recs) {
                $done++;
                continue;
            }

            foreach ($recs as $rec) {
                if ($recType !== 0 && (int)($rec['type'] ?? 0) !== $recType) continue;
                if (!empty($hosts) && !in_array($rec['host'] ?? '', $hosts, true)) continue;

                $r = dnsla_request('DELETE', '/api/record', ['id' => $rec['id']], [], $token, $base);
                if ($r['ok']) {
                    $ok++;
                } else {
                    $failed++;
                    $host = $rec['host'] ?? '@';
                    $failDetails[] = ["{$domain} [{$host}]", dnsla_errmsg((int)($r['code'] ?? 0), $r['msg'] ?? '')];
                }
            }

            $done++;
            if ($done % 10 === 0 || $done === $total) {
                _jProgress($master, $jobId, $done, $total, "已处理 {$done} / {$total}，删除 {$ok}，失败 {$failed}");
            }
        }
        if (file_exists($params['data_file'])) unlink($params['data_file']);
        _jDone($master, $jobId, [
            'ok' => $ok, 'not_found' => $notFound, 'failed' => $failed,
            'fail_details' => $failDetails, 'not_found_domains' => $notFoundDomains,
        ]);
    }

    else {
        _jFail($master, $jobId, "未知任务类型: $type");
    }

} catch (Throwable $e) {
    _jFail($master, $jobId, $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}
