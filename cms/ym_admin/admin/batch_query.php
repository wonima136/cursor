<?php
$pageTitle = '批量查询';
require_once dirname(__DIR__) . '/components/header.php';

$results      = [];
$notFound     = [];
$tagStats     = [];
$queried      = false;
$customFields = getCustomFields();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $queried = true;
    $master  = getMasterDB();
    $lines   = array_unique(array_filter(array_map('trim', explode("\n", $_POST['domains'] ?? ''))));

    // 批量查询
    foreach ($lines as $domain) {
        $row = db_one($master, "
            SELECT d.*, r.name AS registrar_name, a.username AS account_name
            FROM domains d
            LEFT JOIN registrars r ON r.id = d.registrar_id
            LEFT JOIN accounts a ON a.id = d.account_id
            WHERE d.domain = ?
        ", [$domain]);

        if ($row) {
            $row['tags'] = _getDomainTags((int)$row['id']);
            $row['cf']   = json_decode($row['custom_data'] ?? '{}', true) ?: [];
            $results[]   = $row;
            foreach ($row['tags'] as $tag) {
                if (!isset($tagStats[$tag['id']])) {
                    $tagStats[$tag['id']] = ['tag' => $tag, 'count' => 0];
                }
                $tagStats[$tag['id']]['count']++;
            }
        } else {
            $notFound[] = $domain;
        }
    }
}
?>

<div class="row g-3">
  <div class="col-xl-4">
    <div class="form-card">
      <div class="section-title">输入域名列表</div>
      <form method="POST">
        <textarea name="domains" class="form-control font-monospace mb-3" rows="20"
                  placeholder="每行输入一个域名&#10;example.com&#10;test.cn"><?= h($_POST['domains'] ?? '') ?></textarea>
        <button type="submit" class="btn btn-primary w-100">查询</button>
      </form>
    </div>
  </div>

  <div class="col-xl-8">
    <?php if ($queried): ?>

    <!-- 统计 -->
    <div class="row g-2 mb-3">
      <div class="col-4">
        <div class="stat-card text-center">
          <div class="stat-num text-primary"><?= count($results) ?></div>
          <div class="stat-label">已找到</div>
        </div>
      </div>
      <div class="col-4">
        <div class="stat-card text-center">
          <div class="stat-num text-danger"><?= count($notFound) ?></div>
          <div class="stat-label">未找到</div>
        </div>
      </div>
      <div class="col-4">
        <div class="stat-card text-center">
          <div class="stat-num text-secondary"><?= count($results) + count($notFound) ?></div>
          <div class="stat-label">总查询</div>
        </div>
      </div>
    </div>

    <!-- 标签统计 -->
    <?php if ($tagStats): ?>
    <div class="form-card mb-3">
      <div class="section-title">标签分布</div>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($tagStats as $ts): ?>
        <span class="tag-pill d-flex align-items-center gap-1" style="background:<?= h($ts['tag']['color']) ?>">
          <?= h($ts['tag']['name']) ?>
          <span class="badge bg-white text-dark ms-1"><?= $ts['count'] ?></span>
        </span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 查询结果表格 -->
    <?php if ($results): ?>
    <div class="table-card mb-3">
      <div class="px-3 py-2 border-bottom fw-semibold">找到 <?= count($results) ?> 个域名</div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
              <th>域名</th><th>注册商</th><th>注册时间</th><th>过期时间</th>
              <th>状态</th><th>备案</th><th>DNS</th><th>标签</th>
              <?php foreach ($customFields as $cf): ?>
              <th class="small"><?= h($cf['label']) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $d): ?>
            <?php $expClass = getDomainExpireClass($d['expire_date']); ?>
            <tr>
              <td>
                <a href="/admin/detail.php?id=<?= $d['id'] ?>" class="domain-name"><?= h($d['domain']) ?></a>
              </td>
              <td class="small text-muted"><?= h($d['registrar_name'] ?? '-') ?></td>
              <td class="small"><?= h($d['register_date'] ?: '-') ?></td>
              <td>
                <span class="small <?= $expClass ?>"><?= h($d['expire_date'] ?: '-') ?></span>
                <?= getDomainExpireBadge($d['expire_date'] ?? '') ?>
              </td>
              <td>
                <?php $s = STATUS_LABELS[$d['status']] ?? ['label' => $d['status'], 'class' => 'secondary']; ?>
                <span class="badge bg-<?= $s['class'] ?>"><?= h($s['label']) ?></span>
              </td>
              <td>
                <?php $icp = ICP_LABELS[$d['icp_type']] ?? ['label' => $d['icp_type'], 'class' => 'secondary']; ?>
                <span class="badge bg-<?= $icp['class'] ?>"><?= h($icp['label']) ?></span>
              </td>
              <td class="small text-muted" style="max-width:150px;word-break:break-all">
                <?php if ($d['dns_servers'] ?? ''): ?>
                <?php foreach (explode(',', $d['dns_servers']) as $_dns): ?>
                <div><?= h(trim($_dns)) ?></div>
                <?php endforeach; ?>
                <?php else: ?>-<?php endif; ?>
              </td>
              <td>
                <?php foreach ($d['tags'] as $tag): ?>
                <span class="tag-pill" style="background:<?= h($tag['color']) ?>"><?= h($tag['name']) ?></span>
                <?php endforeach; ?>
              </td>
              <?php foreach ($customFields as $cf): ?>
              <td class="small text-muted"><?= h($d['cf'][$cf['name']] ?? '') ?></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- 未找到 -->
    <?php if ($notFound): ?>
    <div class="form-card">
      <div class="section-title text-danger">未找到的域名（<?= count($notFound) ?> 个）</div>
      <div class="font-monospace small">
        <?php foreach ($notFound as $d): ?>
        <div class="text-danger"><?= h($d) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/components/footer.php'; ?>
