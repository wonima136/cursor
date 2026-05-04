<?php
/**
 * 后台任务队列管理器
 * 用于处理批量域名创建等耗时任务
 * 
 * 功能:
 * 1. 创建后台任务
 * 2. 进程管理（启动/停止/查询）
 * 3. 进度跟踪
 * 4. 失败回滚（删除已创建的数据）
 */

class TaskQueueManager {
    private $base_dir;
    private $tasks_dir;
    private $tasks_file;
    private $processes_dir;
    private $logs_dir;
    
    // 任务状态
    const STATUS_PENDING = 'pending';      // 等待执行
    const STATUS_RUNNING = 'running';      // 执行中
    const STATUS_COMPLETED = 'completed';  // 已完成
    const STATUS_FAILED = 'failed';        // 失败
    const STATUS_STOPPED = 'stopped';      // 已停止（手动中止）
    const STATUS_ROLLBACK = 'rollback';    // 回滚中
    
    // 任务类型
    const TYPE_BATCH_CREATE_DOMAIN = 'batch_create_domain';  // 批量创建域名
    const TYPE_BATCH_DELETE_DOMAIN = 'batch_delete_domain';  // 批量删除域名
    const TYPE_BATCH_UPDATE_CACHE = 'batch_update_cache';    // 批量更新缓存
    const TYPE_CLEAR_ALL_GROUPS = 'clear_all_groups';        // 清空所有分组
    
    public function __construct() {
        $this->base_dir = dirname(__DIR__);
        $this->tasks_dir = $this->base_dir . '/data/tasks';
        $this->tasks_file = $this->tasks_dir . '/tasks.json';
        $this->processes_dir = $this->tasks_dir . '/processes';
        $this->logs_dir = $this->tasks_dir . '/logs';
        
        $this->ensureDirectories();
    }
    
