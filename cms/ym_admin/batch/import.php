<?php
$pageTitle = '批量导入';
require_once dirname(__DIR__) . '/components/header.php';

$master     = getMasterDB();
$registrars = db_all($master, "SELECT * FROM registrars ORDER BY name");
$accounts   = db_all($master, "SELECT a.*, r.name AS rname FROM accounts a LEFT JOIN registrars r ON r.id=a.registrar_id ORDER BY r.name, a.username");
$allTags    = db_all($master, "SELECT * FROM tags ORDER BY sort_order, name");

$parseError = '';
$activeTab  = 'paste';

// ── 已知标题映射（中英文均可）→ 内部字段名 ─────────────────────
$HEADER_MAP = [
    '域名'       => 'domain',      'domain'           => 'domain',
    '注册时间'   => 'register_date','register_date'    => 'register_date', 'register' => 'register_date',
    '过期时间'   => 'expire_date', 'expire_date'       => 'expire_date',  'expire'   => 'expire_date',
    '状态'       => 'status',      'status'            => 'status',
    '备案'       => 'icp_type',    '备案类型'          => 'icp_type',     'icp_type' => 'icp_type', 'icp' => 'icp_type',
    '备案号'     => 'icp_number',  'icp_number'        => 'icp_number',
    'dns'        => 'dns_servers', 'DNS'               => 'dns_servers',  'dns_servers' => 'dns_servers',
    'DNS服务器'  => 'dns_servers', '域名DNS'           => 'dns_servers',
    '分组'       => 'group_name',  '域名分组'          => 'group_name',   'group_name' => 'group_name', 'group' => 'group_name',
    '注册商'     => 'registrar_name','registrar'       => 'registrar_name','registrar_name' => 'registrar_name',
    '管理密码'   => 'admin_password','admin_password'  => 'admin_password','password' => 'admin_password',
];

// ── 动态扩展：内置字段已配置的中文标签 ──────────────────────────
// 用户可能修改了字段显示名（如把"备案"改成"备案状态"），也能被识别
$_builtinLabelMap = [
    'registrar' => 'registrar_name',
    'register_date' => 'register_date',
    'expire_date'   => 'expire_date',
    'status'        => 'status',
    'icp_type'      => 'icp_type',
    'icp_number'    => 'icp_number',
    'dns_servers'   => 'dns_servers',
    'group_name'    => 'group_name',
    'admin_password'=> 'admin_password',
];
foreach (getBuiltinFields() as $bf) {
    $label = $bf['label'] ?? '';
    if ($label && !isset($HEADER_MAP[$label]) && isset($_builtinLabelMap[$bf['name']])) {
        $HEADER_MAP[$label] = $_builtinLabelMap[$bf['name']];
    }
}

