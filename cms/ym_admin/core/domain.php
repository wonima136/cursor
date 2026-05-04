<?php
// ════════════════════════════════════════════════════════════════
// 域名标签操作
// ════════════════════════════════════════════════════════════════

function _getDomainTags(int $domainId): array {
    $master = getMasterDB();
    return db_all($master, "
        SELECT t.* FROM tags t
        JOIN domain_tags dt ON dt.tag_id = t.id
        WHERE dt.domain_id = ?
        ORDER BY t.sort_order, t.name
    ", [$domainId]);
}

function setDomainTags(int $domainId, array $newTagIds): void {
    $master    = getMasterDB();
    $oldRows   = db_all($master, "SELECT tag_id FROM domain_tags WHERE domain_id=?", [$domainId]);
    $oldTagIds = array_column($oldRows, 'tag_id');

    foreach (array_diff($oldTagIds, $newTagIds) as $tid) {
        db_delete($master, 'domain_tags', 'domain_id=? AND tag_id=?', [$domainId, (int)$tid]);
    }
    foreach (array_diff($newTagIds, $oldTagIds) as $tid) {
        db_exec($master, 'INSERT OR IGNORE INTO domain_tags (domain_id, tag_id) VALUES (?,?)',
            [$domainId, (int)$tid]);
    }
}

// ════════════════════════════════════════════════════════════════
// 历史记录
// ════════════════════════════════════════════════════════════════

function addHistory(int $domainId, string $type, string $content): void {
    $master = getMasterDB();
    db_insert($master, 'domain_history', [
        'domain_id'   => $domainId,
        'action_type' => $type,
        'content'     => $content,
    ]);
}

function getDomainHistory(int $domainId): array {
    $master = getMasterDB();
    return db_all($master,
        "SELECT * FROM domain_history WHERE domain_id=? ORDER BY created_at DESC",
        [$domainId]
    );
}

// ════════════════════════════════════════════════════════════════
// CRUD 封装
// ════════════════════════════════════════════════════════════════

function createDomain(array $data): int {
    $master = getMasterDB();
    return db_insert($master, 'domains', $data);
}

function updateDomain(int $id, array $data): void {
    $master = getMasterDB();
    db_update($master, 'domains', $data, 'id=?', [$id]);
}

function deleteDomain(int $id): void {
    $master = getMasterDB();
    db_delete($master, 'domain_history', 'domain_id=?', [$id]);
    db_delete($master, 'domain_tags',    'domain_id=?', [$id]);
    db_delete($master, 'domains',        'id=?',        [$id]);
}

// ════════════════════════════════════════════════════════════════
// 读取封装
// ════════════════════════════════════════════════════════════════

function getDomainInfo(int $id): ?array {
    $master = getMasterDB();
    $row = db_one($master, "
        SELECT d.*, r.name AS registrar_name, a.username AS account_name
        FROM domains d
        LEFT JOIN registrars r ON r.id = d.registrar_id
        LEFT JOIN accounts  a ON a.id = d.account_id
        WHERE d.id = ?
    ", [$id]);
    if ($row) {
        $row['tags'] = _getDomainTags($id);
    }
    return $row;
}

function getMasterDomains(array $filters = [], array $tagFilter = [], int $offset = 0, int $limit = PER_PAGE): array {
    $master = getMasterDB();
    [$where, $params] = _buildMasterWhere($filters, $tagFilter);

    $rows = db_all($master,
        "SELECT d.*, r.name AS registrar_name, a.username AS account_name
         FROM domains d
         LEFT JOIN registrars r ON r.id = d.registrar_id
         LEFT JOIN accounts  a ON a.id = d.account_id
         WHERE $where ORDER BY d.expire_date ASC, d.domain ASC LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );

    if ($rows) {
        $ids    = array_column($rows, 'id');
        $tagMap = [];
        foreach (array_chunk($ids, 999) as $chunk) {
            $ph      = implode(',', array_fill(0, count($chunk), '?'));
            $tagRows = db_all($master, "
                SELECT dt.domain_id, t.id, t.name, t.color, t.sort_order
                FROM tags t
                JOIN domain_tags dt ON dt.tag_id = t.id
                WHERE dt.domain_id IN ($ph)
                ORDER BY t.sort_order, t.name
            ", $chunk);
            foreach ($tagRows as $tr) {
                $tagMap[$tr['domain_id']][] = $tr;
            }
        }
        foreach ($rows as &$row) {
            $row['tags'] = $tagMap[$row['id']] ?? [];
        }
        unset($row);
    }
    return $rows;
}

function countMasterDomains(array $filters = [], array $tagFilter = []): int {
    $master = getMasterDB();
    [$where, $params] = _buildMasterWhere($filters, $tagFilter);
    return (int)db_val($master, "SELECT COUNT(*) FROM domains d WHERE $where", $params);
}

function _buildMasterWhere(array $filters, array $tagFilter = []): array {
    $where = ['1=1']; $params = [];

    // 批量域名精确查找（优先级最高，和其他过滤可叠加）
    // 用临时表代替 IN(?,?,...) 参数，彻底规避 SQLite 999 参数上限
    if (!empty($filters['domain_list']) && is_array($filters['domain_list'])) {
        $list = array_values(array_unique(array_filter(array_map('trim', $filters['domain_list']))));
        if ($list) {
            $masterForTmp = getMasterDB();
            $masterForTmp->exec("CREATE TEMP TABLE IF NOT EXISTS _dl_filter (domain TEXT PRIMARY KEY)");
            $masterForTmp->exec("DELETE FROM _dl_filter");
            // 每批 100 个插入（INSERT VALUES 参数少，速度快）
            foreach (array_chunk($list, 100) as $chunk) {
                $ph   = implode(',', array_fill(0, count($chunk), '(?)'));
                $stmt = $masterForTmp->prepare("INSERT OR IGNORE INTO _dl_filter(domain) VALUES $ph");
                $stmt->execute($chunk);
            }
            $where[] = 'd.domain IN (SELECT domain FROM _dl_filter)';
            // 无需额外参数，子查询直接引用临时表
        }
    }

    if (!empty($filters['search'])) {
        $where[] = "d.domain LIKE ?";
        $params[] = '%' . $filters['search'] . '%';
    }

    foreach (['status' => 'd.status', 'icp_type' => 'd.icp_type'] as $key => $col) {
        $val = $filters[$key] ?? '';
        $vals = array_values(array_filter(is_array($val) ? $val : ($val !== '' ? [$val] : [])));
        if ($vals) {
            $hasEmpty = in_array('__EMPTY__', $vals);
            $realVals = array_values(array_filter($vals, function($v) { return $v !== '__EMPTY__'; }));
            $parts = [];
            if ($realVals) {
                $ph = implode(',', array_fill(0, count($realVals), '?'));
                $parts[] = count($realVals) === 1 ? "$col=?" : "$col IN ($ph)";
                $params  = array_merge($params, $realVals);
            }
            if ($hasEmpty) {
                $parts[] = "($col='' OR $col IS NULL)";
            }
            if ($parts) {
                $where[] = count($parts) === 1 ? $parts[0] : '(' . implode(' OR ', $parts) . ')';
            }
        }
    }

    $ridsRaw = $filters['registrar_id'] ?? '';
    $ridsRaw = is_array($ridsRaw) ? $ridsRaw : ($ridsRaw !== '' ? [$ridsRaw] : []);
    $hasEmptyReg = in_array('__EMPTY__', $ridsRaw);
    $rids = array_values(array_filter(array_map('intval',
        array_filter($ridsRaw, function($v) { return $v !== '__EMPTY__'; })
    )));
    $regParts = [];
    if ($rids) {
        $ph = implode(',', array_fill(0, count($rids), '?'));
        $regParts[] = count($rids) === 1 ? "d.registrar_id=?" : "d.registrar_id IN ($ph)";
        $params = array_merge($params, $rids);
    }
    if ($hasEmptyReg) {
        $regParts[] = "(d.registrar_id IS NULL OR d.registrar_id=0)";
    }
    if ($regParts) {
        $where[] = count($regParts) === 1 ? $regParts[0] : '(' . implode(' OR ', $regParts) . ')';
    }

    if (!empty($filters['group_name'])) {
        if ($filters['group_name'] === '__EMPTY__') {
            $where[] = "(d.group_name='' OR d.group_name IS NULL)";
        } else {
            $where[] = "d.group_name=?";
            $params[] = $filters['group_name'];
        }
    }

    if (!empty($filters['dns'])) {
        if ($filters['dns'] === '__EMPTY__') {
            $where[] = "(d.dns_servers='' OR d.dns_servers IS NULL)";
        } else {
            $where[] = "d.dns_servers=?";
            $params[] = $filters['dns'];
        }
    }

    if (!empty($filters['account_id'])) {
        if ($filters['account_id'] === '__EMPTY__') {
            $where[] = "(d.account_id IS NULL OR d.account_id=0)";
        } else {
            $where[] = "d.account_id=?";
            $params[] = (int)$filters['account_id'];
        }
    }

    // 排除已过期域名（独立开关，与其他筛选叠加）
    // 使用 > today，今天到期的也视为已过期排除（与 badge 判断逻辑一致）
    if (!empty($filters['exclude_expired'])) {
        $today2   = date('Y-m-d');
        $where[]  = "(d.expire_date='' OR d.expire_date IS NULL OR d.expire_date>?)";
        $params[] = $today2;
    }

    // 过滤 N 天内到期域名（隐藏 expire_date <= 今天+N 的域名）
    if (!empty($filters['excl_days']) && (int)$filters['excl_days'] > 0) {
        $cutoff  = date('Y-m-d', strtotime('+' . (int)$filters['excl_days'] . ' days'));
        $where[] = "(d.expire_date='' OR d.expire_date IS NULL OR d.expire_date>?)";
        $params[] = $cutoff;
    }

    if (!empty($filters['expire_warn'])) {
        $today = date('Y-m-d');
        if ($filters['expire_warn'] === 'expired') {
            $where[] = "d.expire_date!='' AND d.expire_date<?";
            $params[] = $today;
        } elseif ($filters['expire_warn'] === 'today') {
            $where[] = "d.expire_date=?";
            $params[] = $today;
        } elseif (is_numeric($filters['expire_warn']) && (int)$filters['expire_warn'] > 0) {
            $days = (int)$filters['expire_warn'];
            $where[] = "d.expire_date!='' AND d.expire_date>=? AND d.expire_date<=?";
            $params[] = $today;
            $params[] = date('Y-m-d', strtotime("+{$days} days"));
        }
    }

    if (!empty($filters['date_from'])) {
        $where[]  = "d.expire_date >= ?";
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[]  = "d.expire_date <= ?";
        $params[] = $filters['date_to'];
    }

    if (!empty($filters['cf']) && is_array($filters['cf'])) {
        foreach ($filters['cf'] as $cfName => $cfVal) {
            $cfVal = trim((string)$cfVal);
            if ($cfName === '') continue;
            if ($cfVal === '__EMPTY__') {
                // 筛选该自定义字段为空（没有该键，或值为空字符串）
                $jsonKey = '"' . str_replace('"', '\\"', $cfName) . '":';
                $likeEsc = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $jsonKey);
                $where[]  = "(d.custom_data IS NULL OR d.custom_data='' OR d.custom_data='{}' OR d.custom_data NOT LIKE ? ESCAPE '!')";
                $params[] = '%' . $likeEsc . '%';
            } elseif ($cfVal !== '') {
                $jsonKV   = '"' . str_replace('"', '\\"', $cfName) . '":' . json_encode($cfVal, JSON_UNESCAPED_UNICODE);
                $likeEsc  = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $jsonKV);
                $where[]  = "d.custom_data LIKE ? ESCAPE '!'";
                $params[] = '%' . $likeEsc . '%';
            }
        }
    }

    if ($tagFilter) {
        $ph = implode(',', array_fill(0, count($tagFilter), '?'));
        $where[] = "d.id IN (
            SELECT domain_id FROM domain_tags WHERE tag_id IN ($ph)
            GROUP BY domain_id HAVING COUNT(*)=" . count($tagFilter) . "
        )";
        $params = array_merge($params, $tagFilter);
    }

    return [implode(' AND ', $where), $params];
}

