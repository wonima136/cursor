<?php
$pageTitle = '批量修改字段';
require_once dirname(__DIR__) . '/components/header.php';

$master          = getMasterDB();
$customFields    = getCustomFields();
$distinctStatus  = array_column(db_all($master, "SELECT DISTINCT status FROM domains WHERE status!='' ORDER BY status"), 'status');
$distinctIcp     = array_column(db_all($master, "SELECT DISTINCT icp_type FROM domains WHERE icp_type!='' ORDER BY icp_type"), 'icp_type');
$distinctDns     = array_column(db_all($master, "SELECT DISTINCT dns_servers FROM domains WHERE dns_servers!='' ORDER BY dns_servers"), 'dns_servers');
$distinctGroup   = array_column(db_all($master, "SELECT DISTINCT group_name FROM domains WHERE group_name!='' ORDER BY group_name"), 'group_name');
$distinctReg     = array_column(db_all($master, "SELECT DISTINCT name FROM registrars ORDER BY name"), 'name');
$distinctAcc     = db_all($master, "SELECT a.username, COALESCE(r.name,'') AS rname FROM accounts a LEFT JOIN registrars r ON r.id=a.registrar_id ORDER BY r.name, a.username") ?: [];

// 自定义文本字段：从数据库中提取已有值作为 datalist 补全
$cfDistinct = [];
if ($customFields) {
    $textCfs = array_filter($customFields, function($cf) { return $cf['field_type'] === 'text'; });
    if ($textCfs) {
        $allCd = db_all($master, "SELECT custom_data FROM domains WHERE custom_data NOT IN ('{}','') LIMIT 5000") ?: [];
        foreach ($textCfs as $cf) {
            $vals = [];
            foreach ($allCd as $row) {
                $cd = json_decode($row['custom_data'] ?? '{}', true) ?: [];
                $v  = trim($cd[$cf['name']] ?? '');
                if ($v !== '') $vals[$v] = true;
                if (count($vals) >= 100) break;
            }
            $cfDistinct[$cf['name']] = array_keys($vals);
        }
    }
}
?>

