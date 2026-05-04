<?php
/**
 * WAF Redis 连接管理
 * PHP 7.2+
 *
 * 特性：
 *   - pconnect 持久连接，进程内复用
 *   - 连接失败自动标记，本次请求不再重试
 *   - 未启用 / 扩展缺失 / 连接失败 → 均返回 null，业务层无感知降级
 *   - instanceKey() 生成各站独立 key
 *   - sharedKey()   生成全站共享 key
 */
class WafRedis
{
    /** @var \Redis|null 持久连接实例（进程级复用） */
    private static $instance = null;

    /** @var bool 本次请求内连接是否已失败，失败后不再重试 */
    private static $failed = false;

    /** @var string|null 缓存的实例ID */
    private static $instanceId = null;

    // -------------------------------------------------------------------------
    // 连接管理
    // -------------------------------------------------------------------------

    /**
     * 获取 Redis 连接，失败返回 null
     */
    public static function get(): ?\Redis
    {
        if (self::$failed) return null;
        if (self::$instance !== null) return self::$instance;

        // 未启用
        if (!defined('WAF_REDIS_ENABLED') || !WAF_REDIS_ENABLED) return null;

        // PHP redis 扩展未安装
        if (!extension_loaded('redis')) return null;

        try {
            $host    = defined('WAF_REDIS_HOST')    ? WAF_REDIS_HOST    : '127.0.0.1';
            $port    = defined('WAF_REDIS_PORT')    ? WAF_REDIS_PORT    : 6379;
            $timeout = defined('WAF_REDIS_TIMEOUT') ? WAF_REDIS_TIMEOUT : 0.5;
            $auth    = defined('WAF_REDIS_AUTH')    ? WAF_REDIS_AUTH    : '';
            $db      = defined('WAF_REDIS_DB')      ? WAF_REDIS_DB      : 0;

            $r = new \Redis();

            // 使用 connect() 而非 pconnect()
            // pconnect 持久连接在 PHP-FPM 进程内跨请求复用，当网站程序与披风
            // 使用相同 Redis 服务但不同 DB 时，连接池的 SELECT 状态会互相污染。
            // connect() 每次请求独立建连，完全隔离，消除串库问题。
            // 对 localhost Redis 而言建连开销 < 0.5ms，影响可忽略。
            $connected = @$r->connect($host, (int)$port, (float)$timeout);

            if (!$connected) {
                self::$failed = true;
                return null;
            }

            if ($auth !== '') {
                @$r->auth($auth);
            }

            @$r->select((int)$db);

            // 设置序列化为 none（存字符串，不需要自动序列化）
            $r->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);

            self::$instance = $r;

        } catch (\Exception $e) {
            self::$failed = true;
            return null;
        } catch (\Error $e) {
            self::$failed = true;
            return null;
        }

        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Key 生成
    // -------------------------------------------------------------------------

    /**
     * 获取当前实例ID
     * 优先使用 config.php 中定义的 WAF_INSTANCE_ID，否则自动生成
     */
    public static function getInstanceId(): string
    {
        if (self::$instanceId !== null) return self::$instanceId;

        if (defined('WAF_INSTANCE_ID') && WAF_INSTANCE_ID !== '') {
            self::$instanceId = WAF_INSTANCE_ID;
        } else {
            $path = isset($_ENV['WAF_CONFIG_PATH']) ? $_ENV['WAF_CONFIG_PATH'] : __DIR__;
            self::$instanceId = 'auto_' . substr(md5($path), 0, 12);
        }

        return self::$instanceId;
    }

    /**
     * 生成各站独立 key（IP白名单等各站数据不同）
     * 格式：waf:{instanceId}:{suffix}
     */
    public static function instanceKey(string $suffix): string
    {
        return 'waf:' . self::getInstanceId() . ':' . $suffix;
    }

    /**
     * 生成全站共享 key（设备识别结果所有站通用）
     * 格式：waf:shared:{suffix}
     */
    public static function sharedKey(string $suffix): string
    {
        return 'waf:shared:' . $suffix;
    }

    // -------------------------------------------------------------------------
    // 工具方法
    // -------------------------------------------------------------------------

    /**
     * 检查 Redis 是否可用
     */
    public static function available(): bool
    {
        return self::get() !== null;
    }

    /**
     * 清除当前实例的连接缓存（用于测试）
     */
    public static function reset(): void
    {
        self::$instance   = null;
        self::$failed     = false;
        self::$instanceId = null;
    }
}