// ════════════════════════════════════════════════════════════════
// 统计 & 显示助手
// ════════════════════════════════════════════════════════════════

function getStats(): array {
    $master = getMasterDB();
    $today  = date('Y-m-d');
    $warn7  = date('Y-m-d', strtotime('+7 days'));
    $warn30 = date('Y-m-d', strtotime('+30 days'));
    return [
        'total'   => db_count($master, 'domains'),
        'normal'  => db_count($master, 'domains', "status='normal'"),
        'paused'  => db_count($master, 'domains', "status='paused'"),
        'expired' => db_count($master, 'domains', "expire_date!='' AND expire_date<?", [$today]),
        'soon7'   => db_count($master, 'domains', "expire_date!='' AND expire_date>=? AND expire_date<=?", [$today, $warn7]),
        'soon30'  => db_count($master, 'domains', "expire_date!='' AND expire_date>=? AND expire_date<=?", [$today, $warn30]),
    ];
}

function getDomainExpireClass(string $expire): string {
    if (!$expire) return '';
    $days = (strtotime($expire) - time()) / 86400;
    if ($days < 0)                 return 'text-danger fw-bold';
    if ($days <= WARN_DAYS_RED)    return 'text-danger';
    if ($days <= WARN_DAYS_YELLOW) return 'text-warning';
    return '';
}

function getDomainExpireBadge(string $expire): string {
    if (!$expire) return '';
    $days = (strtotime($expire) - time()) / 86400;
    if ($days < 0)                 return '<span class="badge bg-danger ms-1">已过期</span>';
    if ($days <= WARN_DAYS_RED)    return '<span class="badge bg-danger ms-1">'.(int)$days.'天</span>';
    if ($days <= WARN_DAYS_YELLOW) return '<span class="badge bg-warning text-dark ms-1">'.(int)$days.'天</span>';
    return '';
}
