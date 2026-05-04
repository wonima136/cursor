<?php
/**
 * 日志记录器
 * 每个模式独立日志 + 总日志
 */

// 避免重复声明
if (class_exists('Logger')) {
    return;
}

class Logger {
    private $logDir;
    private $logFile;
    private $maxSize = 104857600; // 100MB
    
    // 明确声明为 public 构造函数
    public function __construct($logName = 'all') {
        $baseDir = dirname(__DIR__);
        $this->logDir = $baseDir . '/logs';
        
        // 创建日志目录
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
        
        $this->logFile = $this->logDir . '/' . $logName . '.txt';
    }
    
    /**
     * 记录日志
     */
    public function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
        
        // 检查文件大小，超过100MB则清空
        if (file_exists($this->logFile) && filesize($this->logFile) > $this->maxSize) {
            file_put_contents($this->logFile, '');
        }
        
        // 追加日志
        @file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        
        // 同时写入总日志（避免递归）
        if (basename($this->logFile) !== 'all.txt') {
            $allLogger = new Logger('all');
            $allLogger->write($logMessage);
        }
    }
    
    /**
     * 直接写入（不重复写all.log）
     */
    private function write($message) {
        if (file_exists($this->logFile) && filesize($this->logFile) > $this->maxSize) {
            file_put_contents($this->logFile, '');
        }
        @file_put_contents($this->logFile, $message, FILE_APPEND);
    }
    
    public function info($message) {
        $this->log($message, 'INFO');
    }
    
    public function error($message) {
        $this->log($message, 'ERROR');
    }
    
    public function debug($message) {
        $this->log($message, 'DEBUG');
    }
    
    public function warning($message) {
        $this->log($message, 'WARNING');
    }
}
