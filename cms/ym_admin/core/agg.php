<?php
// ════════════════════════════════════════════════════════════════
// 聚合统计（faceted search / 汇总标签区块）
// ════════════════════════════════════════════════════════════════

function getFilterAggregates(array $filters, array $tagFilter, int $limit = 20, array $dlList = []): array {
    $master = getMasterDB();
    $result = [];

    // 域名列表搜索：用临时表代替大量 IN 参数，规避 SQLite 999 参数上限
    $dlCond = '';
    if ($dlList) {
        $master->exec("CREATE TEMP TABLE IF NOT EXISTS _dl_filter (domain TEXT PRIMARY KEY)");
        $master->exec("DELETE FROM _dl_filter");
        foreach (array_chunk($dlList, 100) as $chunk) {
            $ph   = implode(',', array_fill(0, count($chunk), '(?)'));
            $stmt = $master->prepare("INSERT OR IGNORE INTO _dl_filter(domain) VALUES $ph");
            $stmt->execute($chunk);
        }
        $dlCond = ' AND d.domain IN (SELECT domain FROM _dl_filter)';
    }

    // 在 _buildMasterWhere 的结果上追加 dl 条件（无额外参数）
    $buildWhere = function($f, $tf) use ($dlCond) {
        [$w, $p] = _buildMasterWhere($f, $tf);
        if ($dlCond) {
            $w .= $dlCond;
        }
        return [$w, $p];
    };

    $nullRow = function($w, $p, $col) use ($master) {
        try {
            $cnt = (int)db_val($master,
                "SELECT COUNT(*) FROM domains d WHERE $w AND ($col IS NULL OR $col='')", $p);
            return $cnt > 0 ? [['val' => '', 'cnt' => $cnt, '_empty' => true]] : [];
        } catch (Exception $e) { return []; }
    };

    $addMulti = function($key, $label, $col) use (&$result, $master, $filters, $tagFilter, $limit, $nullRow, $buildWhere) {
        $rows = []; $truncated = false;
        try {
            $f = array_merge($filters, [$key => []]);
            [$w, $p] = $buildWhere($f, $tagFilter);
            $qrows = db_all($master,
                "SELECT $col AS val, COUNT(*) AS cnt FROM domains d WHERE $w AND $col!=''
                 GROUP BY $col ORDER BY cnt DESC LIMIT " . ($limit + 1), $p);
            if (is_array($qrows)) {
                $truncated = count($qrows) > $limit;
                if ($truncated) array_pop($qrows);
                $rows = $qrows;
            }
            $rows = array_merge($rows, $nullRow($w, $p, $col));
        } catch (Exception $e) {}
        $result[$key] = ['label' => $label, 'field' => $key, 'type' => 'multi',
                         'rows' => $rows, 'truncated' => $truncated];
    };

    $addPlain = function($key, $label, $col) use (&$result, $master, $filters, $tagFilter, $limit, $nullRow, $buildWhere) {
        $rows = []; $truncated = false;
        try {
            $f = array_merge($filters, [$key => '']);
            [$w, $p] = $buildWhere($f, $tagFilter);
            $qrows = db_all($master,
                "SELECT $col AS val, COUNT(*) AS cnt FROM domains d WHERE $w AND $col!=''
                 GROUP BY $col ORDER BY cnt DESC LIMIT " . ($limit + 1), $p);
            if (is_array($qrows)) {
                $truncated = count($qrows) > $limit;
                if ($truncated) array_pop($qrows);
                $rows = $qrows;
            }
            $rows = array_merge($rows, $nullRow($w, $p, $col));
        } catch (Exception $e) {}
        $result[$key] = ['label' => $label, 'field' => $key, 'type' => 'plain',
                         'rows' => $rows, 'truncated' => $truncated];
    };

    // 读取字段可见性配置，只对 show_in_list=true 的字段做汇总
    $bfCfg = getBuiltinFields(); // ['name' => ['show_in_list'=>bool, 'label'=>str, ...]]
    $bfVisible = function($name) use ($bfCfg) {
        return !empty($bfCfg[$name]['show_in_list']);
    };
    $bfLabel = function($name, $fallback) use ($bfCfg) {
        return !empty($bfCfg[$name]['label']) ? $bfCfg[$name]['label'] : $fallback;
    };

    if ($bfVisible('status'))     $addMulti('status',     $bfLabel('status',     '状态'),    'd.status');
    if ($bfVisible('icp_type'))   $addMulti('icp_type',   $bfLabel('icp_type',   '备案类型'), 'd.icp_type');
    if ($bfVisible('group_name')) $addPlain('group_name', $bfLabel('group_name', '分组'),    'd.group_name');
    if ($bfVisible('dns_servers'))$addPlain('dns',        $bfLabel('dns_servers','DNS'),     'd.dns_servers');

    // 注册商（虚拟字段 registrar）
    if ($bfVisible('registrar')) {
    try {
        $fNoReg  = array_merge($filters, ['registrar_id' => []]);
        [$w, $p] = $buildWhere($fNoReg, $tagFilter);
        $rows = db_all($master,
            "SELECT r.id AS val, r.name AS label, COUNT(*) AS cnt
             FROM registrars r JOIN domains d ON d.registrar_id=r.id
             WHERE $w GROUP BY r.id ORDER BY cnt DESC LIMIT " . ($limit + 1), $p);
        $truncated = false;
        if (!is_array($rows)) $rows = [];
        if (count($rows) > $limit) { $truncated = true; array_pop($rows); }
        $emptyCnt = (int)db_val($master,
            "SELECT COUNT(*) FROM domains d WHERE $w AND d.registrar_id IS NULL", $p);
        if ($emptyCnt > 0) $rows[] = ['val' => '', 'cnt' => $emptyCnt, '_empty' => true];
    } catch (Exception $e) { $rows = []; $truncated = false; }
    $result['registrar_id'] = ['label' => $bfLabel('registrar','注册商'), 'field' => 'registrar_id', 'type' => 'multi',
                                'rows' => $rows, 'truncated' => $truncated];
    } // end if registrar visible

    // 账号（虚拟字段 account）
    if ($bfVisible('account')) {
    try {
        $fNoAcc  = array_merge($filters, ['account_id' => '']);
        [$w, $p] = $buildWhere($fNoAcc, $tagFilter);
        $rows = db_all($master,
            "SELECT a.id AS val, a.username AS label, COUNT(*) AS cnt
             FROM accounts a JOIN domains d ON d.account_id=a.id
             WHERE $w GROUP BY a.id ORDER BY cnt DESC LIMIT " . ($limit + 1), $p);
        $truncated = false;
        if (!is_array($rows)) $rows = [];
        if (count($rows) > $limit) { $truncated = true; array_pop($rows); }
        $emptyCnt = (int)db_val($master,
            "SELECT COUNT(*) FROM domains d WHERE $w AND d.account_id IS NULL", $p);
        if ($emptyCnt > 0) $rows[] = ['val' => '', 'cnt' => $emptyCnt, '_empty' => true];
    } catch (Exception $e) { $rows = []; $truncated = false; }
    $result['account_id'] = ['label' => $bfLabel('account','账号'), 'field' => 'account_id', 'type' => 'plain',
                              'rows' => $rows, 'truncated' => $truncated];
    } // end if account visible

    // 自定义字段（PHP 侧解析 JSON，不依赖 json_extract）
    // 只汇总 show_in_list=1 的自定义字段
    try {
        $cfFields = db_all($master, "SELECT * FROM custom_fields WHERE show_in_list=1 ORDER BY sort_order, id");
        if (is_array($cfFields)) {
            foreach ($cfFields as $cf) {
                $fNoCf = $filters;
                if (isset($fNoCf['cf'][$cf['name']])) unset($fNoCf['cf'][$cf['name']]);
                $cfRows = []; $cfTrunc = false;
                try {
                    [$w, $p] = $buildWhere($fNoCf, $tagFilter);
                    $allCd = db_all($master, "SELECT custom_data FROM domains d WHERE $w", $p);

                    $valueCounts = []; $emptyCnt = 0;
                    foreach ($allCd as $row) {
                        $cd  = json_decode($row['custom_data'] ?? '{}', true) ?: [];
                        $val = isset($cd[$cf['name']]) ? trim((string)$cd[$cf['name']]) : '';
                        if ($val === '') { $emptyCnt++; }
                        else             { $valueCounts[$val] = ($valueCounts[$val] ?? 0) + 1; }
                    }

                    if ($cf['field_type'] === 'textarea') {
                        $hasCnt = array_sum($valueCounts);
                        if ($hasCnt > 0)   $cfRows[] = ['val' => '__has__', 'cnt' => $hasCnt,  '_has'   => true];
                        if ($emptyCnt > 0) $cfRows[] = ['val' => '',        'cnt' => $emptyCnt, '_empty' => true];
                    } else {
                        arsort($valueCounts);
                        $cfTrunc = count($valueCounts) > $limit;
                        foreach (array_slice($valueCounts, 0, $limit, true) as $v => $c) {
                            $cfRows[] = ['val' => $v, 'cnt' => $c];
                        }
                        if ($emptyCnt > 0) $cfRows[] = ['val' => '', 'cnt' => $emptyCnt, '_empty' => true];
                    }
                } catch (Exception $e) {}
                $result['cf_' . $cf['name']] = [
                    'label' => $cf['label'], 'field' => 'cf',
                    'cf_name' => $cf['name'], 'type' => 'cf',
                    'rows' => $cfRows, 'truncated' => $cfTrunc,
                ];
            }
        }
    } catch (Exception $e) {}

    return $result;
}
