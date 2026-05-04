<?php
/**
 * 调试：查询单个域名在 DNS-LA 账号中的原始 API 返回
 * GET: account_id=1&domain=example.com
 */
require_once dirname(dirname(dirname(__DIR__))) . '/core/functions.php';
require_once dirname(__DIR__) . '/core/api.php';

header('Content-Type: application/json');

$accountId = (int)($_GET['account_id'] ?? 0);
$domain    = rtrim(strtolower(trim($_GET['domain'] ?? '')), '.');
$account   = $accountId ? dnsla_getAccount($accountId) : null;

if (!$account) { echo json_encode(['ok' => false, 'msg' => '账号不存在']); exit; }
if (!$domain)  { echo json_encode(['ok' => false, 'msg' => '请传入 domain 参数']); exit; }

$token = dnsla_buildToken($account['api_id'], $account['api_secret']);
$base  = $account['api_base'] ?? '';

// 同时测试两个接口
$r1 = dnsla_request('GET', '/api/domain', ['domain' => $domain], [], $token, $base);

echo json_encode([
    'domain'         => $domain,
    'get_domain_api' => $r1,
    'conclusion'     => $r1['ok'] && !empty($r1['data']['id'])
        ? '✅ 域名在账号中，ID=' . $r1['data']['id']
        : '❌ 域名不在账号中，code=' . $r1['code'] . '，msg=' . $r1['msg'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
