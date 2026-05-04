<?php
// ════════════════════════════════════════════════════════════════
// DNS-LA API 辅助函数
// ════════════════════════════════════════════════════════════════

define('DNSLA_API_BASE_DEFAULT', 'https://api.dns.la');
define('DNSLA_ACCOUNTS_FILE', DATA_DIR . '/dns_la_accounts.json');

// ── 账号管理 ─────────────────────────────────────────────────────

function dnsla_getAccounts(): array {
    if (!file_exists(DNSLA_ACCOUNTS_FILE)) return [];
    $data = json_decode(file_get_contents(DNSLA_ACCOUNTS_FILE), true);
    return is_array($data) ? $data : [];
}

function dnsla_saveAccounts(array $accounts): void {
    $dir = dirname(DNSLA_ACCOUNTS_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(DNSLA_ACCOUNTS_FILE, json_encode($accounts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function dnsla_getAccount(int $id): ?array {
    foreach (dnsla_getAccounts() as $acc) {
        if ((int)$acc['id'] === $id) return $acc;
    }
    return null;
}

function dnsla_buildToken(string $apiId, string $apiSecret): string {
    return base64_encode($apiId . ':' . $apiSecret);
}

// ── HTTP 请求 ────────────────────────────────────────────────────

/**
 * 发送 DNS-LA API 请求
 * @return array ['ok' => bool, 'code' => int, 'msg' => string, 'data' => mixed]
 */
function dnsla_request(string $method, string $path, array $query, array $body, string $token, string $apiBase = ''): array {
    $apiBase = rtrim($apiBase ?: DNSLA_API_BASE_DEFAULT, '/');
    $url     = $apiBase . $path;
    if ($query) $url .= '?' . http_build_query($query);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $token,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $method = strtoupper($method);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $raw  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['ok' => false, 'code' => 0, 'msg' => 'cURL error: ' . $err, 'data' => null];

    $resp = json_decode($raw, true);
    if (!is_array($resp)) return ['ok' => false, 'code' => $http, 'msg' => 'Invalid JSON response', 'data' => null];

    $code = (int)($resp['code'] ?? 0);
    return [
        'ok'   => $code === 200,
        'code' => $code,
        'msg'  => $resp['msg'] ?? '',
        'data' => $resp['data'] ?? null,
    ];
}

/**
 * 并发发送多个 DNS-LA 请求（curl_multi）
 * @param array  $requests  每项 ['method'=>, 'path'=>, 'query'=>[], 'body'=>[]]
 * @return array            与 $requests 等长，每项同 dnsla_request 返回格式
 */
function dnsla_requestMulti(array $requests, string $token, string $apiBase = ''): array {
    $apiBase = rtrim($apiBase ?: DNSLA_API_BASE_DEFAULT, '/');
    $headers = [
        'Authorization: Basic ' . $token,
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json',
    ];

    $mh   = curl_multi_init();
    $chs  = [];

    foreach ($requests as $req) {
        $method = strtoupper($req['method'] ?? 'GET');
        $url    = $apiBase . $req['path'];
        if (!empty($req['query'])) $url .= '?' . http_build_query($req['query']);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req['body'] ?? [], JSON_UNESCAPED_UNICODE));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req['body'] ?? [], JSON_UNESCAPED_UNICODE));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if (!empty($req['body'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req['body'], JSON_UNESCAPED_UNICODE));
            }
        }

        curl_multi_add_handle($mh, $ch);
        $chs[] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.1);
    } while ($running > 0);

    $results = [];
    foreach ($chs as $ch) {
        $raw  = curl_multi_getcontent($ch);
        $err  = curl_error($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);

        if ($err) {
            $results[] = ['ok' => false, 'code' => 0, 'msg' => 'cURL: ' . $err, 'data' => null];
            continue;
        }
        $resp = json_decode($raw, true);
        if (!is_array($resp)) {
            $results[] = ['ok' => false, 'code' => $http, 'msg' => 'Invalid JSON', 'data' => null];
            continue;
        }
        $code = (int)($resp['code'] ?? 0);
        $results[] = ['ok' => $code === 200, 'code' => $code, 'msg' => $resp['msg'] ?? '', 'data' => $resp['data'] ?? null];
    }
    curl_multi_close($mh);

    return $results;
}

/**
 * 并发查询多个域名的 ID
 * @param array  $account
 * @param array  $domains  域名字符串数组
 * @return array           domain => id|null
 */
function dnsla_findDomainIds(array $account, array $domains): array {
    if (empty($domains)) return [];
    $requests = [];
    foreach ($domains as $d) {
        $requests[] = ['method' => 'GET', 'path' => '/api/domain', 'query' => ['domain' => $d], 'body' => []];
    }
    $token   = dnsla_buildToken($account['api_id'], $account['api_secret']);
    $base    = $account['api_base'] ?? '';
    $results = dnsla_requestMulti($requests, $token, $base);
    $map     = [];
    foreach ($domains as $i => $d) {
        $r = $results[$i];
        $map[$d] = ($r['ok'] && !empty($r['data']['id'])) ? $r['data']['id'] : null;
    }
    return $map;
}

