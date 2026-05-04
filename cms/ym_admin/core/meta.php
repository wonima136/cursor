<?php
// ════════════════════════════════════════════════════════════════
// 字段管理
// ════════════════════════════════════════════════════════════════

// 从数据库自动探测内置字段，合并已保存的显示配置
function getBuiltinFields(): array {
    // 内部列：不作为可见字段暴露
    $skip = ['id', 'domain', 'registrar_id', 'account_id', 'created_at', 'updated_at', 'custom_data'];

    // 默认标签（用于首次识别时的友好名称）
    $defaultLabels = [
        'register_date'  => ['label' => '注册时间', 'show' => true,  'sort' => 30],
        'expire_date'    => ['label' => '过期时间', 'show' => true,  'sort' => 40],
        'status'         => ['label' => '状态',    'show' => true,  'sort' => 50],
        'icp_type'       => ['label' => '备案',    'show' => true,  'sort' => 60],
        'icp_number'     => ['label' => '备案号',  'show' => false, 'sort' => 65],
        'dns_servers'    => ['label' => 'DNS',     'show' => true,  'sort' => 70],
        'group_name'     => ['label' => '分组',    'show' => false, 'sort' => 80],
        'admin_password' => ['label' => '管理密码', 'show' => false, 'sort' => 90],
    ];

    // 读取已保存配置
    $saved = _readFieldConfig();

    // 虚拟字段（JOIN 出来的，不是 DB 真实列）
    $fields = [];
    foreach ([
        'registrar' => ['label' => '注册商', 'sort' => 10],
        'account'   => ['label' => '账号',   'sort' => 20],
    ] as $name => $def) {
        if (!empty($saved[$name]['deleted'])) continue; // 已删除则跳过
        $s = $saved[$name] ?? [];
        $fields[$name] = [
            'name'         => $name,
            'label'        => $s['label'] ?? $def['label'],
            'show_in_list' => $s['show_in_list'] ?? true,
            'sort_order'   => $s['sort_order']   ?? $def['sort'],
            'can_delete'   => true,
        ];
    }

    // 自动探测真实 DB 列
    $cols = db_all(getMasterDB(), "PRAGMA table_info(domains)") ?: [];
    foreach ($cols as $col) {
        $name = $col['name'];
        if (in_array($name, $skip)) continue;
        if (!empty($saved[$name]['deleted'])) continue; // 已删除则跳过
        $def = $defaultLabels[$name] ?? ['label' => $name, 'show' => false, 'sort' => 500];
        $s   = $saved[$name] ?? [];
        $fields[$name] = [
            'name'         => $name,
            'label'        => $s['label'] ?? $def['label'],
            'show_in_list' => $s['show_in_list'] ?? $def['show'],
            'sort_order'   => $s['sort_order']   ?? $def['sort'],
            'can_delete'   => true,
        ];
    }

    uasort($fields, function($a, $b) { return $a['sort_order'] <=> $b['sort_order']; });
    return $fields;
}

// 获取已删除的内置字段（用于恢复功能）
function getDeletedBuiltinFields(): array {
    $saved = _readFieldConfig();
    $skip  = ['id', 'domain', 'registrar_id', 'account_id', 'created_at', 'updated_at', 'custom_data'];
    $deleted = [];
    // 虚拟字段
    foreach (['registrar', 'account'] as $name) {
        if (!empty($saved[$name]['deleted'])) {
            $deleted[$name] = array_merge(['name' => $name], $saved[$name]);
        }
    }
    // DB 列
    $cols = db_all(getMasterDB(), "PRAGMA table_info(domains)") ?: [];
    foreach ($cols as $col) {
        $name = $col['name'];
        if (in_array($name, $skip)) continue;
        if (!empty($saved[$name]['deleted'])) {
            $deleted[$name] = array_merge(['name' => $name, 'label' => $name], $saved[$name]);
        }
    }
    return $deleted;
}

// 兼容旧代码调用 getSysFieldConfig()
function getSysFieldConfig(): array { return getBuiltinFields(); }

function saveBuiltinFieldConfig(array $updates): void {
    $current = _readFieldConfig();
    foreach ($updates as $name => $cfg) {
        $current[$name] = $cfg;
    }
    file_put_contents(
        DATA_DIR . '/field_config.json',
        json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

// 兼容旧代码调用 saveSysFieldConfig()
function saveSysFieldConfig(array $u): void { saveBuiltinFieldConfig($u); }

function _readFieldConfig(): array {
    $file = DATA_DIR . '/field_config.json';
    return file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
}

function getCustomFields(): array {
    return db_all(getMasterDB(),
        "SELECT * FROM custom_fields ORDER BY sort_order, id");
}

function cfSlug(string $label = ''): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    $db    = getMasterDB();
    do {
        $id = '';
        for ($i = 0; $i < 4; $i++) {
            $id .= $chars[random_int(0, 25)];
        }
    } while (db_one($db, "SELECT id FROM custom_fields WHERE name=?", [$id]));
    return $id;
}

function getDomainCustomData(int $domainId): array {
    $master = getMasterDB();
    $row    = db_one($master, "SELECT custom_data FROM domains WHERE id=?", [$domainId]);
    if (!$row || !$row['custom_data']) return [];
    return json_decode($row['custom_data'], true) ?: [];
}

function updateDomainCustomData(int $domainId, array $values): void {
    $master  = getMasterDB();
    $current = getDomainCustomData($domainId);
    $merged  = array_merge($current, $values);
    db_update($master, 'domains',
        ['custom_data' => json_encode($merged, JSON_UNESCAPED_UNICODE)],
        'id=?', [$domainId]
    );
}
