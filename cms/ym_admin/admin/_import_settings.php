<?php
// 公用导入设置区块（注册商、账号、标签、重复处理）
// 由 batch_import.php 的两个 <form> 共同 include
?>
<div class="form-card mb-3">
  <div class="section-title">导入设置</div>
  <div class="row g-3">
    <div class="col-md-3">
      <label class="form-label">注册商（统一覆盖）</label>
      <select name="registrar_id" class="form-select">
        <option value="">不设置</option>
        <?php foreach ($registrars as $r): ?>
        <option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">账号（统一覆盖）</label>
      <select name="account_id" class="form-select">
        <option value="">不设置</option>
        <?php foreach ($accounts as $a): ?>
        <option value="<?= $a['id'] ?>"><?= h($a['rname'] . ' / ' . $a['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">重复域名处理</label>
      <select name="update_existing" class="form-select">
        <option value="0">跳过</option>
        <option value="1">更新数据</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">统一打标签</label>
      <div class="d-flex flex-wrap gap-2 pt-1">
        <?php foreach ($allTags as $tag): ?>
        <label class="d-flex align-items-center gap-1" style="cursor:pointer">
          <input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>" class="form-check-input">
          <span class="tag-pill" style="background:<?= h($tag['color']) ?>"><?= h($tag['name']) ?></span>
        </label>
        <?php endforeach; ?>
        <?php if (!$allTags): ?><span class="text-muted small">暂无标签</span><?php endif; ?>
      </div>
    </div>
  </div>
</div>
