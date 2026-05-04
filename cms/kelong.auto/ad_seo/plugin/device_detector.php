<?php
/**
 * Device Detector — 两阶段识别
 *
 * 阶段一：device_data.json 关键词/型号匹配（快速）
 * 阶段二：matomo/device-detector 正则引擎（全面兜底）
 *
 * 返回: mobile | tablet | desktop | bot
 */

class DeviceDetector {

    private $userAgent;
    private static $db        = null;  // device_data.json 缓存（进程级）
    private static $mdd       = [];    // Matomo DeviceDetector 对象缓存（进程级）
    private static $typeCache = [];    // 最终类型字符串缓存（进程级，所有来源统一存这里）

    public function __construct(?string $userAgent = null) {
        $this->userAgent = $userAgent ?? $_SERVER['HTTP_USER_AGENT'] ?? '';
        self::loadDB();
    }

    // ── JSON 加载（进程内只读一次）────────────────────────────

    private static function loadDB(): void {
        if (self::$db !== null) return;
        $raw = @file_get_contents(__DIR__ . '/device_data.json');
        self::$db = $raw ? (json_decode($raw, true) ?? []) : [];
    }

    // ── 公开接口 ──────────────────────────────────────────────

    /**
     * 快速返回设备类型: mobile | tablet | desktop | bot
     */
    public function getDeviceType(): string {
        return $this->detect()['type'];
    }

    /**
     * 完整检测结果（三级缓存：L1进程内 → L2 Redis → JSON识别 → Matomo）
     */
    public function detect(): array {
        $ua = $this->userAgent;

        // ── L1：进程内缓存（0ms，同进程内所有请求复用）──────────
        if (isset(self::$typeCache[$ua])) {
            return $this->result(self::$typeCache[$ua], 'l1_cache', 'high', []);
        }

        // ── L2：Redis 缓存（跨进程跨实例共享，~0.1ms）───────────
        $redis   = class_exists('WafRedis') ? WafRedis::get() : null;
        $uaKey   = null;
        $uaHash  = null;
        if ($redis) {
            $uaHash = md5($ua);
            $uaKey  = WafRedis::sharedKey('ua:' . $uaHash);
            $cached = $redis->get($uaKey);
            if ($cached !== false && $cached !== null && $cached !== '') {
                self::$typeCache[$ua] = $cached;
                return $this->result($cached, 'redis_cache', 'high', []);
            }
        }

        // ── 阶段一：JSON 关键词匹配（内存操作，极快）────────────
        $result = $this->detectByJSON();
        if ($result !== null) {
            $type = $result['type'];
            self::$typeCache[$ua] = $type;
            // JSON 命中也写入 Redis，让下一个进程直接走 L2 跳过 JSON 解析
            if ($redis && $uaKey) {
                $redis->setEx($uaKey, 300, $type);
            }
            return $result;
        }

        // ── 阶段二：Matomo（带分布式锁，只让一个进程跑）─────────
        if ($redis && $uaHash) {
            $lockKey = WafRedis::sharedKey('lock:ua:' . $uaHash);
            $gotLock = $redis->set($lockKey, 1, ['NX', 'EX' => 15]);

            if ($gotLock) {
                // 抢到锁：跑 Matomo，写 Redis，释放锁
                $result = $this->runMatomo();
                $type   = $result['type'];
                $redis->setEx($uaKey, 300, $type);
                $redis->del($lockKey);
                self::$typeCache[$ua] = $type;
                return $result;
            }

            // 没抢到锁：不等待，返回兜底值
            self::$typeCache[$ua] = 'desktop';
            return $this->result('desktop', 'fallback_no_lock', 'low', []);
        }

        // ── 无 Redis：直接跑 Matomo ──────────────────────────────
        $result = $this->runMatomo();
        self::$typeCache[$ua] = $result['type'];
        return $result;
    }

    public static function quickDetect(?string $ua = null): string {
        return (new self($ua))->getDeviceType();
    }

    public static function fullDetect(?string $ua = null): array {
        return (new self($ua))->detect();
    }

    // ── 阶段一：device_data.json 识别 ─────────────────────────

