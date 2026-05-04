<?php
$pageTitle = '添加域名';
require_once dirname(__DIR__) . '/components/header.php';

$master     = getMasterDB();
$registrars = db_all($master, "SELECT * FROM registrars ORDER BY name");
$accounts   = db_all($master, "SELECT a.*, r.name AS registrar_name FROM accounts a LEFT JOIN registrars r ON r.id=a.registrar_id ORDER BY r.name, a.username");
$allTags    = db_all($master, "SELECT * FROM tags ORDER BY sort_order, name");

$errors = [];
$data   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    ];

    if (!$data['domain']) $errors[] = '域名不能为空';
    if ($data['domain'] && db_one($master, "SELECT id FROM domains WHERE domain=?", [$data['domain']])) {
        $errors[] = '域名已存在';
    }

    if (!$errors) {
        $id     = createDomain($data);
        $tagIds = array_values(array_filter(array_map('intval', (array)($_POST['tags'] ?? []))));
        if ($tagIds) setDomainTags($id, $tagIds);
        if ($_POST['note'] ?? '') addHistory($id, 'note', $_POST['note']);

        flash('success', "域名 {$data['domain']} 添加成功");
        redirect('/domains/');
    }
}
?>
<div class="row"><div class="col-xl-8">
  <?php if ($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
  <form method="POST">
    <div class="form-card mb-3">
      <div class="section-title">基本信息</div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">域名 <span class="text-danger">*</span></label>
          <input type="text" name="domain" class="form-control" value="<?= h($data['domain'] ?? '') ?>" placeholder="example.com"></div>
        <div class="col-md-6"><label class="form-label">域名分组</label>
          <input type="text" name="group_name" class="form-control" value="<?= h($data['group_name'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">注册时间</label>
          <input type="date" name="register_date" class="form-control" value="<?= h($data['register_date'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">过期时间</label>
          <input type="date" name="expire_date" class="form-control" value="<?= h($data['expire_date'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label">域名状态</label>
          <select name="status" class="form-select">
            <?php foreach(STATUS_LABELS as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($data['status']??'normal')===$k?'selected':'' ?>><?= $v['label'] ?></option>
            <?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">备案类型</label>
          <select name="icp_type" class="form-select">
            <?php foreach(ICP_LABELS as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($data['icp_type']??'none')===$k?'selected':'' ?>><?= $v['label'] ?></option>
            <?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">备案号</label>
          <input type="text" name="icp_number" class="form-control" value="<?= h($data['icp_number'] ?? '') ?>"></div>
      </div>
    </div>

    <div class="form-card mb-3">
      <div class="section-title">注册商 & 账号</div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">注册商</label>
          <select name="registrar_id" class="form-select"><option value="">-- 选择 --</option>
            <?php foreach($registrars as $r): ?>
            <option value="<?= $r['id'] ?>" <?= ($data['registrar_id']??'')==$r['id']?'selected':'' ?>><?= h($r['name']) ?></option>
            <?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">账号</label>
          <select name="account_id" class="form-select"><option value="">-- 选择 --</option>
            <?php foreach($accounts as $a): ?>
            <option value="<?= $a['id'] ?>" <?= ($data['account_id']??'')==$a['id']?'selected':'' ?>><?= h($a['registrar_name'].' / '.$a['username']) ?></option>
            <?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">DNS 服务器</label>
          <input type="text" name="dns_servers" class="form-control" value="<?= h($data['dns_servers'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">管理密码</label>
          <input type="text" name="admin_password" class="form-control" value="<?= h($data['admin_password'] ?? '') ?>"></div>
      </div>
    </div>

    <div class="form-card mb-3">
      <div class="section-title">标签</div>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach($allTags as $tag): ?>
        <label style="cursor:pointer" class="d-flex align-items-center gap-1">
          <input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>" class="form-check-input">
          <span class="tag-pill" style="background:<?= h($tag['color']) ?>"><?= h($tag['name']) ?></span>
        </label>
        <?php endforeach; ?>
        <?php if(!$allTags): ?><span class="text-muted small">暂无标签</span><?php endif; ?>
      </div>
    </div>

    <div class="form-card mb-3">
      <div class="section-title">初始备注（可选）</div>
      <textarea name="note" class="form-control" rows="3" placeholder="用途、来源等..."></textarea>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary">保存域名</button>
      <a href="/domains/" class="btn btn-outline-secondary">取消</a>
    </div>
  </form>
</div></div>
<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