/**
 * 拉取某账号全量域名列表（自动翻页）
 * @return array|null  null=失败, array=域名行数组
 */
function dnsla_fetchAllDomains(array $account): ?array {
    $token   = dnsla_buildToken($account['api_id'], $account['api_secret']);
    $base    = $account['api_base'] ?? '';
    $page    = 1;
    $size    = 100;
    $results = [];

    do {
        $r = dnsla_request('GET', '/api/domainList', ['pageIndex' => $page, 'pageSize' => $size], [], $token, $base);
        if (!$r['ok']) return null;
        $items = $r['data']['results'] ?? [];
        $total = (int)($r['data']['total'] ?? 0);
        $results = array_merge($results, $items);
        $page++;
    } while (count($results) < $total && count($items) === $size);

    return $results;
}

/**
 * 通过域名名称查找 DNS-LA 域名 ID（直接调用获取域名接口，无需全量拉取）
 */
function dnsla_findDomainId(array $account, string $domain): ?string {
    $token  = dnsla_buildToken($account['api_id'], $account['api_secret']);
    $base   = $account['api_base'] ?? '';
    $domain = rtrim(strtolower(trim($domain)), '.');
    $r = dnsla_request('GET', '/api/domain', ['domain' => $domain], [], $token, $base);
    if ($r['ok'] && !empty($r['data']['id'])) return $r['data']['id'];
    return null;
}

/**
 * 拉取某域名全量解析记录（自动翻页）
 */
function dnsla_fetchAllRecords(array $account, string $domainId): ?array {
    $token   = dnsla_buildToken($account['api_id'], $account['api_secret']);
    $base    = $account['api_base'] ?? '';
    $page    = 1;
    $size    = 100;
    $results = [];

    do {
        $r = dnsla_request('GET', '/api/recordList',
            ['pageIndex' => $page, 'pageSize' => $size, 'domainId' => $domainId],
            [], $token, $base
        );
        if (!$r['ok']) return null;
        $items = $r['data']['results'] ?? [];
        $total = (int)($r['data']['total'] ?? 0);
        $results = array_merge($results, $items);
        $page++;
    } while (count($results) < $total && count($items) === $size);

    return $results;
}

// ── 找回域名 ─────────────────────────────────────────────────────

/**
 * 创建域名找回任务（若已存在则直接返回现有任务）
 * type=1 即 TXT 验证方式
 * @return array ['ok'=>bool, 'id'=>string, 'host'=>string, 'data'=>string, 'state'=>int, 'msg'=>string]
 */
function dnsla_createRetrieve(array $account, string $domain): array {
    $token  = dnsla_buildToken($account['api_id'], $account['api_secret']);
    $base   = $account['api_base'] ?? '';
    $domain = rtrim(strtolower(trim($domain)), '.');

    // 先尝试创建（幂等，已存在返回同一个 ID）
    $r = dnsla_request('POST', '/api/domainRetrieve', [], ['domain' => $domain, 'type' => 1], $token, $base);
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => $r['msg'] ?: ('code:' . $r['code'])];
    }

    $id = $r['data']['id'] ?? '';
    if (!$id) {
        return ['ok' => false, 'msg' => '未返回任务 ID'];
    }

    // 拉详情拿 TXT 记录
    $d = dnsla_request('GET', '/api/domainRetrieve', ['id' => $id], [], $token, $base);
    if (!$d['ok'] || empty($d['data'])) {
        return ['ok' => false, 'msg' => '获取找回详情失败'];
    }

    return [
        'ok'     => true,
        'id'     => $id,
        'domain' => $domain,
        'host'   => $d['data']['host']  ?? '@',
        'data'   => $d['data']['data']  ?? '',
        'state'  => (int)($d['data']['state'] ?? 0),
        'reason' => $d['data']['reason'] ?? '',
        'msg'    => '',
    ];
}

/**
 * 查找回任务列表（分页）
 */
function dnsla_getRetrieveList(array $account, int $page = 1, int $size = 50): array {
    $token = dnsla_buildToken($account['api_id'], $account['api_secret']);
    $base  = $account['api_base'] ?? '';
    $r = dnsla_request('GET', '/api/domainRetrieveList',
        ['pageIndex' => $page, 'pageSize' => $size], [], $token, $base);
    return $r;
}

/**
 * 删除找回任务
 */
function dnsla_deleteRetrieve(array $account, string $id): array {
    $token = dnsla_buildToken($account['api_id'], $account['api_secret']);
    $base  = $account['api_base'] ?? '';
    return dnsla_request('DELETE', '/api/domainRetrieve', ['id' => $id], [], $token, $base);
}

// ── 记录类型映射 ─────────────────────────────────────────────────
function dnsla_recordTypes(): array {
    return [
        1   => 'A',
        2   => 'NS',
        5   => 'CNAME',
        15  => 'MX',
        16  => 'TXT',
        28  => 'AAAA',
        33  => 'SRV',
        257 => 'CAA',
        256 => 'URL转发',
    ];
}

function dnsla_recordTypeName(int $type): string {
    return dnsla_recordTypes()[$type] ?? "TYPE{$type}";
}
