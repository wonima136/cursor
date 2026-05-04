<?php
// ════════════════════════════════════════════════════════════════
// 函数库入口（按功能模块拆分，此文件统一引入）
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';   // h(), redirect(), flash(), paginate()
require_once __DIR__ . '/domain.php';    // 域名 CRUD、查询、过滤、标签、历史
require_once __DIR__ . '/import.php';    // _normalizeDate(), _importRow()
require_once __DIR__ . '/job.php';       // createJob(), launchJob(), getActiveJob()
require_once __DIR__ . '/agg.php';       // getFilterAggregates()
require_once __DIR__ . '/meta.php';      // getCustomFields(), cfSlug(), custom_data
