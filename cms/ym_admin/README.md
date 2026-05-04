# ym_admin — 域名资产管理后台

一套基于 **PHP + SQLite + Bootstrap 5** 的自托管域名台账系统，用于集中记录和运维大量域名的注册信息、到期状态、DNS 接入及备案情况。

---

## 功能模块

| 模块 | 说明 |
|------|------|
| **域名列表** | 多条件筛选（状态、注册商、备案、标签、自定义字段、到期区间）、分页、统计卡片、CSV 导出、批量操作 |
| **批量导入** | 支持粘贴 CSV 行或上传文件，自动映射中英文字段，异步任务执行，跳转进度页 |
| **域名卡片** | 将域名归入自定义分组卡片，统计各卡片内正常 / 暂停 / 过期 / 7 天内到期数量，支持重复检测 |
| **DNS 管理** | 集成 DNS-LA API，管理域名解析账号、域名列表、解析记录，异步批量操作 |
| **后台任务** | 长耗时操作（导入、清空、DNS 批量等）通过任务队列异步执行，任务列表可查进度 |
| **自定义字段** | 在系统字段之外自定义扩展字段，存储于 SQLite JSON 列，列表/筛选全支持 |
| **账号管理** | 记录注册商账号，域名与账号关联 |

---

## 技术栈

- **后端**：PHP（无框架，多文件入口）
- **数据库**：SQLite（通过 PDO，主库 `data/master.db`）
- **前端**：Bootstrap 5.3 + Bootstrap Icons（CDN）
- **外部 API**：DNS-LA（`https://api.dns.la`，Basic Auth）
- **PHP 扩展要求**：`pdo_sqlite`、`curl`、`json`、`mbstring`

---

## 目录结构

```
ym_admin/
├── index.php              # 入口，重定向到 /domains/
├── config/
│   └── config.php         # 站点名、密码 Hash、数据库路径、分页等常量
├── core/
│   ├── db.php             # SQLite 连接、表结构初始化、通用 CRUD
│   ├── domain.php         # 域名业务逻辑（标签、历史、筛选、统计）
│   ├── import.php         # 导入解析逻辑
│   ├── job.php            # 任务队列管理
│   ├── agg.php            # 聚合统计缓存
│   ├── functions.php      # 公共函数
│   ├── helpers.php        # 辅助函数
│   └── meta.php           # 元数据处理
├── components/
│   ├── header.php         # 登录校验 + 侧边栏导航
│   └── footer.php
├── auth/
│   ├── login.php          # Session 登录（bcrypt 密码验证）
│   └── logout.php
├── domains/               # 域名列表、详情、编辑、API
├── admin/                 # 另一套域名管理路由（/admin/）
├── batch/                 # 批量导入 / 查询 / 续费 / 修改
├── cards/                 # 域名卡片与聚合统计 API
├── dns/
│   ├── index.php          # DNS 模块首页
│   └── dns_la/            # DNS-LA 账号、域名、记录管理
├── jobs/                  # 任务列表、进度查看、API
├── api/                   # 全局 API（汇总统计、导出、清空等）
├── worker/
│   └── run.php            # 任务异步执行 Worker
├── assets/
│   ├── css/style.css
│   └── js/app.js
└── data/                  # 运行时数据目录（SQLite 库、JSON 账号、缓存）
    ├── master.db          # 主数据库（自动创建）
    └── dns_la_accounts.json   # DNS-LA 账号配置
```

---

## 快速部署

### 环境要求

- PHP 7.4+（推荐 8.x）
- 启用扩展：`pdo_sqlite`、`curl`、`mbstring`
- Web 服务器：Nginx / Apache / PHP 内置服务器均可

### 1. 上传项目

将整个 `ym_admin/` 目录上传到服务器 Web 根目录下，例如：

```
/www/wwwroot/ym_admin/
```

### 2. 修改配置

编辑 `config/config.php`：

```php
define('SITE_NAME', '域名管理系统');  // 站点名称

// 登录密码 Hash（使用下方命令生成）
define('ADMIN_PASSWORD_HASH', '$2y$10$...');
```

生成密码 Hash（在服务器 PHP CLI 执行）：

```bash
php -r "echo password_hash('你的密码', PASSWORD_DEFAULT);"
```

### 3. 确保 data 目录可写

```bash
chmod 755 /www/wwwroot/ym_admin/data
```

### 4. Nginx 配置参考

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /www/wwwroot/ym_admin;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 禁止直接访问数据目录
    location /data/ {
        deny all;
    }
}
```

### 5. 后台任务 Worker（可选）

若需要异步任务功能，添加 Cron 定时执行 Worker：

```bash
# 每分钟执行一次
* * * * * php /www/wwwroot/ym_admin/worker/run.php >> /var/log/ym_admin_worker.log 2>&1
```

---

## DNS-LA 配置

在 `data/dns_la_accounts.json` 中填写账号信息：

```json
[
  {
    "id": 1,
    "name": "账号名称",
    "api_id": "your_api_id",
    "api_secret": "your_api_secret",
    "api_base": "https://api.dns.la"
  }
]
```

---

## 到期预警规则

在 `config/config.php` 中调整：

```php
define('WARN_DAYS_RED',    7);   // 7 天内到期 → 红色高亮
define('WARN_DAYS_YELLOW', 30);  // 30 天内到期 → 黄色高亮
```

---

## 安全建议

- 修改默认密码，使用强密码
- 建议通过 IP 白名单限制后台访问
- `data/` 目录禁止 Web 直接访问（见 Nginx 配置）
- 定期备份 `data/master.db`

---

## License

MIT