<style>
.field-row { display:none; }
.field-row.show { display:flex; }
.field-toggle:checked ~ .field-row { display:flex; }
.field-block { background:#f8f9fa; border-radius:8px; padding:14px 16px; margin-bottom:10px; }
.field-block label.toggle-label { font-weight:500; cursor:pointer; user-select:none; }
</style>

<div class="row g-3">
  <!-- 左列：域名列表 -->
  <div class="col-xl-4">
    <div class="form-card h-100">
      <div class="section-title">输入域名列表</div>
      <p class="small text-muted mb-2">每行一个域名，系统会查找已存在的域名并批量修改</p>
      <textarea id="domainList" class="form-control font-monospace" rows="22"
                placeholder="example.com&#10;test.cn&#10;hello.net"></textarea>
      <div class="mt-2 text-muted small" id="domainCount">0 行</div>
    </div>
  </div>

  <!-- 右列：字段设置 -->
  <div class="col-xl-8">
    <form id="updateForm">
      <div class="form-card mb-3">
        <div class="section-title">选择要修改的字段</div>
        <p class="small text-muted mb-3">勾选字段后设置目标值，未勾选的字段不会被修改</p>

        <!-- 状态 -->
        <div class="field-block">
          <div class="d-flex align-items-center gap-3">
            <input type="checkbox" class="form-check-input field-check" id="chk_status"
                   onchange="toggleField('status_row', this)">
            <label class="form-label mb-0 toggle-label" for="chk_status">
              <i class="bi bi-toggle-on me-1 text-success"></i>状态
            </label>
            <div id="status_row" class="field-row flex-grow-1" style="display:none">
              <input type="text" name="status" class="form-control form-control-sm"
                     list="dl_bu_status" placeholder="输入状态值...">
              <datalist id="dl_bu_status">
                <?php foreach ($distinctStatus as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?>
              </datalist>
            </div>
          </div>
        </div>

        <!-- 备案类型 -->
        <div class="field-block">
          <div class="d-flex align-items-center gap-3">
            <input type="checkbox" class="form-check-input field-check" id="chk_icp"
                   onchange="toggleField('icp_row', this)">
            <label class="form-label mb-0 toggle-label" for="chk_icp">
              <i class="bi bi-shield-check me-1 text-info"></i>备案类型
            </label>
            <div id="icp_row" class="field-row flex-grow-1" style="display:none">
              <input type="text" name="icp_type" class="form-control form-control-sm"
                     list="dl_bu_icp" placeholder="输入备案值...">
              <datalist id="dl_bu_icp">
                <?php foreach ($distinctIcp as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?>
              </datalist>
            </div>
          </div>
        </div>

        <!-- 注册商 -->
        <div class="field-block">
          <div class="d-flex align-items-center gap-3">
            <input type="checkbox" class="form-check-input field-check" id="chk_registrar"
                   onchange="toggleField('registrar_row', this)">
            <label class="form-label mb-0 toggle-label" for="chk_registrar">
              <i class="bi bi-building me-1 text-warning"></i>注册商
            </label>
            <div id="registrar_row" class="field-row flex-grow-1" style="display:none">
              <input type="text" name="registrar_name" class="form-control form-control-sm"
                     list="dl_bu_registrar" placeholder="输入注册商名称（留空表示清除）">
              <datalist id="dl_bu_registrar">
                <?php foreach ($distinctReg as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?>
              </datalist>
            </div>
          </div>
        </div>

        <!-- 账号 -->
        <div class="field-block">
          <div class="d-flex align-items-center gap-3">
            <input type="checkbox" class="form-check-input field-check" id="chk_account"
                   onchange="toggleField('account_row', this)">
            <label class="form-label mb-0 toggle-label" for="chk_account">
              <i class="bi bi-person-circle me-1 text-secondary"></i>账号
            </label>
            <div id="account_row" class="field-row flex-grow-1" style="display:none">
              <input type="text" name="account_name" class="form-control form-control-sm"
                     list="dl_bu_account" placeholder="输入账号名称（留空表示清除）">
              <datalist id="dl_bu_account">
                <?php foreach ($distinctAcc as $a): ?>
                <option value="<?= h(($a['rname'] ? $a['rname'].' / ' : '').$a['username']) ?>">
                <?php endforeach; ?>
              </datalist>
            </div>
          </div>
        </div>

        <!-- 分组 -->
        <div class="field-block">
          <div class="d-flex align-items-center gap-3">
            <input type="checkbox" class="form-check-input field-check" id="chk_group"
                   onchange="toggleField('group_row', this)">
            <label class="form-label mb-0 toggle-label" for="chk_group">
              <i class="bi bi-folder me-1 text-secondary"></i>分组
            </label>
            <div id="group_row" class="field-row flex-grow-1" style="display:none">
              <input type="text" name="group_name" class="form-control form-control-sm"
                     list="dl_bu_group" placeholder="输入分组名称（留空表示清除）">
              <datalist id="dl_bu_group">
                <?php foreach ($distinctGroup as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?>
              </datalist>
            </div>
          </div>
        </div>

        <!-- DNS服务器 -->
        <div class="field-block">
          <div class="d-flex align-items-center gap-3">
            <input type="checkbox" class="form-check-input field-check" id="chk_dns"
                   onchange="toggleField('dns_row', this)">
            <label class="form-label mb-0 toggle-label" for="chk_dns">
              <i class="bi bi-diagram-3 me-1 text-danger"></i>DNS服务器
            </label>
            <div id="dns_row" class="field-row flex-grow-1" style="display:none">
              <input type="text" name="dns_servers" class="form-control form-control-sm"
                     list="dl_bu_dns" placeholder="ns1.example.com,ns2.example.com（逗号分隔）">
              <datalist id="dl_bu_dns">
                <?php foreach ($distinctDns as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?>
              </datalist>
            </div>
          </div>
        </div>

        <!-- 备注（写入历史记录） -->
        <div class="field-block">
          <div class="d-flex align-items-center gap-3 mb-0">
            <input type="checkbox" class="form-check-input field-check" id="chk_note"
                   onchange="toggleField('note_row', this)">
            <label class="form-label mb-0 toggle-label" for="chk_note">
              <i class="bi bi-chat-text me-1 text-primary"></i>添加备注
              <small class="text-muted fw-normal">（写入档案历史）</small>
            </label>
          </div>
          <div id="note_row" class="field-row mt-2 flex-column gap-2" style="display:none">
            <div class="d-flex gap-2">
              <select name="note_type" class="form-select form-select-sm" style="width:130px">
                <?php foreach (ACTION_LABELS as $k => $v): ?>
                <option value="<?= $k ?>"><?= $v['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <textarea name="note_content" class="form-control form-control-sm" rows="3"
                      placeholder="输入备注内容，将写入每个匹配域名的历史档案"></textarea>
          </div>
        </div>
      </div>

        <?php if ($customFields): ?>
        <!-- 自定义字段 -->
        <?php foreach ($customFields as $cf): ?>
        <div class="field-block">
          <div class="d-flex align-items-center gap-3 mb-0">
            <input type="checkbox" class="form-check-input field-check cf-check"
                   id="chk_cf_<?= h($cf['name']) ?>"
                   data-cf-name="<?= h($cf['name']) ?>"
                   onchange="toggleField('cf_row_<?= h($cf['name']) ?>', this)">
            <label class="form-label mb-0 toggle-label" for="chk_cf_<?= h($cf['name']) ?>">
              <i class="bi bi-sliders me-1 text-secondary"></i><?= h($cf['label']) ?>
              <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-1 small">自定义</span>
            </label>
          </div>
          <div id="cf_row_<?= h($cf['name']) ?>" class="field-row mt-2 flex-grow-1" style="display:none">
            <?php if ($cf['field_type'] === 'select'): ?>
              <?php $opts = json_decode($cf['options'], true) ?: []; ?>
              <select name="cf_val_<?= h($cf['name']) ?>" class="form-select form-select-sm">
                <option value="">-- 清除该字段 --</option>
                <?php foreach ($opts as $opt): ?>
                <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($cf['field_type'] === 'textarea'): ?>
              <textarea name="cf_val_<?= h($cf['name']) ?>" class="form-control form-control-sm" rows="2"></textarea>
            <?php elseif ($cf['field_type'] === 'date'): ?>
              <input type="date" name="cf_val_<?= h($cf['name']) ?>" class="form-control form-control-sm">
            <?php elseif ($cf['field_type'] === 'number'): ?>
              <input type="number" name="cf_val_<?= h($cf['name']) ?>" class="form-control form-control-sm">
            <?php else: ?>
              <?php $dlId = 'dl_cf_' . preg_replace('/\W+/', '_', $cf['name']); ?>
              <input type="text" name="cf_val_<?= h($cf['name']) ?>" class="form-control form-control-sm"
                     list="<?= $dlId ?>" placeholder="输入值（留空表示清除）">
              <?php if (!empty($cfDistinct[$cf['name']])): ?>
              <datalist id="<?= $dlId ?>">
                <?php foreach ($cfDistinct[$cf['name']] as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?>
              </datalist>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

      <!-- 提交 -->
      <div class="form-card">
        <div id="submitArea">
          <button type="button" class="btn btn-primary w-100" onclick="submitUpdate()">
            <i class="bi bi-lightning-charge me-1"></i>执行批量修改
          </button>
        </div>
        <div id="submitWarning" class="text-muted small mt-2 text-center" style="display:none"></div>
      </div>
    </form>
  </div>
</div>

<script>
function toggleField(rowId, chk) {
  var row = document.getElementById(rowId);
  if (row) row.style.display = chk.checked ? 'flex' : 'none';
}

// 更新行数统计
document.getElementById('domainList').addEventListener('input', function() {
  var lines = this.value.split('\n').filter(function(l){ return l.trim(); });
  document.getElementById('domainCount').textContent = lines.length + ' 行';
});

function submitUpdate() {
  var domainText = document.getElementById('domainList').value.trim();
  if (!domainText) { alert('请先输入域名列表'); return; }

  var checked = document.querySelectorAll('.field-check:checked');
  if (!checked.length) { alert('请至少勾选一个要修改的字段'); return; }

  var domains = domainText.split('\n').map(function(l){ return l.trim(); })
                           .filter(function(l){ return l; });
  if (!domains.length) { alert('域名列表为空'); return; }

  var form    = document.getElementById('updateForm');
  var data    = new FormData(form);
  var payload = { domains: domains, fields: {} };

  // 收集勾选字段
  checked.forEach(function(chk) {
    var fieldId = chk.id.replace('chk_', '');
    switch (fieldId) {
      case 'status':
        payload.fields.status = data.get('status');
        break;
      case 'icp':
        payload.fields.icp_type = data.get('icp_type');
        break;
      case 'registrar':
        payload.fields.registrar_name = data.get('registrar_name') || '';
        break;
      case 'account':
        payload.fields.account_name = data.get('account_name') || '';
        break;
      case 'group':
        payload.fields.group_name = data.get('group_name') || '';
        break;
      case 'dns':
        payload.fields.dns_servers = data.get('dns_servers') || '';
        break;
      case 'note':
        payload.fields.note = {
          type: data.get('note_type') || 'note',
          content: data.get('note_content') || ''
        };
        break;
    }
  });

  // 收集自定义字段
  document.querySelectorAll('.cf-check:checked').forEach(function(chk) {
    var cfName = chk.dataset.cfName;
    var val    = data.get('cf_val_' + cfName) || '';
    if (!payload.fields.custom_data) payload.fields.custom_data = {};
    payload.fields.custom_data[cfName] = val;
  });

  var warn = document.getElementById('submitWarning');
  warn.style.display = 'block';
  warn.textContent = '正在提交 ' + domains.length + ' 个域名…';

  fetch('/batch/api/update_job.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok && data.job_id) {
      location.href = '/jobs/progress.php?id=' + encodeURIComponent(data.job_id);
    } else {
      warn.textContent = '提交失败：' + (data.msg || '未知错误');
    }
  })
  .catch(function(e) { warn.textContent = '请求失败：' + e.message; });
}
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
