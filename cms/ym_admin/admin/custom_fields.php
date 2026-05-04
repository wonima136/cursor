<?php
$pageTitle = '自定义字段';
require_once dirname(__DIR__) . '/components/header.php';

$master = getMasterDB();
$action = $_GET['action'] ?? '';
$fid    = (int)($_GET['id'] ?? 0);

// ── 删除 ─────────────────────────────────────────────────────
if ($action === 'delete' && $fid) {
    $f = db_one($master, "SELECT name FROM custom_fields WHERE id=?", [$fid]);
    if ($f) {
        // 从所有域名的 custom_data 里移除该字段
        $all = db_all($master, "SELECT id, custom_data FROM domains WHERE custom_data != '{}'");
        foreach ($all as $d) {
            $cd = json_decode($d['custom_data'], true) ?: [];
            if (array_key_exists($f['name'], $cd)) {
                unset($cd[$f['name']]);
                db_update($master, 'domains',
                    ['custom_data' => json_encode($cd, JSON_UNESCAPED_UNICODE)],
                    'id=?', [$d['id']]
                );
            }
        }
        db_delete($master, 'custom_fields', 'id=?', [$fid]);
    }
    redirect('/admin/custom_fields.php?msg=deleted');
}

// ── 保存（新增/编辑） ─────────────────────────────────────────
$errors = [];
$editRow = null;
if ($action === 'edit' && $fid) {
    $editRow = db_one($master, "SELECT * FROM custom_fields WHERE id=?", [$fid]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $label      = trim($_POST['label'] ?? '');
    $fieldType  = $_POST['field_type'] ?? 'text';
    $showInList = (int)(!empty($_POST['show_in_list']));
    $sortOrder  = (int)($_POST['sort_order'] ?? 0);
    $postId     = (int)($_POST['id'] ?? 0);

    // 构建选项列表
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

        // 避免 name 冲突（新增时）
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
        redirect('/admin/custom_fields.php');
    }
}

$fields  = getCustomFields();
$typeMap = [
    'text'     => ['label' => '单行文本', 'icon' => 'bi-input-cursor-text',  'class' => 'primary'],
    'textarea' => ['label' => '多行文本', 'icon' => 'bi-textarea-t',         'class' => 'secondary'],
    'select'   => ['label' => '下拉选择', 'icon' => 'bi-menu-button-wide',   'class' => 'success'],
    'date'     => ['label' => '日期',     'icon' => 'bi-calendar3',          'class' => 'info'],
    'number'   => ['label' => '数字',     'icon' => 'bi-123',                'class' => 'warning'],
];

$msg = $_GET['msg'] ?? getFlash('success');
?>

<div class="row g-3">
  <!-- 左：字段列表 -->
  <div class="col-xl-7">
    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible py-2">
      <?= $msg === 'deleted' ? '字段已删除' : h($msg) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
      <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
        <span class="fw-semibold">已定义字段（<?= count($fields) ?> 个）</span>
        <button class="btn btn-primary btn-sm" onclick="showForm()">
          <i class="bi bi-plus-lg"></i> 新增字段
        </button>
      </div>
      <?php if (!$fields): ?>
      <div class="text-center text-muted py-5">
        <i class="bi bi-layout-three-columns fs-1 d-block mb-2"></i>
        暂无自定义字段，点击右上角新增
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>字段名</th>
              <th>标识（slug）</th>
              <th>类型</th>
              <th class="text-center">列表显示</th>
              <th class="text-center">排序</th>
              <th class="text-end">操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($fields as $f): ?>
          <?php $tm = $typeMap[$f['field_type']] ?? $typeMap['text']; ?>
          <tr>
            <td class="fw-semibold"><?= h($f['label']) ?></td>
            <td><code class="small"><?= h($f['name']) ?></code></td>
            <td>
              <span class="badge bg-<?= $tm['class'] ?>-subtle text-<?= $tm['class'] ?> border border-<?= $tm['class'] ?>-subtle">
                <i class="bi <?= $tm['icon'] ?> me-1"></i><?= $tm['label'] ?>
              </span>
              <?php if ($f['field_type'] === 'select'): ?>
              <?php $opts = json_decode($f['options'], true) ?: []; ?>
              <small class="text-muted ms-1"><?= count($opts) ?> 个选项</small>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?= $f['show_in_list'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?>
            </td>
            <td class="text-center text-muted"><?= $f['sort_order'] ?></td>
            <td class="text-end">
              <a href="?action=edit&id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary" onclick="loadEdit(<?= $f['id'] ?>,'<?= h(addslashes($f['label'])) ?>','<?= $f['field_type'] ?>','<?= $f['show_in_list'] ?>','<?= $f['sort_order'] ?>',<?= h($f['options']) ?>);return false;">
                <i class="bi bi-pencil"></i>
              </a>
              <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?= $f['id'] ?>,'<?= h(addslashes($f['label'])) ?>')">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="mt-3 p-3 bg-light rounded small text-muted">
      <i class="bi bi-info-circle me-1"></i>
      自定义字段的值存储在每个域名的 <code>custom_data</code> 字段中。
      勾选"列表显示"后，字段会出现在域名列表表格末尾列。
      字段删除后，所有域名中对应的值也会被清除。
    </div>
  </div>

  <!-- 右：新增/编辑表单 -->
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
          <input type="text" name="label" id="f_label" class="form-control"
                 placeholder="例：服务器IP、合同编号、到期提醒人..." required>
          <div class="form-text">用户可见的显示名，可以是中文</div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">字段类型</label>
          <select name="field_type" id="f_type" class="form-select" onchange="onTypeChange(this.value)">
            <?php foreach ($typeMap as $k => $tm): ?>
            <option value="<?= $k ?>"><?= $tm['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3" id="optionsBlock" style="display:none">
          <label class="form-label fw-semibold">下拉选项</label>
          <textarea name="options_raw" id="f_options" class="form-control font-monospace" rows="5"
                    placeholder="每行一个选项，例：&#10;待处理&#10;已处理&#10;已归档"></textarea>
          <div class="form-text">每行一个选项值，用户在编辑域名时可以从中选择</div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-6">
            <label class="form-label fw-semibold">排序权重</label>
            <input type="number" name="sort_order" id="f_sort" class="form-control" value="0" min="0">
            <div class="form-text">数字越小越靠前</div>
          </div>
          <div class="col-6 d-flex align-items-end pb-1">
            <div class="form-check">
              <input type="checkbox" name="show_in_list" id="f_show" class="form-check-input" value="1">
              <label class="form-check-label fw-semibold" for="f_show">
                在域名列表中显示此列
              </label>
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
function hideForm() {
  document.getElementById('formPanel').style.display = 'none';
}
function onTypeChange(type) {
  document.getElementById('optionsBlock').style.display = type === 'select' ? '' : 'none';
}
function loadEdit(id, label, type, showInList, sortOrder, options) {
  document.getElementById('f_id').value    = id;
  document.getElementById('f_label').value = label;
  document.getElementById('f_type').value  = type;
  document.getElementById('f_sort').value  = sortOrder;
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

// 若是编辑状态则自动展开表单
<?php if ($action === 'edit' && $editRow): ?>
loadEdit(
  <?= $editRow['id'] ?>,
  '<?= h(addslashes($editRow['label'])) ?>',
  '<?= $editRow['field_type'] ?>',
  '<?= $editRow['show_in_list'] ?>',
  '<?= $editRow['sort_order'] ?>',
  <?= $editRow['options'] ?>
);
<?php endif; ?>
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
