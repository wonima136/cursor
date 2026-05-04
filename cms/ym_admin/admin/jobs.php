<?php
$pageTitle = '后台任务';
require_once dirname(__DIR__) . '/components/header.php';

$master = getMasterDB();
$jobs   = db_all($master,
    "SELECT id, type, status, pid, progress, total, message, result, created_at, updated_at
     FROM jobs ORDER BY created_at DESC LIMIT 100"
);

$typeLabels = [
    'import'       => ['label' => '批量导入',   'icon' => 'bi-cloud-upload',    'class' => 'primary'],
    'renew'        => ['label' => '批量续费',   'icon' => 'bi-arrow-clockwise', 'class' => 'info'],
    'batch_action' => ['label' => '批量操作',   'icon' => 'bi-lightning',       'class' => 'warning'],
    'batch_update' => ['label' => '批量修改字段', 'icon' => 'bi-pencil-square',   'class' => 'secondary'],
    'clear_all'    => ['label' => '清空域名',   'icon' => 'bi-trash3',          'class' => 'danger'],
];
$statusLabels = [
    'pending' => ['label' => '等待中', 'class' => 'secondary', 'icon' => 'bi-clock'],
    'running' => ['label' => '进行中', 'class' => 'primary',   'icon' => 'bi-play-circle'],
    'done'    => ['label' => '已完成', 'class' => 'success',   'icon' => 'bi-check-circle'],
    'failed'  => ['label' => '失败',   'class' => 'danger',    'icon' => 'bi-x-circle'],
];
?>

<div class="container-fluid py-3 px-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h6 class="mb-0 text-muted">共 <?= count($jobs) ?> 条记录（最近100条）</h6>
    <button class="btn btn-sm btn-outline-danger" onclick="deleteAll()">
      <i class="bi bi-trash3"></i> 清空已完成/失败
    </button>
  </div>

  <?php if (!$jobs): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-inbox fs-1 d-block mb-2"></i>暂无任务记录
  </div>
  <?php else: ?>
  <div class="card shadow-sm border-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>类型</th>
            <th>状态</th>
            <th>进度</th>
            <th>消息</th>
            <th>创建时间</th>
            <th>更新时间</th>
            <th class="text-end">操作</th>
          </tr>
        </thead>
        <tbody id="jobTableBody">
        <?php foreach ($jobs as $job): ?>
          <?php
            $tl  = $typeLabels[$job['type']]   ?? ['label' => $job['type'], 'icon' => 'bi-gear', 'class' => 'secondary'];
            $sl  = $statusLabels[$job['status']] ?? ['label' => $job['status'], 'class' => 'secondary', 'icon' => 'bi-question'];
            $pct = $job['total'] > 0 ? round($job['progress'] / $job['total'] * 100) : 0;
            $result = $job['result'] ? json_decode($job['result'], true) : [];
          ?>
          <tr data-job-id="<?= h($job['id']) ?>"
              data-status="<?= $job['status'] ?>"
              data-pid="<?= (int)$job['pid'] ?>">
            <td>
              <span class="badge bg-<?= $tl['class'] ?>-subtle text-<?= $tl['class'] ?> border border-<?= $tl['class'] ?>-subtle">
                <i class="bi <?= $tl['icon'] ?> me-1"></i><?= $tl['label'] ?>
              </span>
            </td>
            <td>
              <span class="badge bg-<?= $sl['class'] ?>">
                <i class="bi <?= $sl['icon'] ?> me-1"></i><?= $sl['label'] ?>
              </span>
            </td>
            <td style="min-width:140px">
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:6px">
                  <div class="progress-bar bg-<?= $job['status'] === 'done' ? 'success' : ($job['status'] === 'failed' ? 'danger' : 'primary') ?>"
                       style="width:<?= $pct ?>%"></div>
                </div>
                <span class="text-muted small" style="white-space:nowrap">
                  <?= $job['progress'] ?>/<?= $job['total'] ?>
                </span>
              </div>
            </td>
            <td class="small text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?php if ($result): ?>
                <?php foreach ($result as $k => $v): ?>
                  <span class="me-2"><?= h($k) ?>: <strong><?= (int)$v ?></strong></span>
                <?php endforeach; ?>
              <?php else: ?>
                <?= h($job['message']) ?>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= h($job['created_at']) ?></td>
            <td class="small text-muted"><?= h($job['updated_at']) ?></td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <?php if (in_array($job['status'], ['pending', 'running'])): ?>
                <button class="btn btn-sm btn-outline-warning" title="终止"
                        onclick="jobAction('<?= h($job['id']) ?>', 'terminate', this)">
                  <i class="bi bi-stop-circle"></i>
                </button>
                <?php endif; ?>
                <a href="/admin/job_progress.php?id=<?= urlencode($job['id']) ?>"
                   class="btn btn-sm btn-outline-primary" title="查看详情">
                  <i class="bi bi-eye"></i>
                </a>
                <button class="btn btn-sm btn-outline-danger" title="删除记录"
                        onclick="jobAction('<?= h($job['id']) ?>', 'delete', this)">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function jobAction(id, action, btn) {
  var labels = { terminate: '确定要终止该任务吗？', delete: '确定删除该任务记录吗？' };
  if (!confirm(labels[action] || '确认操作？')) return;
  btn.disabled = true;

  fetch('/api/job_action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: id, action: action })
  })
  .then(function(r){ return r.json(); })
  .then(function(data){
    if (!data.ok) { alert(data.msg || '操作失败'); btn.disabled = false; return; }
    var row = btn.closest('tr');
    if (action === 'delete') {
      row.remove();
    } else {
      // 刷新状态显示
      location.reload();
    }
  })
  .catch(function(){ alert('请求失败'); btn.disabled = false; });
}

function deleteAll() {
  if (!confirm('确定清空所有已完成/失败的任务记录？')) return;
  fetch('/api/job_action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'delete_finished' })
  })
  .then(function(r){ return r.json(); })
  .then(function(data){
    if (data.ok) location.reload();
    else alert(data.msg || '操作失败');
  });
}

// 自动刷新运行中的任务
(function(){
  var hasRunning = <?= json_encode(count(array_filter($jobs, function($j){ return in_array($j['status'], ['pending','running']); })) > 0) ?>;
  if (!hasRunning) return;
  var timer = setInterval(function(){
    fetch('/api/job_status.php?list=1')
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data.ok || !data.jobs) return;
        var allDone = true;
        data.jobs.forEach(function(job){
          if (job.status === 'running' || job.status === 'pending') allDone = false;
          var row = document.querySelector('tr[data-job-id="' + job.id + '"]');
          if (!row) return;
          // 更新进度条
          var bar = row.querySelector('.progress-bar');
          if (bar) bar.style.width = (job.pct || 0) + '%';
          var txt = row.querySelector('.small.text-muted');
        });
        if (allDone) { clearInterval(timer); location.reload(); }
      }).catch(function(){});
  }, 3000);
})();
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
