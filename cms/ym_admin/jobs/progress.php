<?php
$id = trim($_GET['id'] ?? '');
require_once dirname(__DIR__) . '/core/functions.php';

$job = $id ? db_one(getMasterDB(),
    "SELECT id,type,status,progress,total,message,result FROM jobs WHERE id=?", [$id]
) : null;
if (!$job) { header('Location: /domains/'); exit; }

$typeLabels = [
    'import'        => '批量导入',
    'renew'         => '批量续费',
    'renew_add_years'=> '批量续费（增加年份）',
    'batch_action'  => '批量操作',
    'batch_update'  => '批量修改字段',
    'clear_all'     => '清空域名',
    'domain_search'        => '域名列表查找',
    'dns_la_add_domains'   => 'DNS-LA 批量添加域名',
    'dns_la_del_domains'   => 'DNS-LA 批量删除域名',
    'dns_la_add_records'   => 'DNS-LA 批量添加解析记录',
    'dns_la_update_records'=> 'DNS-LA 批量修改解析记录',
    'dns_la_del_records'   => 'DNS-LA 批量删除解析记录',
];
$pageTitle = ($typeLabels[$job['type']] ?? '任务') . ' — 处理中';
// 对于 domain_search 任务，完成后需自动跳转
$_isDomainSearch = ($job['type'] === 'domain_search');
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
            <?php if (isset($result['failed']) && $result['failed'] > 0): ?><span class="badge bg-danger fs-6"><?= $result['failed'] ?> 失败</span><?php endif; ?>
            <?php if (isset($result['not_found'])): ?><span class="badge bg-warning text-dark fs-6"><?= $result['not_found'] ?> 未找到</span><?php endif; ?>
            <?php if (isset($result['processed'])): ?><span class="badge bg-primary fs-6"><?= $result['processed'] ?> 已处理</span><?php endif; ?>
            <?php if (isset($result['cleared'])):   ?><span class="badge bg-warning text-dark fs-6"><?= $result['cleared'] ?> 已清空</span><?php endif; ?>
            <?php if (isset($result['stopped_at'])): ?><span class="badge bg-secondary fs-6">第 <?= $result['stopped_at'] ?> 条停止</span><?php endif; ?>
          </div>
          <?php if (isset($result['stopped_at'])): ?>
          <div class="mt-2 small text-warning"><i class="bi bi-exclamation-triangle me-1"></i>在第 <?= $result['stopped_at'] ?> 条时提前终止</div>
          <?php endif; ?>
        </div>

        <?php
        // 成功域名列表（可折叠）
        if (!empty($result['ok_domains'])):
            $okList = $result['ok_domains'];
        ?>
        <div class="mt-3 border border-success-subtle rounded-3 overflow-hidden">
          <div class="d-flex align-items-center gap-2 px-3 py-2 bg-success-subtle"
               style="cursor:pointer" onclick="this.nextElementSibling.classList.toggle('d-none')">
            <span class="badge bg-success"><?= count($okList) ?> 个</span>
            <span class="fw-semibold text-success small">成功域名列表</span>
            <i class="bi bi-chevron-down ms-auto text-success"></i>
            <button class="btn btn-xs btn-outline-success"
              onclick="event.stopPropagation();copyList(<?= h(json_encode($okList)) ?>)">
              <i class="bi bi-clipboard me-1"></i>复制全部
            </button>
          </div>
          <div class="d-none">
            <textarea class="form-control form-control-sm font-monospace border-0 rounded-0"
              rows="<?= min(10, count($okList)) ?>" readonly
              style="font-size:12px;resize:vertical"><?= h(implode("\n", $okList)) ?></textarea>
          </div>
        </div>
        <?php endif; ?>

        <?php
        // 失败详情：按原因分组
        if (!empty($result['fail_details'])):
            $groups = [];
            foreach ($result['fail_details'] as [$dom, $reason]) {
                $groups[$reason][] = $dom;
            }
        ?>
        <div class="mt-3">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="fw-semibold text-danger"><i class="bi bi-x-circle me-1"></i>失败明细（<?= count($result['fail_details']) ?> 条）</span>
            <button class="btn btn-xs btn-outline-danger"
              onclick="copyList(<?= h(json_encode(array_column($result['fail_details'], 0))) ?>)">
              <i class="bi bi-clipboard me-1"></i>复制全部失败项
            </button>
          </div>
          <?php foreach ($groups as $reason => $doms): ?>
          <div class="mb-2 border rounded-3 overflow-hidden">
            <div class="d-flex align-items-center gap-2 px-3 py-2 bg-danger-subtle">
              <span class="badge bg-danger"><?= count($doms) ?> 个</span>
              <span class="small fw-semibold text-danger"><?= h($reason) ?></span>
              <button class="btn btn-xs btn-outline-danger ms-auto"
                onclick="copyList(<?= h(json_encode($doms)) ?>)">
                <i class="bi bi-clipboard me-1"></i>复制这组
              </button>
            </div>
            <textarea class="form-control form-control-sm font-monospace border-0 rounded-0" rows="<?= min(6, count($doms)) ?>" readonly
              style="font-size:12px;resize:vertical"><?= h(implode("\n", $doms)) ?></textarea>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($result['not_found_domains'])): ?>
        <div class="mt-3 border rounded-3 overflow-hidden">
          <div class="d-flex align-items-center gap-2 px-3 py-2 bg-warning-subtle">
            <span class="badge bg-warning text-dark"><?= count($result['not_found_domains']) ?> 个</span>
            <span class="small fw-semibold">账号中不存在（未找到）</span>
            <button class="btn btn-xs btn-outline-warning ms-auto"
              onclick="copyList(<?= h(json_encode($result['not_found_domains'])) ?>)">
              <i class="bi bi-clipboard me-1"></i>复制
            </button>
          </div>
          <textarea class="form-control form-control-sm font-monospace border-0 rounded-0" rows="<?= min(6, count($result['not_found_domains'])) ?>" readonly
            style="font-size:12px;resize:vertical"><?= h(implode("\n", $result['not_found_domains'])) ?></textarea>
        </div>
        <?php endif; ?>
        <?php elseif ($job['status'] === 'failed'): ?>
        <div class="alert alert-danger mb-3">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <strong>处理失败：</strong><?= h($job['message']) ?>
        </div>
        <?php endif; ?>

        <div class="d-flex gap-2">
          <?php if (strpos($job['type'], 'dns_la_') === 0): ?>
          <a href="/dns/dns_la/" class="btn btn-primary"><i class="bi bi-diagram-3 me-1"></i>返回 DNS-LA</a>
          <?php else: ?>
          <a href="/domains/" class="btn btn-primary"><i class="bi bi-grid-1x2 me-1"></i>返回域名列表</a>
          <?php endif; ?>
          <a href="javascript:history.back()" class="btn btn-outline-secondary">返回上一页</a>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function () {
  var jobId          = <?= json_encode($id) ?>;
  var jobType        = <?= json_encode($job['type']) ?>;
  var isDone         = <?= in_array($job['status'], ['done','failed']) ? 'true' : 'false' ?>;
  var isDomainSearch = <?= $_isDomainSearch ? 'true' : 'false' ?>;
  var timer          = null;

  // 如果是域名查找且已完成，直接跳转
  if (isDone && isDomainSearch) {
    <?php if ($job['status'] === 'done' && !empty(json_decode($job['result'] ?? '{}', true)['redirect_url'])): ?>
    window.location.replace(<?= json_encode(json_decode($job['result'], true)['redirect_url']) ?>);
    <?php endif; ?>
  }

  if (isDone) return;   // 已完成，无需轮询

  function poll() {
    fetch('/jobs/api/status.php?id=' + encodeURIComponent(jobId))
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

          // 域名查找完成 → 自动跳转到结果页
          if (isDomainSearch && job.status === 'done' && job.result && job.result.redirect_url) {
            window.location.replace(job.result.redirect_url);
            return;
          }

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
            if (r.added      !== undefined) html += '<span class="badge bg-success fs-6">'            + r.added      + ' 新增</span>';
            if (r.updated    !== undefined) html += '<span class="badge bg-info fs-6">'               + r.updated    + ' 更新</span>';
            if (r.skipped    !== undefined) html += '<span class="badge bg-secondary fs-6">'          + r.skipped    + ' 跳过</span>';
            if (r.ok         !== undefined) html += '<span class="badge bg-success fs-6">'           + r.ok         + ' 成功</span>';
            if (r.failed     !== undefined && r.failed > 0) html += '<span class="badge bg-danger fs-6">'  + r.failed + ' 失败</span>';
            if (r.not_found  !== undefined) html += '<span class="badge bg-warning text-dark fs-6">' + r.not_found  + ' 未找到</span>';
            if (r.matched    !== undefined) html += '<span class="badge bg-success fs-6">'           + r.matched    + ' 匹配</span>';
            if (r.processed  !== undefined) html += '<span class="badge bg-primary fs-6">'           + r.processed  + ' 已处理</span>';
            if (r.cleared    !== undefined) html += '<span class="badge bg-warning text-dark fs-6">' + r.cleared    + ' 已清空</span>';
            if (r.stopped_at !== undefined) html += '<span class="badge bg-secondary fs-6">第 '      + r.stopped_at + ' 条停止</span>';
            html += '</div>';
            if (r.stopped_at !== undefined) {
              html += '<div class="mt-2 small text-warning"><i class="bi bi-exclamation-triangle me-1"></i>在第 ' + r.stopped_at + ' 条时提前终止</div>';
            }
            html += '</div>';

            // 成功域名列表（可折叠）
            if (r.ok_domains && r.ok_domains.length) {
              html += '<div class="mt-3 border border-success-subtle rounded-3 overflow-hidden">'
                + '<div class="d-flex align-items-center gap-2 px-3 py-2 bg-success-subtle" style="cursor:pointer" onclick="this.nextElementSibling.classList.toggle(\'d-none\')">'
                + '<span class="badge bg-success">' + r.ok_domains.length + ' 个</span>'
                + '<span class="fw-semibold text-success small">成功域名列表</span>'
                + '<i class="bi bi-chevron-down ms-auto text-success"></i>'
                + '<button class="btn btn-xs btn-outline-success" onclick="event.stopPropagation();copyList(_okDomains)"><i class="bi bi-clipboard me-1"></i>复制全部</button>'
                + '</div>'
                + '<div class="d-none"><textarea class="form-control form-control-sm font-monospace border-0 rounded-0" rows="' + Math.min(10, r.ok_domains.length) + '" readonly style="font-size:12px;resize:vertical">'
                + r.ok_domains.join('\n') + '</textarea></div></div>';
              window._okDomains = r.ok_domains;
            }

            // 失败明细：按原因分组
            if (r.fail_details && r.fail_details.length) {
              var groups = {};
              r.fail_details.forEach(function(item) {
                var dom = item[0], reason = item[1];
                if (!groups[reason]) groups[reason] = [];
                groups[reason].push(dom);
              });
              var allFailed = r.fail_details.map(function(x){return x[0];});
              html += '<div class="mt-3">'
                   + '<div class="d-flex align-items-center gap-2 mb-2">'
                   + '<span class="fw-semibold text-danger"><i class="bi bi-x-circle me-1"></i>失败明细（' + r.fail_details.length + ' 条）</span>'
                   + '<button class="btn btn-xs btn-outline-danger" onclick="copyList(' + JSON.stringify(allFailed) + ')"><i class="bi bi-clipboard me-1"></i>复制全部失败项</button>'
                   + '</div>';
              Object.keys(groups).forEach(function(reason) {
                var doms = groups[reason];
                var rows = Math.min(6, doms.length);
                html += '<div class="mb-2 border rounded-3 overflow-hidden">'
                     + '<div class="d-flex align-items-center gap-2 px-3 py-2 bg-danger-subtle">'
                     + '<span class="badge bg-danger">' + doms.length + ' 个</span>'
                     + '<span class="small fw-semibold text-danger">' + reason + '</span>'
                     + '<button class="btn btn-xs btn-outline-danger ms-auto" onclick="copyList(' + JSON.stringify(doms) + ')"><i class="bi bi-clipboard me-1"></i>复制这组</button>'
                     + '</div>'
                     + '<textarea class="form-control form-control-sm font-monospace border-0 rounded-0" rows="' + rows + '" readonly style="font-size:12px;resize:vertical">'
                     + doms.join('\n') + '</textarea></div>';
              });
              html += '</div>';
            }

            // 未找到域名
            if (r.not_found_domains && r.not_found_domains.length) {
              var rows2 = Math.min(6, r.not_found_domains.length);
              html += '<div class="mt-3 border rounded-3 overflow-hidden">'
                   + '<div class="d-flex align-items-center gap-2 px-3 py-2 bg-warning-subtle">'
                   + '<span class="badge bg-warning text-dark">' + r.not_found_domains.length + ' 个</span>'
                   + '<span class="small fw-semibold">账号中不存在（未找到）</span>'
                   + '<button class="btn btn-xs btn-outline-warning ms-auto" onclick="copyList(' + JSON.stringify(r.not_found_domains) + ')"><i class="bi bi-clipboard me-1"></i>复制</button>'
                   + '</div>'
                   + '<textarea class="form-control form-control-sm font-monospace border-0 rounded-0" rows="' + rows2 + '" readonly style="font-size:12px;resize:vertical">'
                   + r.not_found_domains.join('\n') + '</textarea></div>';
            }
          } else if (job.status === 'failed') {
            html += '<div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle me-2"></i><strong>处理失败：</strong>'
                 + job.message + '</div>';
          }
          var backBtn = jobType.indexOf('dns_la_') === 0
            ? '<a href="/dns/dns_la/" class="btn btn-primary"><i class="bi bi-diagram-3 me-1"></i>返回 DNS-LA</a>'
            : '<a href="/domains/" class="btn btn-primary"><i class="bi bi-grid-1x2 me-1"></i>返回域名列表</a>';
          html += '<div class="d-flex gap-2">' + backBtn
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

function copyList(arr) {
  var text = arr.join('\n');
  try {
    navigator.clipboard.writeText(text).then(function() { alert('已复制 ' + arr.length + ' 个域名'); });
  } catch(e) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
    alert('已复制 ' + arr.length + ' 个域名');
  }
}
</script>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