    private function detectByJSON(): ?array {
        $ua  = strtolower($this->userAgent);
        $db  = self::$db;

        // 1. Bot 优先（防止被关键词误判为移动/桌面）
        if ($this->matchList($ua, $db['bot_keywords'] ?? [])) {
            return $this->result('bot', 'json', 'high', [
                'bot_type' => $this->botType($ua),
            ]);
        }

        // 2. 苹果设备（UA 含苹果特征词才进入）
        $appleResult = $this->detectAppleByJSON($ua);
        if ($appleResult !== null) return $appleResult;

        // 3. 平板（早于手机，Android 平板无 mobile 字样）
        if ($this->isTabletUA($ua)) {
            return $this->result('tablet', 'json', 'high', [
                'brand' => $this->lookupBrand($ua, 'tablet_brands'),
            ]);
        }

        // 4. 手机
        if ($this->isMobileUA($ua)) {
            return $this->result('mobile', 'json', 'high', [
                'brand'      => $this->lookupBrand($ua, 'mobile_brands'),
                'os_version' => $this->mobileOSVersion(),
            ]);
        }

        // 5. 桌面
        if ($this->matchList($ua, $db['desktop_keywords'] ?? [])) {
            return $this->result('desktop', 'json', 'medium', [
                'platform' => $this->desktopPlatform(),
            ]);
        }

        // 没有任何关键词命中 → 交给 matomo
        return null;
    }

    // ── 苹果设备细分（JSON）────────────────────────────────────

    private function detectAppleByJSON(string $ua): ?array {
        $apple = self::$db['apple'] ?? [];

        $isApple = false;
        foreach ($apple['indicators'] ?? [] as $kw) {
            if (stripos($this->userAgent, $kw) !== false) { $isApple = true; break; }
        }
        if (!$isApple) return null;

        // iPhone
        if (stripos($this->userAgent, 'iPhone') !== false) {
            $info = $this->matchAppleModel('iphone_models', '/iPhone(\d+,\d+)/');
            return $this->result('mobile', 'json', 'very_high', array_merge(
                ['brand' => 'Apple', 'category' => 'smartphone'],
                $info
            ));
        }

        // iPad
        if (stripos($this->userAgent, 'iPad') !== false) {
            $info = $this->matchAppleModel('ipad_models', '/iPad(\d+,\d+)/');
            return $this->result('tablet', 'json', 'very_high', array_merge(
                ['brand' => 'Apple', 'category' => 'tablet'],
                $info
            ));
        }

        // Apple Watch → 移动
        if (stripos($this->userAgent, 'Apple Watch') !== false
         || stripos($this->userAgent, 'watchOS') !== false) {
            return $this->result('mobile', 'json', 'very_high', [
                'brand' => 'Apple', 'category' => 'wearable',
            ]);
        }

        // Apple TV → 桌面
        foreach ($apple['appletv_keywords'] ?? [] as $kw) {
            if (stripos($this->userAgent, $kw) !== false) {
                return $this->result('desktop', 'json', 'very_high', [
                    'brand' => 'Apple', 'category' => 'set_top_box',
                ]);
            }
        }

        // Mac / Macintosh
        if (stripos($this->userAgent, 'Macintosh') !== false
         || stripos($this->userAgent, 'Mac OS X') !== false) {
            $info = $this->matchAppleModel('mac_models', '/(' . implode('|', array_map(
                'preg_quote', array_keys($apple['mac_models'] ?? [])
            )) . ')/');
            return $this->result('desktop', 'json', 'very_high', array_merge(
                ['brand' => 'Apple', 'category' => 'desktop'],
                $info
            ));
        }

        // 苹果设备但无法细分 → matomo 兜底
        return null;
    }

    private function matchAppleModel(string $modelKey, string $pattern): array {
        $models = self::$db['apple'][$modelKey] ?? [];
        if (preg_match($pattern, $this->userAgent, $m)) {
            // iPhone/iPad pattern 需要拼前缀
            $prefix = strpos($modelKey, 'iphone') !== false ? 'iPhone'
                    : (strpos($modelKey, 'ipad') !== false ? 'iPad' : '');
            $key = $prefix . ($m[1] ?? $m[0]);
            if (isset($models[$key])) {
                return [
                    'model'            => $models[$key]['name'],
                    'model_identifier' => $key,
                    'year'             => $models[$key]['year'],
                ];
            }
        }
        return [];
    }

    // ── 阶段二：Matomo 识别（仅在 L1/Redis 全部未命中时调用）──

