<?php
/**
 * IP列表自动更新模块
 * 每2分钟从远程URL获取最新的蜘蛛IP列表
 * 使用文件锁防止并发更新
 */

class IPAutoUpdate {
    // IP列表远程URL
    private static $remote_url = 'http://ip.3306.site/data/all.txt';
    
    // 本地IP文件路径
    private static $local_ip_file = __DIR__ . '/ip.txt';
    
    // 时间戳缓存文件
    private static $timestamp_file = __DIR__ . '/ip_update_time.lock';
    
    // 更新间隔（秒）- 2分钟
    private static $update_interval = 120;
    
    /**
     * 检查并更新IP列表（如果需要）
     * @return bool 是否成功更新
     */
    public static function checkAndUpdate() {
        // 检查是否需要更新
        if (!self::needsUpdate()) {
            return false;
        }
        
        // 尝试获取锁并更新
        return self::updateWithLock();
    }
    
    /**
     * 检查是否需要更新
     * @return bool
     */
    private static function needsUpdate() {
        // 如果时间戳文件不存在，需要更新
        if (!file_exists(self::$timestamp_file)) {
            return true;
        }
        
        // 读取最后更新时间
        $last_update_time = (int)file_get_contents(self::$timestamp_file);
        $current_time = time();
        
        // 如果超过2分钟，需要更新
        return ($current_time - $last_update_time) >= self::$update_interval;
    }
    
    /**
     * 使用文件锁进行更新
     * @return bool
     */
    private static function updateWithLock() {
        // 打开锁文件（如果不存在会创建）
        $lock_file = fopen(self::$timestamp_file, 'c+');
        
        if (!$lock_file) {
            // error_log('[IP更新] 无法打开锁文件');
            return false;
        }
        
        // 尝试获取排他锁（非阻塞）
        if (!flock($lock_file, LOCK_EX | LOCK_NB)) {
            // 获取锁失败，说明其他进程正在更新
            fclose($lock_file);
            return false;
        }
        
        try {
            // 再次检查是否需要更新（双重检查锁）
            fseek($lock_file, 0);
            $content = fread($lock_file, 64);
            $last_update_time = $content ? (int)$content : 0;
            $current_time = time();
            
            if (($current_time - $last_update_time) < self::$update_interval) {
                // 其他进程已经更新过了
                flock($lock_file, LOCK_UN);
                fclose($lock_file);
                return false;
            }
            
            // 执行实际更新
            $result = self::performUpdate();
            
            if ($result) {
                // 更新成功，写入新的时间戳
                ftruncate($lock_file, 0);
                fseek($lock_file, 0);
                fwrite($lock_file, (string)$current_time);
                // 设置锁文件权限为 775
                @chmod(self::$timestamp_file, 0775);
                // error_log('[IP更新] 更新成功 - ' . date('Y-m-d H:i:s'));
            } else {
                // error_log('[IP更新] 更新失败');
            }
            
            // 释放锁
            flock($lock_file, LOCK_UN);
            fclose($lock_file);
            
            return $result;
            
        } catch (Exception $e) {
            // 异常处理，确保锁被释放
            flock($lock_file, LOCK_UN);
            fclose($lock_file);
            // error_log('[IP更新] 异常: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 执行实际的IP列表更新
     * @return bool
     */
    private static function performUpdate() {
        // 设置超时时间
        $context = stream_context_create([
            'http' => [
                'timeout' => 10, // 10秒超时
                'user_agent' => 'Mozilla/5.0 (compatible; Spider IP Updater/1.0)',
                'follow_location' => 1,
                'max_redirects' => 3
            ]
        ]);
        
        // 从远程URL获取IP列表
        $content = @file_get_contents(self::$remote_url, false, $context);
        
        if ($content === false || empty($content)) {
            // error_log('[IP更新] 无法获取远程IP列表: ' . self::$remote_url);
            return false;
        }
        
        // 处理获取到的内容
        $lines = explode("\n", trim($content));
        $valid_ips = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // 跳过空行和注释行
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            
            // 验证IP格式
            if (self::isValidIP($line)) {
                $valid_ips[] = $line;
            }
        }
        
        if (empty($valid_ips)) {
            // error_log('[IP更新] 远程列表中没有有效的IP');
            return false;
        }
        
        // 备份旧的IP文件
        if (file_exists(self::$local_ip_file)) {
            $backup_file = self::$local_ip_file . '.bak';
            @copy(self::$local_ip_file, $backup_file);
            // 备份文件也设置权限为 775
            @chmod($backup_file, 0775);
        }
        
        // 写入新的IP列表
        $new_content = implode("\n", $valid_ips);
        $write_result = file_put_contents(self::$local_ip_file, $new_content, LOCK_EX);
        
        if ($write_result === false) {
            // error_log('[IP更新] 写入IP文件失败');
            return false;
        }
        
        // 设置文件权限为 775
        if (!@chmod(self::$local_ip_file, 0775)) {
            // error_log('[IP更新] 警告: 无法设置文件权限为775');
        }
        
        // error_log('[IP更新] 成功更新 ' . count($valid_ips) . ' 个IP段');
        return true;
    }
    
    /**
     * 验证IP格式是否有效
     * @param string $ip
     * @return bool
     */
    private static function isValidIP($ip) {
        // 检查基本格式
        $parts = explode('.', $ip);
        
        if (count($parts) !== 4) {
            return false;
        }
        
        foreach ($parts as $part) {
            if (!is_numeric($part) || $part < 0 || $part > 255) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 强制立即更新（用于测试或手动触发）
     * @return bool
     */
    public static function forceUpdate() {
        // 删除时间戳文件，强制更新
        if (file_exists(self::$timestamp_file)) {
            @unlink(self::$timestamp_file);
        }
        
        return self::checkAndUpdate();
    }
    
    /**
     * 获取最后更新时间
     * @return string
     */
    public static function getLastUpdateTime() {
        if (!file_exists(self::$timestamp_file)) {
            return '从未更新';
        }
        
        $timestamp = (int)file_get_contents(self::$timestamp_file);
        if ($timestamp === 0) {
            return '从未更新';
        }
        
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    /**
     * 获取下次更新时间
     * @return string
     */
    public static function getNextUpdateTime() {
        if (!file_exists(self::$timestamp_file)) {
            return '即将更新';
        }
        
        $timestamp = (int)file_get_contents(self::$timestamp_file);
        if ($timestamp === 0) {
            return '即将更新';
        }
        
        $next_time = $timestamp + self::$update_interval;
        $remaining = $next_time - time();
        
        if ($remaining <= 0) {
            return '即将更新';
        }
        
        return date('Y-m-d H:i:s', $next_time) . ' (剩余 ' . ceil($remaining / 60) . ' 分钟)';
    }
}

// 自动执行更新检查（静默模式）
IPAutoUpdate::checkAndUpdate();
?>