// ── 动态扩展：自定义字段（中文标签 → __cf_{内部名}）────────────
$_customFields = getCustomFields();
foreach ($_customFields as $cf) {
    $HEADER_MAP[$cf['label']]  = '__cf_' . $cf['name']; // 中文标签
    $HEADER_MAP[$cf['name']]   = '__cf_' . $cf['name']; // 内部随机名（兼容）
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $registrarId = (int)($_POST['registrar_id'] ?? 0) ?: null;
    $accountId   = (int)($_POST['account_id']   ?? 0) ?: null;
    $tagIds      = array_values(array_filter(array_map('intval', (array)($_POST['tags'] ?? []))));
    $update      = ($_POST['update_existing'] ?? '') === '1';
    $action      = $_POST['action'] ?? '';
    $rows        = [];

    // ── 模式一：粘贴文本 ─────────────────────────────────────────
    if ($action === 'import') {
        $activeTab = 'paste';
        $sep   = ($_POST['sep'] ?? ',') === 'tab' ? "\t" : ',';
        $lines = array_filter(array_map('trim', explode("\n", $_POST['data'] ?? '')));
        foreach ($lines as $line) {
            $cols = str_getcsv($line, $sep);
            $rows[] = [
                'domain'        => $cols[0] ?? '',
                'register_date' => $cols[1] ?? '',
                'expire_date'   => $cols[2] ?? '',
                'status'        => $cols[3] ?? '',
                'icp_type'      => $cols[4] ?? '',
                'dns_servers'   => $cols[5] ?? '',
                'group_name'    => $cols[6] ?? '',
            ];
        }
    }

    // ── 模式二：上传 CSV ─────────────────────────────────────────
    if ($action === 'csv_import') {
        $activeTab = 'csv';
        if (empty($_FILES['csvfile']['tmp_name'])) {
            $parseError = '请选择要上传的 CSV 文件';
        } else {
            $encoding = $_POST['csv_encoding'] ?? 'UTF-8';
            $content  = file_get_contents($_FILES['csvfile']['tmp_name']);
            if ($encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }
            $content = str_replace(["\r\n", "\r"], "\n", $content);
            $lines   = array_values(array_filter(explode("\n", $content), function($l) { return trim($l) !== ''; }));

            if (count($lines) < 2) {
                $parseError = 'CSV 至少需要一行标题 + 一行数据';
            } else {
                global $HEADER_MAP;
                $headers = str_getcsv(array_shift($lines), ',');
                $colMap  = [];
                foreach ($headers as $idx => $raw) {
                    $key   = trim($raw);
                    $field = $HEADER_MAP[$key] ?? $HEADER_MAP[mb_strtolower($key)] ?? null;
                    if ($field && !isset($colMap[$field])) $colMap[$field] = $idx;
                }
                if (!isset($colMap['domain'])) {
                    $parseError = '未找到「域名」列，请确认第一行有标题（域名 / domain）';
                } else {
                    foreach ($lines as $line) {
                        $cols = str_getcsv($line, ',');
                        $row  = [];
                        foreach ($colMap as $field => $idx) $row[$field] = $cols[$idx] ?? '';
                        $rows[] = $row;
                    }
                }
            }
        }
    }

    // ── 提交后台任务 ──────────────────────────────────────────────
    if ($rows && !$parseError) {
        $jobId = createJob('import', $rows, [
            'registrar_id'    => $registrarId,
            'account_id'      => $accountId,
            'tag_ids'         => $tagIds,
            'update_existing' => $update,
        ]);
        launchJob($jobId);
        redirect('/jobs/progress.php?id=' . $jobId);
    }
}
?>

<!-- Tab 切换 -->
<ul class="nav nav-tabs mb-3" id="importTab">
  <li class="nav-item">
    <button class="nav-link <?= $activeTab === 'paste' ? 'active' : '' ?>"
            data-tab="paste" onclick="switchTab('paste')">
      <i class="bi bi-clipboard-data me-1"></i>粘贴文本
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link <?= $activeTab === 'csv' ? 'active' : '' ?>"
            data-tab="csv" onclick="switchTab('csv')">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>上传 CSV
    </button>
  </li>
</ul>

<?php if ($parseError): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($parseError) ?></div>
<?php endif; ?>

<!-- ══ 粘贴文本 ══════════════════════════════════════════════════ -->
<div id="tab-paste" style="<?= $activeTab === 'paste' ? '' : 'display:none' ?>">
  <div class="form-card mb-3">
    <div class="section-title">格式说明（CSV 表头自动匹配）</div>
    <p class="text-muted small mb-2">
      CSV 第一行为表头，<strong>列顺序不限</strong>，按表头名自动对应字段：
    </p>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <?php
      $importHintFields = [
          ['域名', '必填', 'danger'],
          ['注册时间', '', 'secondary'],
          ['过期时间', '', 'secondary'],
      ];
      foreach (getBuiltinFields() as $bf) {
          if (in_array($bf['name'], ['register_date','expire_date'])) continue;
          $importHintFields[] = [$bf['label'], '', 'primary'];
      }
      foreach ($_customFields as $cf) {
          $importHintFields[] = [$cf['label'], '自定义', 'success'];
      }
      foreach ($importHintFields as [$lbl, $note, $cls]): ?>
      <span class="badge bg-<?= $cls ?>-subtle text-<?= $cls ?> border border-<?= $cls ?>-subtle">
        <?= h($lbl) ?><?= $note ? " <small>($note)</small>" : '' ?>
      </span>
      <?php endforeach; ?>
    </div>
    <p class="text-muted small mb-0">
      <i class="bi bi-lightbulb me-1 text-warning"></i>
      自定义字段：在"字段管理"中创建字段后，CSV 表头写<strong>字段的中文名</strong>即可自动导入。
    </p>
  </div>

  <form method="POST">
    <input type="hidden" name="action" value="import">
    <div class="form-card mb-3">
      <div class="section-title">粘贴域名数据</div>
      <div class="mb-2">
        <select name="sep" class="form-select form-select-sm" style="width:auto;display:inline-block">
          <option value=",">逗号分隔</option>
          <option value="tab">Tab 分隔</option>
        </select>
      </div>
      <textarea name="data" class="form-control font-monospace" rows="12"
                placeholder="example.com,2024-01-01,2025-01-01&#10;test.cn,2024-06-01,2025-06-01"><?= h($_POST['data'] ?? '') ?></textarea>
    </div>
    <?php include __DIR__ . '/_import_settings.php'; ?>
    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>提交后台处理</button>
  </form>
