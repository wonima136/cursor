<?php
// ════════════════════════════════════════════════════════════════
// 导入辅助函数
// ════════════════════════════════════════════════════════════════

function _normalizeDate(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    $ts = strtotime(str_replace('/', '-', $v));
    return ($ts && $ts > 0) ? date('Y-m-d', $ts) : $v;
}

function _importRow(PDO $master, array $row, ?int $registrarId, ?int $accountId, array $tagIds, bool $update): array {
    static $regCache = [], $accCache = [];

    $domain = trim($row['domain'] ?? '');
    if (!$domain) return [];

    // 提取自定义字段（__cf_* 前缀的键）
    $customData = [];
    foreach ($row as $key => $val) {
        if (strpos($key, '__cf_') === 0) {
            $cfName = substr($key, 5);
            $v = trim((string)$val);
            if ($v !== '') $customData[$cfName] = $v;
        }
    }

    // 从 CSV 列 "注册商" 自动创建/匹配注册商（全局设置优先）
    if (!$registrarId && !empty(trim($row['registrar_name'] ?? ''))) {
        $rname = trim($row['registrar_name']);
        $rkey  = mb_strtolower($rname);
        if (!isset($regCache[$rkey])) {
            $reg = db_one($master, "SELECT id FROM registrars WHERE name=?", [$rname]);
            $regCache[$rkey] = $reg ? (int)$reg['id']
                                    : (int)db_insert($master, 'registrars', ['name' => $rname]);
        }
        $registrarId = $regCache[$rkey];
    }

    // $row 的键就是 CSV 实际提供的字段（经过 header mapping 之后）
    // 只有 CSV 中出现的列才写入，避免"没提供=空"覆盖已有数据
    $upd = ['updated_at' => date('Y-m-d H:i:s')];

    // 内置字段：只有 CSV 有该列才写（兼容 PHP 7.2）
    if (array_key_exists('register_date',  $row)) $upd['register_date']  = _normalizeDate($row['register_date']);
    if (array_key_exists('expire_date',    $row)) $upd['expire_date']    = _normalizeDate($row['expire_date']);
    if (array_key_exists('status',         $row)) $upd['status']         = trim($row['status']);
    if (array_key_exists('icp_type',       $row)) $upd['icp_type']       = trim($row['icp_type']);
    if (array_key_exists('icp_number',     $row)) $upd['icp_number']     = trim($row['icp_number']);
    if (array_key_exists('dns_servers',    $row)) $upd['dns_servers']    = trim($row['dns_servers']);
    if (array_key_exists('group_name',     $row)) $upd['group_name']     = trim($row['group_name']);
    if (array_key_exists('admin_password', $row)) $upd['admin_password'] = trim($row['admin_password']);
    // registrar/account：CSV 有列 或 页面全局选了就写
    if (array_key_exists('registrar_name', $row) || $registrarId !== null) {
        $upd['registrar_id'] = $registrarId;
    }
    if ($accountId !== null) {
        $upd['account_id'] = $accountId;
    }

    // 新域名完整数据（全字段，用于 INSERT）
    $data = array_merge([
        'domain'         => $domain,
        'registrar_id'   => $registrarId,
        'account_id'     => $accountId,
        'register_date'  => _normalizeDate($row['register_date']  ?? ''),
        'expire_date'    => _normalizeDate($row['expire_date']    ?? ''),
        'status'         => trim($row['status']         ?? 'normal'),
        'icp_type'       => trim($row['icp_type']       ?? 'none'),
        'icp_number'     => trim($row['icp_number']     ?? ''),
        'dns_servers'    => trim($row['dns_servers']    ?? ''),
        'group_name'     => trim($row['group_name']     ?? ''),
        'admin_password' => trim($row['admin_password'] ?? ''),
    ]);

    $exist = db_one($master, "SELECT id, custom_data FROM domains WHERE domain=?", [$domain]);
    if ($exist) {
        if ($update) {
            // 合并自定义字段（只追加/覆盖 CSV 提供的列，其余保留原值）
            if ($customData) {
                $existCd = json_decode($exist['custom_data'] ?? '{}', true) ?: [];
                $upd['custom_data'] = json_encode(
                    array_merge($existCd, $customData),
                    JSON_UNESCAPED_UNICODE
                );
            }
            updateDomain($exist['id'], $upd);
            if ($tagIds) setDomainTags($exist['id'], $tagIds);
            return ['domain' => $domain, 'status' => 'updated'];
        }
        return ['domain' => $domain, 'status' => 'skipped'];
    }

    // 新域名：写入自定义字段
    if ($customData) {
        $data['custom_data'] = json_encode($customData, JSON_UNESCAPED_UNICODE);
    }
    $newId = createDomain($data);
    if ($tagIds) setDomainTags($newId, $tagIds);
    return ['domain' => $domain, 'status' => 'added'];
}