    private function runMatomo(): array {
        $ua = $this->userAgent;

        if (!isset(self::$mdd[$ua])) {
            $autoload = __DIR__ . '/vendor/autoload.php';
            if (!file_exists($autoload)) {
                return $this->result('desktop', 'fallback', 'low', []);
            }
            require_once $autoload;

            // 子类覆盖规则文件路径，使 mobiles.yml 直接放在 plugin/ 根目录
            $pluginDir    = __DIR__;
            $mobileParser = new class($pluginDir) extends \DeviceDetector\Parser\Device\Mobile {
                private $dir;
                public function __construct(string $dir) { $this->dir = $dir; }
                protected function getRegexesDirectory(): string { return $this->dir; }
            };
            (function () { $this->fixtureFile = 'mobiles.yml'; })->call($mobileParser);

            $dd = new \DeviceDetector\DeviceDetector($ua);
            $dd->setCache(new \DeviceDetector\Cache\StaticCache());
            $dd->setDeviceParsers([$mobileParser]);
            $dd->setBotParsers([]);
            $dd->setClientParsers([]);
            $dd->parse();

            self::$mdd[$ua] = $dd;
        }

        $dd   = self::$mdd[$ua];
        $type = $this->matomoType($dd);
        $os   = $dd->getOs();

        return $this->result($type, 'matomo', 'very_high', [
            'brand'       => $dd->getBrandName() ?: 'unknown',
            'model'       => $dd->getModel()     ?: 'unknown',
            'device_name' => $dd->getDeviceName() ?: 'unknown',
            'os'          => ['name' => $os['name'] ?? 'unknown', 'version' => $os['version'] ?? 'unknown'],
        ]);
    }

    // 向后兼容（detect() 已整合，此方法保留为内部别名）
    private function detectByMatomo(): array {
        return $this->runMatomo();
    }

    private function matomoType(\DeviceDetector\DeviceDetector $dd): string {
        $name = strtolower($dd->getDeviceName() ?: '');
        if (in_array($name, ['smartphone', 'feature phone', 'phablet', 'wearable'], true)) return 'mobile';
        if (in_array($name, ['tablet', 'portable media player'], true)) return 'tablet';
        if ($dd->isMobile()) return 'mobile';
        if ($dd->isTablet()) return 'tablet';
        // JSON 阶段没命中且 matomo 也不认识 → 默认桌面
        return 'desktop';
    }

    // ── 关键词匹配工具 ─────────────────────────────────────────

    private function matchList(string $ua, array $list): bool {
        foreach ($list as $kw) {
            if (strpos($ua, strtolower($kw)) !== false) return true;
        }
        return false;
    }

    private function isTabletUA(string $ua): bool {
        if ($this->matchList($ua, self::$db['tablet_keywords'] ?? [])) return true;
        return strpos($ua, 'android') !== false && strpos($ua, 'mobile') === false;
    }

    private function isMobileUA(string $ua): bool {
        if ($this->isTabletUA($ua)) return false;
        if ($this->matchList($ua, self::$db['mobile_keywords'] ?? [])) return true;
        return (bool)preg_match('/android.*mobile/i', $ua);
    }

    // ── 辅助信息提取 ──────────────────────────────────────────

    private function lookupBrand(string $ua, string $key): string {
        foreach (self::$db[$key] ?? [] as $kw => $brand) {
            if (stripos($this->userAgent, $kw) !== false) return $brand;
        }
        return 'unknown';
    }

    private function desktopPlatform(): string {
        $ua = $this->userAgent;
        if (stripos($ua, 'Windows')   !== false) return 'Windows';
        if (stripos($ua, 'Macintosh') !== false) return 'macOS';
        if (stripos($ua, 'CrOS')      !== false) return 'ChromeOS';
        if (stripos($ua, 'Linux')     !== false) return 'Linux';
        return 'unknown';
    }

    private function botType(string $ua): string {
        foreach (self::$db['bot_types'] ?? [] as $kw => $type) {
            if (strpos($ua, strtolower($kw)) !== false) return $type;
        }
        return 'unknown';
    }

    private function mobileOSVersion(): string {
        foreach (self::$db['mobile_os_patterns'] ?? [] as $os => $pattern) {
            if (@preg_match($pattern, $this->userAgent, $m)) {
                return $os . ' ' . $m[1] . (isset($m[2]) ? '.' . $m[2] : '');
            }
        }
        return 'unknown';
    }

    private function result(string $type, string $method, string $confidence, array $details): array {
        return [
            'type'       => $type,
            'method'     => $method,
            'confidence' => $confidence,
            'details'    => array_merge(['user_agent' => $this->userAgent], $details),
        ];
    }
}

// 向后兼容全局函数
function detectDeviceType(): string { return DeviceDetector::quickDetect(); }
function getDeviceInfo(): array     { return DeviceDetector::fullDetect(); }