</div>

<!-- ══ 上传 CSV ═══════════════════════════════════════════════════ -->
<div id="tab-csv" style="<?= $activeTab === 'csv' ? '' : 'display:none' ?>">
  <div class="form-card mb-3">
    <div class="section-title">CSV 标题识别规则</div>
    <p class="text-muted small mb-2">第一行必须是标题行，<strong>列顺序任意</strong>，支持以下标题名（中英文均可）：</p>
    <div class="table-responsive">
      <table class="table table-sm table-bordered small">
        <thead><tr><th>字段</th><th>可识别的标题名</th></tr></thead>
        <tbody>
          <tr><td><span class="badge bg-danger">必填</span> 域名</td><td><code>域名</code> &nbsp; <code>domain</code></td></tr>
          <tr><td>注册时间</td><td><code>注册时间</code> &nbsp; <code>register_date</code></td></tr>
          <tr><td>过期时间</td><td><code>过期时间</code> &nbsp; <code>expire_date</code></td></tr>
          <tr><td>状态</td><td><code>状态</code> &nbsp; <code>status</code></td></tr>
          <tr><td>备案类型</td><td><code>备案</code> &nbsp; <code>备案类型</code> &nbsp; <code>icp_type</code></td></tr>
          <tr><td>备案号</td><td><code>备案号</code> &nbsp; <code>icp_number</code></td></tr>
          <tr><td>DNS</td><td><code>DNS</code> &nbsp; <code>域名DNS</code> &nbsp; <code>dns_servers</code></td></tr>
          <tr><td>分组</td><td><code>分组</code> &nbsp; <code>域名分组</code> &nbsp; <code>group_name</code></td></tr>
          <tr><td>注册商 <small class="text-success">（自动创建）</small></td><td><code>注册商</code> &nbsp; <code>registrar</code> &nbsp; <code>registrar_name</code></td></tr>
          <tr><td>管理密码</td><td><code>管理密码</code> &nbsp; <code>admin_password</code></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="csv_import">
    <div class="form-card mb-3">
      <div class="section-title">选择 CSV 文件</div>
      <div class="row g-3 align-items-end">
        <div class="col-md-7">
          <label class="form-label">CSV 文件</label>
          <input type="file" name="csvfile" class="form-control" accept=".csv,text/csv" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">文件编码</label>
          <select name="csv_encoding" class="form-select">
            <option value="UTF-8">UTF-8（默认）</option>
            <option value="GBK">GBK / GB2312（中文 Excel）</option>
            <option value="GB18030">GB18030</option>
          </select>
        </div>
      </div>
    </div>
    <?php include __DIR__ . '/_import_settings.php'; ?>
    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>提交后台处理</button>
  </form>
</div>

<script>
function switchTab(name) {
  ['paste','csv'].forEach(function(t) {
    document.getElementById('tab-' + t).style.display = t === name ? '' : 'none';
    document.querySelectorAll('[data-tab="' + t + '"]').forEach(function(el) {
      el.classList.toggle('active', t === name);
    });
  });
}
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