    /**
     * 确保目录存在
     */
    private function ensureDirectories() {
        $dirs = [
            $this->tasks_dir,
            $this->processes_dir,
            $this->logs_dir,
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        
        // 初始化tasks.json
        if (!file_exists($this->tasks_file)) {
            file_put_contents($this->tasks_file, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }
    
    /**
     * 创建新任务
     * @param string $type 任务类型
     * @param array $data 任务数据
     * @param string $description 任务描述
     * @return string 任务ID
     */
    public function createTask($type, $data, $description = '') {
        $task_id = $this->generateTaskId();
        
        $task = [
            'id' => $task_id,
            'type' => $type,
            'status' => self::STATUS_PENDING,
            'description' => $description,
            'data' => $data,
            'progress' => [
                'total' => 0,
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'percent' => 0
            ],
            'created_items' => [],  // 记录已创建的项目，用于回滚
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'started_at' => null,
            'completed_at' => null,
            'error' => null,
            'pid' => null  // 进程ID
        ];
        
        // 保存任务到文件
        $this->saveTask($task);
        
        // 创建进程状态文件
        $this->createProcessFile($task_id);
        
        return $task_id;
    }
    
    /**
     * 🚀 创建轻量级任务（数据存在外部文件，提升创建速度）
     * @param string $task_id 任务ID
     * @param string $type 任务类型
     * @param string $dataFile 数据文件路径
     * @param string $description 任务描述
     * @return string 任务ID
     */
    public function createLightweightTask($task_id, $type, $dataFile, $description = '') {
        $task = [
            'id' => $task_id,
            'type' => $type,
            'status' => self::STATUS_PENDING,
            'description' => $description,
            'data_file' => $dataFile,  // 只保存文件路径
            'data' => null,  // 不保存大数据
            'progress' => [
                'total' => 0,
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'percent' => 0
            ],
            'created_items' => [],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'started_at' => null,
            'completed_at' => null,
            'error' => null,
            'pid' => null
        ];
        
        // 保存任务到文件（轻量级）
        $this->saveTask($task);
        
        // 创建进程状态文件
        $this->createProcessFile($task_id);
        
        return $task_id;
    }
    
    /**
     * 启动任务执行
     * @param string $task_id 任务ID
     * @param bool $updateStatus 是否更新状态到文件（批量启动时可设为false）
     * @return bool
     */
    public function startTask($task_id, $updateStatus = true) {
        $task = $this->getTask($task_id);
        if (!$task) {
            return false;
        }
        
        // 检查任务状态
        if ($task['status'] !== self::STATUS_PENDING) {
            return false;
        }
        
        // 尝试启动后台进程
        $started = $this->executeTaskInBackground($task_id);
        
        if (!$started) {
            // ⚠️ 注意: 由于服务器限制，无法自动启动后台进程
            // 保持 pending 状态，等待前端通过 AJAX 调用 task_executor.php
            $this->log($task_id, '⚠️ 无法自动启动，请通过 task_executor.php 手动执行');
            return false;
        }
        
        // 🚀 可选：更新任务状态（批量启动时可跳过，减少文件IO）
        if ($updateStatus) {
            $task['status'] = self::STATUS_RUNNING;
            $task['started_at'] = date('Y-m-d H:i:s');
            $task['updated_at'] = date('Y-m-d H:i:s');
            $this->saveTask($task);
        }
        
        return true;
    }
    
    /**
     * 在后台执行任务
     * @param string $task_id
     */
    private function executeTaskInBackground($task_id) {
        // 检查是否有可用的进程执行函数
        $hasExec = function_exists('exec') && !$this->isFunctionDisabled('exec');
        $hasPopen = function_exists('popen') && !$this->isFunctionDisabled('popen');
        $hasProcOpen = function_exists('proc_open') && !$this->isFunctionDisabled('proc_open');
        
        if (!$hasExec && !$hasPopen && !$hasProcOpen) {
            // 所有函数都被禁用
            $this->log($task_id, "⚠️ 注意: 服务器禁用了进程执行函数");
            $this->log($task_id, "请通过前端 AJAX 调用 task_executor.php?task_id={$task_id} 来执行任务");
            $this->log($task_id, "⚠️ 无法自动启动，请通过 task_executor.php 手动执行");
            return false;
        }
        
        // 获取PHP可执行文件路径
        $phpPath = $this->getPhpPath();
        $workerPath = $this->base_dir . '/inc/TaskWorker.php';
        
        // 构建命令
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($isWindows) {
            // Windows: 使用 popen 启动后台进程（exec在Windows下可能阻塞）
            $command = "\"{$phpPath}\" \"{$workerPath}\" {$task_id}";
            $this->log($task_id, "启动后台任务 (Windows): {$command}");
            
            if ($hasPopen) {
                // 使用popen异步启动，立即关闭句柄
                $handle = @popen("start /B cmd /c \"{$command}\" 2>&1", 'r');
                if ($handle) {
                    @pclose($handle);
                }
            } elseif ($hasExec) {
                // 备用方案：直接exec（可能阻塞）
                @exec("start /B cmd /c \"{$command}\" 2>&1", $output, $returnVar);
            }
        } else {
            // Linux: 使用 & 启动后台进程，并重定向输出到日志
            $logFile = $this->tasks_dir . "/{$task_id}.log";
            $command = "\"{$phpPath}\" \"{$workerPath}\" {$task_id} >> \"{$logFile}\" 2>&1 &";
            $this->log($task_id, "启动后台任务 (Linux): {$command}");
            
            if ($hasExec) {
                @exec($command);
            } elseif ($hasPopen) {
                $handle = @popen($command, 'r');
                if ($handle) {
                    @pclose($handle);
                }
            }
        }
        
        $this->log($task_id, "✅ 后台任务已启动");
        return true;
    }
    
    /**
     * 检查函数是否被禁用
     */
    private function isFunctionDisabled($functionName) {
        $disabled = explode(',', ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);
        return in_array($functionName, $disabled);
    }
    
    /**
     * 获取PHP路径
     */
    private function getPhpPath() {
        // 尝试常见的PHP路径
        $paths = [
            'G:/BtSoft/php/74/php.exe',  // 宝塔PHP 7.4
            'G:/BtSoft/php/80/php.exe',  // 宝塔PHP 8.0
            'php',  // 系统PATH中的php
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        return 'php';  // 默认使用系统php
    }
    
    /**
     * 停止任务
     * @param string $task_id
     * @param bool $rollback 是否回滚已创建的数据
     * @return bool
     */
    public function stopTask($task_id, $rollback = true) {
        $task = $this->getTask($task_id);
        if (!$task) {
            return false;
        }
        
        // 只有运行中的任务可以停止
        if ($task['status'] !== self::STATUS_RUNNING) {
            return false;
        }
        
        // 标记为停止状态
        $process_file = $this->getProcessFile($task_id);
        $process_data = [
            'should_stop' => true,
            'stop_time' => time(),
            'rollback' => $rollback
        ];
        file_put_contents($process_file, json_encode($process_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        $this->log($task_id, "任务停止请求已发送，回滚: " . ($rollback ? '是' : '否'));
        
        return true;
    }
    
    /**
     * 强制标记任务为已停止 (用于无响应任务)
     */
    public function markAsStopped($task_id) {
        try {
            $task = $this->getTask($task_id);
            if (!$task) {
                return false;
            }
            
            // 更新任务状态为停止
            $task['status'] = self::STATUS_STOPPED;
            $task['completed_at'] = time();
            $task['updated_at'] = time();
            
            // 保存任务到数据库
            $this->saveTask($task);
            
            // 更新进程文件
            $process_file = $this->getProcessFile($task_id);
            $process_data = [
                'should_stop' => true,
                'stop_time' => time(),
                'force_stopped' => true
            ];
            $result = file_put_contents($process_file, json_encode($process_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            if ($result === false) {
                throw new Exception("无法写入进程文件");
            }
            
            return true;
            
        } catch (Exception $e) {
            // 记录错误但不抛出，返回false
            error_log("markAsStopped错误: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查任务是否应该停止
     * @param string $task_id
     * @return array ['should_stop' => bool, 'rollback' => bool]
     */
    public function shouldStop($task_id) {
        $process_file = $this->getProcessFile($task_id);
        if (!file_exists($process_file)) {
            return ['should_stop' => false, 'rollback' => false];
        }
        
        $data = json_decode(file_get_contents($process_file), true);
        return [
            'should_stop' => isset($data['should_stop']) && $data['should_stop'],
            'rollback' => isset($data['rollback']) && $data['rollback']
        ];
    }
    
    /**
     * 更新任务进度
     * @param string $task_id
     * @param int $processed 已处理数量
     * @param int $success 成功数量
     * @param int $failed 失败数量
     * @param mixed $created_item 新创建的项目（用于回滚）
     */
    public function updateProgress($task_id, $processed, $success, $failed, $created_item = null) {
        $task = $this->getTask($task_id);
        if (!$task) {
            return false;
        }
        
        $task['progress']['processed'] = $processed;
        $task['progress']['success'] = $success;
        $task['progress']['failed'] = $failed;
        
        if ($task['progress']['total'] > 0) {
            $task['progress']['percent'] = round(($processed / $task['progress']['total']) * 100, 2);
        }
        
        // 记录已创建的项目
        if ($created_item !== null) {
            $task['created_items'][] = $created_item;
        }
        
        $task['updated_at'] = date('Y-m-d H:i:s');
        
        $this->saveTask($task);
        
        return true;
    }
    
    /**
     * 设置任务总数
     */
    public function setTotal($task_id, $total) {
        $task = $this->getTask($task_id);
        if (!$task) {
            return false;
        }
        
        $task['progress']['total'] = $total;
        $task['updated_at'] = date('Y-m-d H:i:s');
        $this->saveTask($task);
        
        return true;
    }
    
    /**
     * 完成任务
     */
    public function completeTask($task_id, $error = null) {
        $task = $this->getTask($task_id);
        if (!$task) {
            return false;
        }
        
        $task['status'] = $error ? self::STATUS_FAILED : self::STATUS_COMPLETED;
        $task['completed_at'] = date('Y-m-d H:i:s');
        $task['updated_at'] = date('Y-m-d H:i:s');
        $task['error'] = $error;
        
        $this->saveTask($task);
        $this->log($task_id, "任务完成，状态: " . $task['status']);
        
        return true;
    }
    
    
    /**
     * 回滚任务（删除已创建的数据）
     */
    public function rollbackTask($task_id) {
        $task = $this->getTask($task_id);
        if (!$task) {
            return false;
        }
        
        $this->log($task_id, "开始回滚，删除已创建的数据...");
        
        // 更新状态为回滚中
        $task['status'] = self::STATUS_ROLLBACK;
        $this->saveTask($task);
        
        $deleted_count = 0;
        $failed_count = 0;
        
        // 根据任务类型执行回滚
        if ($task['type'] === self::TYPE_BATCH_CREATE_DOMAIN) {
            // 删除已创建的域名配置文件
            foreach ($task['created_items'] as $item) {
                if (isset($item['domain'])) {
                    $domain_file = $this->base_dir . '/data/domain/' . $item['domain'] . '.json';
                    if (file_exists($domain_file)) {
                        if (unlink($domain_file)) {
                            $deleted_count++;
                            $this->log($task_id, "已删除: {$item['domain']}");
                        } else {
                            $failed_count++;
                            $this->log($task_id, "删除失败: {$item['domain']}");
                        }
                    }
                }
            }
        }
        
        $this->log($task_id, "回滚完成，成功删除: {$deleted_count}，失败: {$failed_count}");
        
        // 清空已创建项目列表
        $task['created_items'] = [];
        $task['status'] = self::STATUS_STOPPED;
        $this->saveTask($task);
        
        return ['deleted' => $deleted_count, 'failed' => $failed_count];
    }
    
    /**
     * 获取任务
     */
    public function getTask($task_id) {
        $tasks = $this->getAllTasks();
        foreach ($tasks as $task) {
            if ($task['id'] === $task_id) {
                return $task;
            }
        }
        return null;
    }
    
    /**
     * 获取所有任务
     */
    public function getAllTasks() {
        if (!file_exists($this->tasks_file)) {
            return [];
        }
        
        $content = file_get_contents($this->tasks_file);
        $tasks = json_decode($content, true);
        
        if (!is_array($tasks)) {
            return [];
        }
        
        // 按创建时间倒序排列
        usort($tasks, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        return $tasks;
    }
    
    /**
     * 获取运行中的任务
     */
    public function getRunningTasks() {
        $tasks = $this->getAllTasks();
        return array_filter($tasks, function($task) {
            return $task['status'] === self::STATUS_RUNNING;
        });
    }
    
    /**
     * 保存任务
     */
    private function saveTask($task) {
        $tasks = $this->getAllTasks();
        
        // 查找并更新任务
        $found = false;
        foreach ($tasks as $i => $t) {
            if ($t['id'] === $task['id']) {
                $tasks[$i] = $task;
                $found = true;
                break;
            }
        }
        
        // 如果是新任务，添加到列表
        if (!$found) {
            $tasks[] = $task;
        }
        
        // 保存到文件（移除PRETTY_PRINT以提升性能）
        file_put_contents($this->tasks_file, json_encode($tasks, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 删除任务
     */
    public function deleteTask($task_id) {
        $tasks = $this->getAllTasks();
        $tasks = array_filter($tasks, function($task) use ($task_id) {
            return $task['id'] !== $task_id;
        });
        
        file_put_contents($this->tasks_file, json_encode(array_values($tasks), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        // 删除进程文件和日志文件
        $process_file = $this->getProcessFile($task_id);
        $log_file = $this->getLogFile($task_id);
        
        if (file_exists($process_file)) {
            unlink($process_file);
        }
        if (file_exists($log_file)) {
            unlink($log_file);
        }
        
        // 🆕 删除任务数据文件 (task_data_*.json)
        // 需要处理任务ID格式不统一的问题
        $taskIdBase = str_replace('task_', '', $task_id);  // 去掉 task_ 前缀
        
        // 尝试多种匹配模式，因为任务ID和文件名格式可能不一致
        $patterns = [
            // 精确匹配完整task_id
            $this->tasks_dir . '/task_data_' . $task_id . '.json',
            $this->tasks_dir . '/task_data_' . $task_id . '_*.json',
            // 去掉task_前缀的匹配
            $this->tasks_dir . '/task_data_task_' . $taskIdBase . '.json',
            $this->tasks_dir . '/task_data_task_' . $taskIdBase . '_*.json',
            // 处理时间格式不统一的问题（插入下划线）
            $this->tasks_dir . '/task_data_*' . substr($taskIdBase, 0, 8) . '_' . substr($taskIdBase, 8) . '*.json',
        ];
        
        $deletedDataFiles = [];
        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            foreach ($files as $dataFile) {
                if (file_exists($dataFile) && !in_array($dataFile, $deletedDataFiles)) {
                    unlink($dataFile);
                    $deletedDataFiles[] = $dataFile;
                }
            }
        }
        
        return true;
    }
    
    /**
     * 生成任务ID
     */
    private function generateTaskId() {
        return 'task_' . date('YmdHis') . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    /**
     * 创建进程状态文件
     */
    private function createProcessFile($task_id) {
        $file = $this->getProcessFile($task_id);
        $data = [
            'should_stop' => false,
            'created_at' => time()
        ];
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    
    /**
     * 获取进程文件路径
     */
    private function getProcessFile($task_id) {
        return $this->processes_dir . '/' . $task_id . '.json';
    }
    
    /**
     * 获取日志文件路径
     */
    private function getLogFile($task_id) {
        return $this->logs_dir . '/' . $task_id . '.log';
    }
    
    /**
     * 记录日志
     */
    public function log($task_id, $message) {
        $log_file = $this->getLogFile($task_id);
        $time = date('Y-m-d H:i:s');
        $log_message = "[{$time}] {$message}\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }
    
    /**
     * 获取任务日志
     */
    public function getTaskLog($task_id, $lines = 100) {
        $log_file = $this->getLogFile($task_id);
        if (!file_exists($log_file)) {
            return '';
        }
        
        $content = file_get_contents($log_file);
        $lines_array = explode("\n", $content);
        
        // 返回最后N行
        if (count($lines_array) > $lines) {
            $lines_array = array_slice($lines_array, -$lines);
        }
        
        return implode("\n", $lines_array);
    }
    
    /**
     * 清理旧任务（保留最近30天）
     */
    public function cleanOldTasks($days = 30) {
        $tasks = $this->getAllTasks();
        $cutoff_time = strtotime("-{$days} days");
        $deleted = 0;
        
        foreach ($tasks as $task) {
            $created_time = strtotime($task['created_at']);
            
            // 只删除已完成/失败/停止的旧任务
            if ($created_time < $cutoff_time && 
                in_array($task['status'], [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_STOPPED])) {
                $this->deleteTask($task['id']);
                $deleted++;
            }
        }
        
        return $deleted;
    }
}
