<?php
$id = (int)($_GET['id'] ?? 0);
require_once dirname(__DIR__) . '/core/functions.php';

$domain = getDomainInfo($id);
if (!$domain) { header('Location: /admin/index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['note'] ?? '')) {
    addHistory($id, $_POST['action_type'] ?? 'note', trim($_POST['note']));
    flash('success', '备注已添加');
    redirect('/admin/detail.php?id=' . $id);
}

$pageTitle = $domain['domain'] . ' 档案';
require_once dirname(__DIR__) . '/components/header.php';

$history      = getDomainHistory($id);
$customFields = getCustomFields();
$cfData       = $customFields ? getDomainCustomData($id) : [];
$s   = STATUS_LABELS[$domain['status']] ?? ['label' => $domain['status'], 'class' => 'secondary'];
$icp = ICP_LABELS[$domain['icp_type']] ?? ['label' => $domain['icp_type'], 'class' => 'secondary'];
$expClass = getDomainExpireClass($domain['expire_date'] ?? '');
?>
<div class="d-flex justify-content-end mb-3 gap-2">
  <a href="/admin/edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil"></i> 编辑</a>
  <a href="/admin/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> 返回</a>
</div>
<div class="row g-3">
  <div class="col-xl-4">
    <div class="form-card">
      <h5 class="fw-bold mb-2"><?= h($domain['domain']) ?></h5>
      <span class="badge bg-<?= $s['class'] ?>"><?= $s['label'] ?></span>
      <span class="badge bg-<?= $icp['class'] ?> ms-1"><?= $icp['label'] ?></span>
      <?php if($domain['icp_number']): ?><div class="small mt-2"><span class="text-muted">备案号：</span><?= h($domain['icp_number']) ?></div><?php endif; ?>
      <hr class="my-2">
      <div class="row g-2 small">
        <div class="col-6"><div class="text-muted">注册时间</div><div><?= h($domain['register_date']?:'-') ?></div></div>
        <div class="col-6"><div class="text-muted">过期时间</div>
          <div class="<?= $expClass ?>"><?= h($domain['expire_date']?:'-') ?><?= getDomainExpireBadge($domain['expire_date']??'') ?></div></div>
        <div class="col-6"><div class="text-muted">注册商</div><div><?= h($domain['registrar_name']?:'-') ?></div></div>
        <div class="col-6"><div class="text-muted">账号</div><div><?= h($domain['account_name']?:'-') ?></div></div>
        <?php if($domain['group_name']): ?><div class="col-12"><div class="text-muted">分组</div><div><?= h($domain['group_name']) ?></div></div><?php endif; ?>
        <?php if($domain['dns_servers']): ?><div class="col-12"><div class="text-muted">DNS</div><div class="text-break"><?= h($domain['dns_servers']) ?></div></div><?php endif; ?>
        <?php if($domain['admin_password']): ?>
        <div class="col-12"><div class="text-muted">管理密码</div>
          <div><span style="filter:blur(4px);cursor:pointer" onclick="this.style.filter='none'" class="font-monospace"><?= h($domain['admin_password']) ?></span> <small class="text-muted">（点击显示）</small></div></div>
        <?php endif; ?>
      </div>
      <?php if($domain['tags']): ?>
      <hr class="my-2">
      <div class="d-flex flex-wrap gap-1">
        <?php foreach($domain['tags'] as $tag): ?><span class="tag-pill" style="background:<?= h($tag['color']) ?>"><?= h($tag['name']) ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <hr class="my-2">
      <?php if ($customFields): ?>
      <hr class="my-2">
      <div class="row g-2 small">
        <?php foreach ($customFields as $cf): ?>
        <?php $val = $cfData[$cf['name']] ?? ''; ?>
        <?php if ($val !== ''): ?>
        <div class="col-12">
          <div class="text-muted"><?= h($cf['label']) ?></div>
          <div><?= nl2br(h($val)) ?></div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="small text-muted mt-2">
        <div>创建：<?= h($domain['created_at']) ?></div>
        <div>更新：<?= h($domain['updated_at']) ?></div>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <div class="form-card mb-3">
      <div class="section-title">添加档案记录</div>
      <form method="POST">
        <div class="row g-2">
          <div class="col-md-3">
            <select name="action_type" class="form-select form-select-sm">
              <?php foreach(ACTION_LABELS as $k=>$v): ?><option value="<?= $k ?>"><?= $v['label'] ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-9">
            <div class="input-group input-group-sm">
              <input type="text" name="note" class="form-control" placeholder="输入备注内容..." required>
              <button type="submit" class="btn btn-primary">记录</button>
            </div>
          </div>
        </div>
      </form>
    </div>

    <div class="form-card">
      <div class="section-title">历史档案（共 <?= count($history) ?> 条）</div>
      <?php if(!$history): ?><div class="text-center text-muted py-4">暂无记录</div>
      <?php else: ?>
      <div class="timeline">
        <?php foreach($history as $h): ?>
        <?php $act = ACTION_LABELS[$h['action_type']] ?? ACTION_LABELS['note']; ?>
        <div class="timeline-item">
          <div class="timeline-dot"><i class="<?= $act['icon'] ?>"></i></div>
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary"><?= $act['label'] ?></span>
            <span class="timeline-time"><?= $h['created_at'] ?></span>
          </div>
          <div class="timeline-content"><?= nl2br(h($h['content'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
