<?php
require_once dirname(__DIR__) . '/config/config.php';

// ── 连接管理 ──────────────────────────────────────────────────

$_dbPool = [];

function _getDB(string $path): PDO {
    global $_dbPool;
    if (isset($_dbPool[$path])) return $_dbPool[$path];

    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA synchronous=NORMAL;');

    $_dbPool[$path] = $pdo;
    return $pdo;
}

function getMasterDB(): PDO {
    $pdo = _getDB(MASTER_DB);
    static $init = false;
    if (!$init) { _initMasterDB($pdo); $init = true; }
    return $pdo;
}

// ── Schema 初始化 ─────────────────────────────────────────────

function _initMasterDB(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS registrars (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            url TEXT DEFAULT '',
            note TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registrar_id INTEGER DEFAULT 0,
            username TEXT NOT NULL,
            note TEXT DEFAULT '',
            admin_url TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            color TEXT DEFAULT '#6c757d',
            sort_order INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT NOT NULL UNIQUE,
            registrar_id INTEGER DEFAULT 0,
            account_id INTEGER DEFAULT 0,
            register_date TEXT DEFAULT '',
            expire_date TEXT DEFAULT '',
            status TEXT DEFAULT 'normal',
            icp_type TEXT DEFAULT 'none',
            icp_number TEXT DEFAULT '',
            dns_servers TEXT DEFAULT '',
            group_name TEXT DEFAULT '',
            admin_password TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS domain_tags (
            domain_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            PRIMARY KEY (domain_id, tag_id)
        );
        CREATE TABLE IF NOT EXISTS domain_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain_id INTEGER NOT NULL,
            action_type TEXT NOT NULL DEFAULT 'note',
            content TEXT NOT NULL DEFAULT '',
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS custom_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            field_type TEXT DEFAULT 'text',
            options TEXT DEFAULT '[]',
            sort_order INTEGER DEFAULT 0,
            show_in_list INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE INDEX IF NOT EXISTS idx_domains_expire  ON domains(expire_date);
        CREATE INDEX IF NOT EXISTS idx_domains_status  ON domains(status);
        CREATE INDEX IF NOT EXISTS idx_dt_tag          ON domain_tags(tag_id);
        CREATE INDEX IF NOT EXISTS idx_dt_domain       ON domain_tags(domain_id);
        CREATE INDEX IF NOT EXISTS idx_history_domain  ON domain_history(domain_id);
        CREATE TABLE IF NOT EXISTS domain_cards (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT NOT NULL,
            note       TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS domain_card_items (
            card_id   INTEGER NOT NULL REFERENCES domain_cards(id) ON DELETE CASCADE,
            domain_id INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
            PRIMARY KEY (card_id, domain_id)
        );
        CREATE INDEX IF NOT EXISTS idx_dci_domain ON domain_card_items(domain_id);
        CREATE TABLE IF NOT EXISTS jobs (
            id TEXT PRIMARY KEY,
            type TEXT NOT NULL,
            params TEXT NOT NULL DEFAULT '{}',
            status TEXT NOT NULL DEFAULT 'pending',
            pid INTEGER DEFAULT 0,
            progress INTEGER DEFAULT 0,
            total INTEGER DEFAULT 0,
            message TEXT DEFAULT '',
            result TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );
    ");

    // 兼容旧库：jobs.pid
    $cols = array_column($pdo->query("PRAGMA table_info(jobs)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('pid', $cols)) {
        $pdo->exec("ALTER TABLE jobs ADD COLUMN pid INTEGER DEFAULT 0");
    }
    // 兼容旧库：domains.custom_data
    $dcols = array_column($pdo->query("PRAGMA table_info(domains)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('custom_data', $dcols)) {
        $pdo->exec("ALTER TABLE domains ADD COLUMN custom_data TEXT DEFAULT '{}'");
    }
}

// ── 通用查询封装 ──────────────────────────────────────────────

function db_exec(PDO $pdo, string $sql, array $params = []): PDOStatement {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_all(PDO $pdo, string $sql, array $params = []): array {
    return db_exec($pdo, $sql, $params)->fetchAll();
}

function db_one(PDO $pdo, string $sql, array $params = []): ?array {
    $row = db_exec($pdo, $sql, $params)->fetch();
    return $row ?: null;
}

function db_val(PDO $pdo, string $sql, array $params = []) {
    return db_exec($pdo, $sql, $params)->fetchColumn();
}

function db_insert(PDO $pdo, string $table, array $data): int {
    $cols = implode(',', array_map(function($k) { return "\"$k\""; }, array_keys($data)));
    $ph   = implode(',', array_fill(0, count($data), '?'));
    db_exec($pdo, "INSERT INTO \"$table\" ($cols) VALUES ($ph)", array_values($data));
    return (int)$pdo->lastInsertId();
}

function db_upsert(PDO $pdo, string $table, array $data, string $conflictCol): void {
    $cols = implode(',', array_map(function($k) { return "\"$k\""; }, array_keys($data)));
    $ph   = implode(',', array_fill(0, count($data), '?'));
    db_exec($pdo,
        "INSERT OR REPLACE INTO \"$table\" ($cols) VALUES ($ph)",
        array_values($data)
    );
}

function db_update(PDO $pdo, string $table, array $data, string $where, array $wp = []): void {
    $sets = implode(',', array_map(function($k) { return "\"$k\"=?"; }, array_keys($data)));
    db_exec($pdo, "UPDATE \"$table\" SET $sets WHERE $where", array_merge(array_values($data), $wp));
}

function db_delete(PDO $pdo, string $table, string $where, array $params = []): void {
    db_exec($pdo, "DELETE FROM \"$table\" WHERE $where", $params);
}

function db_count(PDO $pdo, string $table, string $where = '1', array $params = []): int {
    return (int)db_exec($pdo, "SELECT COUNT(*) FROM \"$table\" WHERE $where", $params)->fetchColumn();
}
