<?php
$pageTitle = '字段管理';
require_once dirname(__DIR__) . '/components/header.php';

$master = getMasterDB();
$action = $_GET['action'] ?? '';
$fid    = (int)($_GET['id'] ?? 0);

// ── 删除内置字段 ─────────────────────────────────────────────
if ($action === 'delete_builtin') {
    $name = trim($_GET['name'] ?? '');
    if ($name && $name !== 'domain') {
        $cfg = _readFieldConfig();
        $cfg[$name] = array_merge($cfg[$name] ?? [], ['deleted' => true, 'show_in_list' => false]);
        file_put_contents(DATA_DIR . '/field_config.json',
            json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    redirect('/settings/custom_fields.php?msg=builtin_deleted');
}

// ── 恢复内置字段 ─────────────────────────────────────────────
if ($action === 'restore_builtin') {
    $name = trim($_GET['name'] ?? '');
    if ($name) {
        $cfg = _readFieldConfig();
        unset($cfg[$name]['deleted']);
        file_put_contents(DATA_DIR . '/field_config.json',
            json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    redirect('/settings/custom_fields.php?msg=builtin_restored');
}

// ── 保存显示设置（所有字段）──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_visibility') {
    // 内置字段更新 JSON 配置
    $builtinFields = getBuiltinFields();
    $builtinUpdates = [];
    foreach ($builtinFields as $name => $bf) {
        $builtinUpdates[$name] = [
            'label'        => trim($_POST['label'][$name] ?? $bf['label']),
            'show_in_list' => !empty($_POST['show'][$name]),
            'sort_order'   => (int)($_POST['sort'][$name] ?? $bf['sort_order']),
        ];
    }
    saveBuiltinFieldConfig($builtinUpdates);

    // 自定义字段更新数据库
    $customFields = getCustomFields();
    foreach ($customFields as $cf) {
        db_update($master, 'custom_fields', [
            'show_in_list' => !empty($_POST['show']['cf_' . $cf['id']]) ? 1 : 0,
            'sort_order'   => (int)($_POST['sort']['cf_' . $cf['id']] ?? $cf['sort_order']),
        ], 'id=?', [$cf['id']]);
    }

    flash('success', '字段显示设置已保存');
    redirect('/settings/custom_fields.php');
}

// ── 自定义字段删除 ───────────────────────────────────────────
if ($action === 'delete' && $fid) {
    $f = db_one($master, "SELECT name FROM custom_fields WHERE id=?", [$fid]);
    if ($f) {
        $all = db_all($master, "SELECT id, custom_data FROM domains WHERE custom_data != '{}'");
        foreach ($all as $d) {
            $cd = json_decode($d['custom_data'], true) ?: [];
            if (array_key_exists($f['name'], $cd)) {
                unset($cd[$f['name']]);
                db_update($master, 'domains',
                    ['custom_data' => json_encode($cd, JSON_UNESCAPED_UNICODE)],
                    'id=?', [$d['id']]);
            }
        }
        db_delete($master, 'custom_fields', 'id=?', [$fid]);
    }
    redirect('/settings/custom_fields.php?msg=deleted');
}

// ── 自定义字段保存（新增/编辑）──────────────────────────────
$errors  = [];
$editRow = null;
if ($action === 'edit' && $fid) {
    $editRow = db_one($master, "SELECT * FROM custom_fields WHERE id=?", [$fid]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['_action'])) {
    $label      = trim($_POST['label_new'] ?? '');
    $fieldType  = $_POST['field_type'] ?? 'text';
    $showInList = (int)(!empty($_POST['show_in_list']));
    $sortOrder  = (int)($_POST['sort_order'] ?? 0);
    $postId     = (int)($_POST['id'] ?? 0);

    $rawOptions = trim($_POST['options_raw'] ?? '');
    $options    = [];
    if ($rawOptions) {
        foreach (array_filter(array_map('trim', explode("\n", $rawOptions))) as $opt) {
            if ($opt !== '') $options[] = $opt;
        }
    }

    if (!$label) $errors[] = '字段名称不能为空';
    if (!in_array($fieldType, ['text', 'select', 'date', 'number', 'textarea'])) {
        $errors[] = '无效字段类型';
    }

    if (!$errors) {
        $name = $postId
            ? (db_one($master, "SELECT name FROM custom_fields WHERE id=?", [$postId])['name'] ?? cfSlug($label))
            : cfSlug($label);
        if (!$postId) {
            $base = $name; $i = 2;
            while (db_one($master, "SELECT id FROM custom_fields WHERE name=?", [$name])) {
                $name = $base . '_' . $i++;
            }
        }
        $data = [
            'name'         => $name,
            'label'        => $label,
            'field_type'   => $fieldType,
            'options'      => json_encode($options, JSON_UNESCAPED_UNICODE),
            'sort_order'   => $sortOrder,
            'show_in_list' => $showInList,
        ];
        if ($postId) {
            db_update($master, 'custom_fields', $data, 'id=?', [$postId]);
            flash('success', '字段已更新');
        } else {
            db_insert($master, 'custom_fields', $data);
            flash('success', '字段已创建');
        }
        redirect('/settings/custom_fields.php');
    }
}

$builtinFields  = getBuiltinFields();
$customFields   = getCustomFields();
$deletedBuiltin = getDeletedBuiltinFields();
$typeMap = [
    'text'     => '单行文本',
    'textarea' => '多行文本',
    'select'   => '下拉选择',
    'date'     => '日期',
    'number'   => '数字',
];
$msg = $_GET['msg'] ?? getFlash('success');
?>

<div class="row g-3">
  <!-- 左列：全字段列表 -->
  <div class="col-xl-7">
    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible py-2">
      <?php
        if ($msg === 'deleted') echo '字段已删除，域名数据已同步清除';
        elseif ($msg === 'builtin_deleted') echo '字段已删除，可在下方恢复';
        elseif ($msg === 'builtin_restored') echo '字段已恢复';
        else echo h($msg);
      ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="_action" value="save_visibility">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
          <span class="fw-semibold">全部字段（<?= count($builtinFields) + count($customFields) ?> 个）</span>
          <button class="btn btn-primary btn-sm" type="button" onclick="showForm()">
            <i class="bi bi-plus-lg"></i> 新增字段
          </button>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>显示名称</th>
                <th>标识</th>
                <th>类型</th>
                <th class="text-center">列表显示</th>
                <th class="text-center" style="width:80px">排序</th>
                <th class="text-end" style="width:80px">操作</th>
              </tr>
            </thead>
            <tbody>

              <!-- 域名（唯一固定列）-->
              <tr class="table-light">
                <td class="fw-semibold">
                  域名
                  <span class="badge bg-dark-subtle text-dark border ms-1 small">固定</span>
                </td>
                <td><code class="small">domain</code></td>
                <td><span class="text-muted small">—</span></td>
                <td class="text-center"><i class="bi bi-lock text-muted"></i></td>
                <td class="text-center"><span class="text-muted">—</span></td>
                <td></td>
              </tr>

              <!-- 自动探测的内置字段 -->
              <?php foreach ($builtinFields as $bf): ?>
              <tr>
                <td>
                  <input type="text" name="label[<?= h($bf['name']) ?>]"
                         class="form-control form-control-sm border-0 bg-transparent px-0 fw-semibold"
                         style="min-width:80px"
                         value="<?= h($bf['label']) ?>">
                </td>
                <td><code class="small text-muted"><?= h($bf['name']) ?></code></td>
                <td><span class="text-muted small">内置</span></td>
                <td class="text-center">
                  <input type="checkbox" class="form-check-input"
                         name="show[<?= h($bf['name']) ?>]" value="1"
                         <?= $bf['show_in_list'] ? 'checked' : '' ?>>
                </td>
                <td>
                  <input type="number" name="sort[<?= h($bf['name']) ?>]"
                         class="form-control form-control-sm text-center"
                         value="<?= (int)$bf['sort_order'] ?>" min="0" max="999">
                </td>
                <td class="text-end">
                  <a href="?action=delete_builtin&name=<?= urlencode($bf['name']) ?>"
                     class="btn btn-sm btn-outline-danger py-0"
                     onclick="return confirm('确定删除字段「<?= h(addslashes($bf['label'])) ?>」？\n删除后不再显示，可随时恢复。')">
                    <i class="bi bi-trash"></i>
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>

              <!-- 自定义字段 -->
              <?php foreach ($customFields as $cf): ?>
              <tr>
                <td class="fw-semibold"><?= h($cf['label']) ?></td>
                <td><code class="small"><?= h($cf['name']) ?></code></td>
                <td><span class="text-muted small"><?= h($typeMap[$cf['field_type']] ?? $cf['field_type']) ?></span></td>
                <td class="text-center">
                  <input type="checkbox" class="form-check-input"
                         name="show[cf_<?= $cf['id'] ?>]" value="1"
                         <?= $cf['show_in_list'] ? 'checked' : '' ?>>
                </td>
                <td>
                  <input type="number" name="sort[cf_<?= $cf['id'] ?>]"
                         class="form-control form-control-sm text-center"
                         value="<?= (int)$cf['sort_order'] ?>" min="0" max="999">
                </td>
                <td class="text-end">
                  <?php
                    $cfOpts = $cf['options'] ?? '[]';
                    if (!$cfOpts || $cfOpts === 'null') $cfOpts = '[]';
                  ?>
                  <button type="button" class="btn btn-sm btn-outline-primary py-0"
                          onclick="loadEdit(<?= $cf['id'] ?>,'<?= h(addslashes($cf['label'])) ?>','<?= $cf['field_type'] ?>','<?= $cf['show_in_list'] ?>','<?= $cf['sort_order'] ?>',<?= $cfOpts ?>)">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-danger py-0"
                          onclick="confirmDelete(<?= $cf['id'] ?>,'<?= h(addslashes($cf['label'])) ?>')">
                    <i class="bi bi-trash"></i>
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>

            </tbody>
          </table>
        </div>
        <div class="px-3 py-2 border-top">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-check-lg me-1"></i>保存显示设置
          </button>
          <span class="text-muted small ms-2">支持修改内置字段显示名称、显示开关和排序</span>
        </div>
      </div>
    </form>

    <?php if ($deletedBuiltin): ?>
    <div class="card shadow-sm border-0 mt-3">
      <div class="card-header bg-white py-2">
        <span class="fw-semibold text-muted">已删除的内置字段（可恢复）</span>
      </div>
      <div class="card-body py-2 px-3 d-flex flex-wrap gap-2">
        <?php foreach ($deletedBuiltin as $df): ?>
        <a href="?action=restore_builtin&name=<?= urlencode($df['name']) ?>"
           class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrow-counterclockwise me-1"></i>
          <?= h($df['label'] ?? $df['name']) ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="mt-3 p-3 bg-light rounded small text-muted">
      <i class="bi bi-info-circle me-1"></i>
      自定义字段删除后数据同步清除，不可恢复。内置字段删除后仅隐藏（数据库列保留），可在上方恢复区还原。
    </div>
  </div>

  <!-- 右列：新增/编辑自定义字段表单 -->
  <div class="col-xl-5" id="formPanel" style="display:none">
    <div class="form-card">
      <div class="section-title" id="formTitle">新增自定义字段</div>

      <?php if ($errors): ?>
      <div class="alert alert-danger py-2 mb-3 small">
        <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="POST" id="cfForm">
        <input type="hidden" name="id" id="f_id" value="0">

        <div class="mb-3">
          <label class="form-label fw-semibold">字段名称 <span class="text-danger">*</span></label>
          <input type="text" name="label_new" id="f_label" class="form-control"
                 placeholder="例：服务器IP、合同编号…" required>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">字段类型</label>
          <select name="field_type" id="f_type" class="form-select" onchange="onTypeChange(this.value)">
            <?php foreach ($typeMap as $k => $v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3" id="optionsBlock" style="display:none">
          <label class="form-label fw-semibold">下拉选项</label>
          <textarea name="options_raw" id="f_options" class="form-control font-monospace" rows="5"
                    placeholder="每行一个选项"></textarea>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-6">
            <label class="form-label fw-semibold">排序权重</label>
            <input type="number" name="sort_order" id="f_sort" class="form-control" value="0" min="0">
          </div>
          <div class="col-6 d-flex align-items-end pb-1">
            <div class="form-check">
              <input type="checkbox" name="show_in_list" id="f_show" class="form-check-input" value="1">
              <label class="form-check-label fw-semibold" for="f_show">在域名列表中显示</label>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary flex-fill">
            <i class="bi bi-check-lg me-1"></i>保存
          </button>
          <button type="button" class="btn btn-outline-secondary" onclick="hideForm()">取消</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function showForm(edit) {
  document.getElementById('formPanel').style.display = '';
  if (!edit) {
    document.getElementById('formTitle').textContent = '新增自定义字段';
    document.getElementById('cfForm').reset();
    document.getElementById('f_id').value = '0';
    onTypeChange('text');
  }
  document.getElementById('formPanel').scrollIntoView({behavior:'smooth'});
}
function hideForm() { document.getElementById('formPanel').style.display = 'none'; }
function onTypeChange(type) {
  document.getElementById('optionsBlock').style.display = type === 'select' ? '' : 'none';
}
function loadEdit(id, label, type, showInList, sortOrder, options) {
  document.getElementById('f_id').value     = id;
  document.getElementById('f_label').value  = label;
  document.getElementById('f_type').value   = type;
  document.getElementById('f_sort').value   = sortOrder;
  document.getElementById('f_show').checked = showInList == '1';
  var opts = Array.isArray(options) ? options : [];
  document.getElementById('f_options').value = opts.join('\n');
  document.getElementById('formTitle').textContent = '编辑字段：' + label;
  onTypeChange(type);
  showForm(true);
}
function confirmDelete(id, label) {
  if (confirm('确定删除字段「' + label + '」？\n所有域名中该字段的值也将被清除，此操作不可恢复。')) {
    location.href = '?action=delete&id=' + id;
  }
}
<?php if ($action === 'edit' && $editRow): ?>
<?php $erOpts = $editRow['options'] ?? '[]'; if (!$erOpts || $erOpts === 'null') $erOpts = '[]'; ?>
loadEdit(
  <?= $editRow['id'] ?>,
  '<?= h(addslashes($editRow['label'])) ?>',
  '<?= $editRow['field_type'] ?>',
  '<?= $editRow['show_in_list'] ?>',
  '<?= $editRow['sort_order'] ?>',
  <?= $erOpts ?>
);
<?php endif; ?>
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
