// 全选/取消全选
document.addEventListener('DOMContentLoaded', function () {
  const selectAll = document.getElementById('selectAll');
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
      updateBatchBar();
    });
    document.querySelectorAll('.row-check').forEach(cb => {
      cb.addEventListener('change', updateBatchBar);
    });
  }

  // 标签多选下拉
  initTagFilter();
});

function updateBatchBar() {
  const checked = document.querySelectorAll('.row-check:checked');
  const bar = document.getElementById('batch-bar');
  const countEl = document.getElementById('batch-count');
  if (!bar) return;
  if (checked.length > 0) {
    bar.style.display = 'flex';
    if (countEl) countEl.textContent = checked.length;
  } else {
    bar.style.display = 'none';
  }
}

function getCheckedIds() {
  return Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);
}

// 批量操作提交 → 后台任务 → 跳转进度页
function doBatchAction(action) {
  var ids = getCheckedIds();
  if (!ids.length) return;

  var confirmMsgs = {
    delete: '确定要删除选中的 ' + ids.length + ' 个域名吗？此操作不可撤销！',
    pause:  '确定要将选中域名状态改为「暂停解析」吗？',
    normal: '确定要将选中域名状态改为「正常」吗？',
  };
  if (confirmMsgs[action] && !confirm(confirmMsgs[action])) return;

  fetch('/batch/api/batch.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: action, ids: ids}),
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok && data.job_id) {
      location.href = '/jobs/progress.php?id=' + encodeURIComponent(data.job_id);
    } else {
      alert('操作失败：' + (data.msg || '未知错误'));
    }
  });
}

// 删除单个 → 后台任务
function deleteDomain(id, name) {
  if (!confirm('确定要删除域名「' + name + '」吗？')) return;
  fetch('/batch/api/batch.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'delete', ids: [String(id)]}),
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok && data.job_id) {
      location.href = '/jobs/progress.php?id=' + encodeURIComponent(data.job_id);
    } else {
      alert('删除失败');
    }
  });
}

// 标签多选
function initTagFilter() {
  const dropdown = document.getElementById('tagFilterDropdown');
  if (!dropdown) return;
  const checkboxes = dropdown.querySelectorAll('.tag-check');
  const btn = document.getElementById('tagFilterBtn');
  const input = document.getElementById('tagFilterInput');

  function updateBtn() {
    const checked = dropdown.querySelectorAll('.tag-check:checked');
    if (checked.length === 0) {
      btn.textContent = '选择标签';
    } else {
      btn.textContent = '已选 ' + checked.length + ' 个标签';
    }
    input.value = Array.from(checked).map(c => c.value).join(',');
  }

  checkboxes.forEach(cb => cb.addEventListener('change', updateBtn));
  updateBtn();
}
