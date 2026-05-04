<?php
$id = trim($_GET['id'] ?? '');
require_once dirname(__DIR__) . '/core/functions.php';

$job = $id ? db_one(getMasterDB(),
    "SELECT id,type,status,progress,total,message,result FROM jobs WHERE id=?", [$id]
) : null;
if (!$job) { header('Location: /admin/index.php'); exit; }

$typeLabels = [
    'import'       => '批量导入',
    'renew'        => '批量续费',
    'batch_action' => '批量操作',
    'batch_update' => '批量修改字段',
    'clear_all'    => '清空域名',
];
$pageTitle = ($typeLabels[$job['type']] ?? '任务') . ' — 处理中';
require_once dirname(__DIR__) . '/components/header.php';
?>

<div class="row justify-content-center">
  <div class="col-xl-7">
    <div class="form-card">

      <!-- 标题 -->
      <div class="d-flex align-items-center gap-3 mb-4">
        <div id="statusIcon" class="fs-2">
          <?php if ($job['status'] === 'done'): ?>⏏️
          <?php elseif ($job['status'] === 'failed'): ?>❌
          <?php else: ?>⏳
          <?php endif; ?>
        </div>
        <div>
          <h5 class="mb-0 fw-bold" id="statusTitle">
            <?php
              if ($job['status'] === 'done')        echo '处理完成';
              elseif ($job['status'] === 'failed')  echo '处理失败';
              elseif ($job['status'] === 'running') echo '正在处理…';
              else                                  echo '等待处理…';
            ?>
          </h5>
          <small class="text-muted">任务 ID: <?= h($id) ?></small>
        </div>
      </div>

      <!-- 进度条 -->
      <div class="mb-3">
        <?php
          $pct = $job['total'] > 0
            ? min(100, (int)round($job['progress'] / $job['total'] * 100))
            : ($job['status'] === 'done' ? 100 : 0);
        ?>
        <div class="d-flex justify-content-between small text-muted mb-1">
          <span id="progressMsg"><?= h($job['message'] ?: '等待中…') ?></span>
          <span id="progressPct"><?= $pct ?>%</span>
        </div>
        <div class="progress" style="height:20px">
          <div id="progressBar"
               class="progress-bar progress-bar-striped <?= $job['status'] === 'running' || $job['status'] === 'pending' ? 'progress-bar-animated' : '' ?> bg-<?= $job['status'] === 'failed' ? 'danger' : 'primary' ?>"
               style="width:<?= $pct ?>%"></div>
        </div>
        <div class="small text-muted mt-1" id="progressCount">
          <?php if ($job['total'] > 0): ?>
          <?= $job['progress'] ?> / <?= $job['total'] ?> 条
          <?php endif; ?>
        </div>
      </div>

      <!-- 结果区（完成后显示） -->
      <div id="resultArea" style="<?= $job['status'] !== 'done' && $job['status'] !== 'failed' ? 'display:none' : '' ?>">
        <?php
          $result = ($job['result'] && $job['result'] !== '')
            ? (is_array($job['result']) ? $job['result'] : json_decode($job['result'], true))
            : null;
        ?>
        <?php if ($job['status'] === 'done' && $result): ?>
        <div class="alert alert-success mb-3">
          <div class="fw-semibold mb-2"><i class="bi bi-check-circle me-2"></i>操作成功</div>
          <div class="d-flex flex-wrap gap-3">
            <?php if (isset($result['added'])):   ?><span class="badge bg-success fs-6"><?= $result['added'] ?> 新增</span><?php endif; ?>
            <?php if (isset($result['updated'])): ?><span class="badge bg-info fs-6"><?= $result['updated'] ?> 更新</span><?php endif; ?>
            <?php if (isset($result['skipped'])): ?><span class="badge bg-secondary fs-6"><?= $result['skipped'] ?> 跳过</span><?php endif; ?>
            <?php if (isset($result['ok'])):      ?><span class="badge bg-success fs-6"><?= $result['ok'] ?> 成功</span><?php endif; ?>
            <?php if (isset($result['not_found'])): ?><span class="badge bg-warning text-dark fs-6"><?= $result['not_found'] ?> 未找到</span><?php endif; ?>
            <?php if (isset($result['processed'])): ?><span class="badge bg-primary fs-6"><?= $result['processed'] ?> 已处理</span><?php endif; ?>
            <?php if (isset($result['cleared'])):   ?><span class="badge bg-danger fs-6"><?= $result['cleared'] ?> 已清空</span><?php endif; ?>
          </div>
        </div>
        <?php elseif ($job['status'] === 'failed'): ?>
        <div class="alert alert-danger mb-3">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <strong>处理失败：</strong><?= h($job['message']) ?>
        </div>
        <?php endif; ?>

        <div class="d-flex gap-2">
          <a href="/admin/index.php" class="btn btn-primary"><i class="bi bi-grid-1x2 me-1"></i>返回域名列表</a>
          <a href="javascript:history.back()" class="btn btn-outline-secondary">返回上一页</a>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function () {
  var jobId   = <?= json_encode($id) ?>;
  var isDone  = <?= in_array($job['status'], ['done','failed']) ? 'true' : 'false' ?>;
  var timer   = null;

  if (isDone) return;   // 已完成，无需轮询

  function poll() {
    fetch('/api/job_status.php?id=' + encodeURIComponent(jobId))
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (!data.ok) return;
        var job = data.job;

        // 更新进度条
        document.getElementById('progressBar').style.width = job.pct + '%';
        document.getElementById('progressPct').textContent = job.pct + '%';
        document.getElementById('progressMsg').textContent = job.message || '处理中…';
        if (job.total > 0) {
          document.getElementById('progressCount').textContent =
            job.progress + ' / ' + job.total + ' 条';
        }

        if (job.status === 'done' || job.status === 'failed') {
          clearInterval(timer);

          // 更新标题
          var titleEl = document.getElementById('statusTitle');
          titleEl.textContent = job.status === 'done' ? '处理完成' : '处理失败';

          // 进度条变色
          var bar = document.getElementById('progressBar');
          bar.classList.remove('progress-bar-animated','bg-primary');
          bar.classList.add(job.status === 'failed' ? 'bg-danger' : 'bg-success');

          // 构建结果 HTML
          var html = '';
          if (job.status === 'done' && job.result) {
            html += '<div class="alert alert-success mb-3">';
            html += '<div class="fw-semibold mb-2"><i class="bi bi-check-circle me-2"></i>操作成功</div>';
            html += '<div class="d-flex flex-wrap gap-3">';
            var r = job.result;
            if (r.added   !== undefined) html += '<span class="badge bg-success fs-6">'  + r.added   + ' 新增</span>';
            if (r.updated !== undefined) html += '<span class="badge bg-info fs-6">'     + r.updated + ' 更新</span>';
            if (r.skipped !== undefined) html += '<span class="badge bg-secondary fs-6">'+ r.skipped + ' 跳过</span>';
            if (r.ok      !== undefined) html += '<span class="badge bg-success fs-6">'  + r.ok      + ' 成功</span>';
            if (r.not_found !== undefined) html += '<span class="badge bg-warning text-dark fs-6">'+ r.not_found + ' 未找到</span>';
            if (r.processed !== undefined) html += '<span class="badge bg-primary fs-6">'+ r.processed + ' 已处理</span>';
            if (r.cleared   !== undefined) html += '<span class="badge bg-danger fs-6">'  + r.cleared   + ' 已清空</span>';
            html += '</div></div>';
          } else if (job.status === 'failed') {
            html += '<div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle me-2"></i><strong>处理失败：</strong>'
                 + job.message + '</div>';
          }
          html += '<div class="d-flex gap-2">'
               + '<a href="/admin/index.php" class="btn btn-primary"><i class="bi bi-grid-1x2 me-1"></i>返回域名列表</a>'
               + '<a href="javascript:history.back()" class="btn btn-outline-secondary">返回上一页</a>'
               + '</div>';

          var ra = document.getElementById('resultArea');
          ra.innerHTML = html;
          ra.style.display = '';
        }
      })
      .catch(function(){});  // 网络错误静默处理
  }

  poll();                        // 立即执行一次
  timer = setInterval(poll, 1500); // 每 1.5 秒轮询
})();
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
