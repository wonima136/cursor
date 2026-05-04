<?php
$pageTitle = '编辑域名';
require_once dirname(__DIR__) . '/components/header.php';

$id     = (int)($_GET['id'] ?? 0);
$domain = getDomainInfo($id);
if (!$domain) { flash('error', '域名不存在'); redirect('/admin/index.php'); }

$master          = getMasterDB();
$registrars      = db_all($master, "SELECT * FROM registrars ORDER BY name");
$accounts        = db_all($master, "SELECT a.*, r.name AS registrar_name FROM accounts a LEFT JOIN registrars r ON r.id=a.registrar_id ORDER BY r.name, a.username");
$allTags         = db_all($master, "SELECT * FROM tags ORDER BY sort_order, name");
$customFields    = getCustomFields();
$distinctStatus  = array_column(db_all($master, "SELECT DISTINCT status FROM domains WHERE status!='' ORDER BY status"), 'status');
$distinctIcp     = array_column(db_all($master, "SELECT DISTINCT icp_type FROM domains WHERE icp_type!='' ORDER BY icp_type"), 'icp_type');
$distinctGroup   = array_column(db_all($master, "SELECT DISTINCT group_name FROM domains WHERE group_name!='' ORDER BY group_name"), 'group_name');
$selectedTags = array_column($domain['tags'], 'id');
$errors       = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldExpire = $domain['expire_date'];
    $oldStatus = $domain['status'];
    $oldDns    = $domain['dns_servers'];
    $oldIcp    = $domain['icp_type'];

    $data = [
        'domain'         => trim($_POST['domain'] ?? ''),
        'registrar_id'   => (int)($_POST['registrar_id'] ?? 0) ?: null,
        'account_id'     => (int)($_POST['account_id'] ?? 0) ?: null,
        'register_date'  => $_POST['register_date'] ?? '',
        'expire_date'    => $_POST['expire_date'] ?? '',
        'status'         => $_POST['status'] ?? 'normal',
        'icp_type'       => $_POST['icp_type'] ?? 'none',
        'icp_number'     => trim($_POST['icp_number'] ?? ''),
        'dns_servers'    => trim($_POST['dns_servers'] ?? ''),
        'group_name'     => trim($_POST['group_name'] ?? ''),
        'admin_password' => trim($_POST['admin_password'] ?? ''),
        'updated_at'     => date('Y-m-d H:i:s'),
    ];

    if (!$data['domain']) $errors[] = '域名不能为空';
    if ($data['domain'] && db_one($master, "SELECT id FROM domains WHERE domain=? AND id!=?", [$data['domain'], $id])) {
        $errors[] = '域名已存在';
    }

    if (!$errors) {
        updateDomain($id, $data);
        $tagIds = array_values(array_filter(array_map('intval', (array)($_POST['tags'] ?? []))));
        setDomainTags($id, $tagIds);

        // 保存自定义字段
        $cfValues = [];
        foreach ($customFields as $cf) {
            $val = $_POST['cf_' . $cf['name']] ?? '';
            $cfValues[$cf['name']] = trim((string)$val);
        }
        if ($cfValues) updateDomainCustomData($id, $cfValues);

        if ($data['expire_date'] !== $oldExpire)
            addHistory($id, 'renewal', "过期时间更新：{$oldExpire} → {$data['expire_date']}");
        if ($data['status'] !== $oldStatus)
            addHistory($id, 'status_change', '状态变更：'.(STATUS_LABELS[$oldStatus]['label']??$oldStatus).' → '.(STATUS_LABELS[$data['status']]['label']??$data['status']));
        if ($data['dns_servers'] !== $oldDns)
            addHistory($id, 'dns_change', "DNS变更：{$oldDns} → {$data['dns_servers']}");
        if ($data['icp_type'] !== $oldIcp)
            addHistory($id, 'icp_change', '备案类型：'.(ICP_LABELS[$oldIcp]['label']??$oldIcp).' → '.(ICP_LABELS[$data['icp_type']]['label']??$data['icp_type']));
        if ($_POST['note'] ?? '') addHistory($id, 'note', $_POST['note']);

        flash('success', "域名 {$data['domain']} 更新成功");
        redirect('/admin/detail.php?id=' . $id);
    }
    $domain = array_merge($domain, $data);
    $selectedTags = array_values(array_filter(array_map('intval', (array)($_POST['tags'] ?? []))));
}
?>
<div class="row"><div class="col-xl-8">
  <?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
  <form method="POST">
    <div class="form-card mb-3">
      <div class="section-title">基本信息</div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">域名</label><input type="text" name="domain" class="form-control" value="<?= h($domain['domain']) ?>"></div>
        <div class="col-md-6">
          <label class="form-label">分组</label>
          <input type="text" name="group_name" class="form-control" list="dl_group"
                 value="<?= h($domain['group_name']) ?>" placeholder="输入或选择分组...">
          <datalist id="dl_group">
            <?php foreach ($distinctGroup as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div class="col-md-6"><label class="form-label">注册时间</label><input type="date" name="register_date" class="form-control" value="<?= h($domain['register_date']) ?>"></div>
        <div class="col-md-6"><label class="form-label">过期时间</label><input type="date" name="expire_date" class="form-control" value="<?= h($domain['expire_date']) ?>"></div>
        <div class="col-md-4">
          <label class="form-label">状态</label>
          <input type="text" name="status" class="form-control" list="dl_status"
                 value="<?= h($domain['status']) ?>" placeholder="输入状态值...">
          <datalist id="dl_status">
            <?php foreach ($distinctStatus as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div class="col-md-4">
          <label class="form-label">备案类型</label>
          <input type="text" name="icp_type" class="form-control" list="dl_icp"
                 value="<?= h($domain['icp_type']) ?>" placeholder="输入备案值...">
          <datalist id="dl_icp">
            <?php foreach ($distinctIcp as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div class="col-md-4"><label class="form-label">备案号</label><input type="text" name="icp_number" class="form-control" value="<?= h($domain['icp_number']) ?>"></div>
      </div>
    </div>
    <div class="form-card mb-3">
      <div class="section-title">注册商 & 账号</div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">注册商</label>
          <select name="registrar_id" class="form-select"><option value="">-- 选择 --</option>
            <?php foreach($registrars as $r): ?><option value="<?= $r['id'] ?>" <?= $domain['registrar_id']==$r['id']?'selected':'' ?>><?= h($r['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-6"><label class="form-label">账号</label>
          <select name="account_id" class="form-select"><option value="">-- 选择 --</option>
            <?php foreach($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= $domain['account_id']==$a['id']?'selected':'' ?>><?= h($a['registrar_name'].' / '.$a['username']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-6"><label class="form-label">DNS</label><input type="text" name="dns_servers" class="form-control" value="<?= h($domain['dns_servers']) ?>"></div>
        <div class="col-md-6"><label class="form-label">管理密码</label><input type="text" name="admin_password" class="form-control" value="<?= h($domain['admin_password']) ?>"></div>
      </div>
    </div>
    <div class="form-card mb-3">
      <div class="section-title">标签</div>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach($allTags as $tag): ?>
        <label style="cursor:pointer" class="d-flex align-items-center gap-1">
          <input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>" class="form-check-input" <?= in_array($tag['id'],$selectedTags)?'checked':'' ?>>
          <span class="tag-pill" style="background:<?= h($tag['color']) ?>"><?= h($tag['name']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($customFields): ?>
    <div class="form-card mb-3">
      <div class="section-title">自定义字段</div>
      <?php
        $cfData = getDomainCustomData($id);
        $cfRows = array_chunk($customFields, 2);
      ?>
      <div class="row g-3">
      <?php foreach ($customFields as $cf): ?>
      <?php $cfVal = $cfData[$cf['name']] ?? ''; ?>
      <div class="col-md-6">
        <label class="form-label"><?= h($cf['label']) ?></label>
        <?php if ($cf['field_type'] === 'select'): ?>
          <?php $opts = json_decode($cf['options'], true) ?: []; ?>
          <select name="cf_<?= h($cf['name']) ?>" class="form-select">
            <option value="">-- 不设置 --</option>
            <?php foreach ($opts as $opt): ?>
            <option value="<?= h($opt) ?>" <?= $cfVal === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        <?php elseif ($cf['field_type'] === 'textarea'): ?>
          <textarea name="cf_<?= h($cf['name']) ?>" class="form-control" rows="3"><?= h($cfVal) ?></textarea>
        <?php elseif ($cf['field_type'] === 'date'): ?>
          <input type="date" name="cf_<?= h($cf['name']) ?>" class="form-control" value="<?= h($cfVal) ?>">
        <?php elseif ($cf['field_type'] === 'number'): ?>
          <input type="number" name="cf_<?= h($cf['name']) ?>" class="form-control" value="<?= h($cfVal) ?>">
        <?php else: ?>
          <input type="text" name="cf_<?= h($cf['name']) ?>" class="form-control" value="<?= h($cfVal) ?>">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="form-card mb-3">
      <div class="section-title">添加备注（保留历史）</div>
      <textarea name="note" class="form-control" rows="3" placeholder="本次操作说明，留空不记录..."></textarea>
    </div>
    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary">保存更改</button>
      <a href="/admin/detail.php?id=<?= $id ?>" class="btn btn-outline-secondary">取消</a>
    </div>
  </form>
</div></div>
<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
