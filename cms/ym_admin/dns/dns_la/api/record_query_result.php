<?php
// 读取解析记录查询结果
require_once dirname(dirname(dirname(__DIR__))) . '/core/functions.php';
header('Content-Type: application/json');

$jobId = trim($_GET['job_id'] ?? '');
if (!$jobId) { echo json_encode(['ok'=>false,'msg'=>'缺少 job_id']); exit; }

// 检查任务状态
$job = db_one(getMasterDB(), "SELECT status, result FROM jobs WHERE id=?", [$jobId]);
if (!$job) { echo json_encode(['ok'=>false,'msg'=>'任务不存在']); exit; }
if ($job['status'] !== 'done') { echo json_encode(['ok'=>false,'msg'=>'任务未完成', 'status'=>$job['status']]); exit; }

// 读取结果文件
$resultFile = DATA_DIR . '/jobs/' . $jobId . '_qresult.json';
if (!file_exists($resultFile)) { echo json_encode(['ok'=>false,'msg'=>'结果文件不存在']); exit; }

$data = json_decode(file_get_contents($resultFile), true);
echo json_encode(['ok' => true, 'results' => $data['results'] ?? [], 'stats' => $data['stats'] ?? []]);
