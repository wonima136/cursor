<?php
namespace Redirect301\Modules;

use Redirect301\Utils\DomainHelper;
use Redirect301\Utils\PlaceholderHelper;

/**
 * 寄生重定向模块
 * 优先级：3
 */
class ParasiteRedirect extends RedirectModule {
    
    public function getName() {
        return '寄生重定向';
    }
    
    public function getPriority() {
        return 3;
    }
    
    public function check() {
        // 优先从 Redis 加载配置
        require_once __DIR__ . '/../admin/redis_config.php';
        $taskIds = getAllParasiteTaskIdsFromRedis();
        
        $this->logger->debug("ParasiteRedirect: 开始检查，Redis任务数: " . count($taskIds));
        
        // 如果 Redis 中没有数据，从 JSON 加载
        if (empty($taskIds)) {
            $config = $this->config->load('parasites');
            if (empty($config['enabled']) || empty($config['tasks'])) {
                $this->logger->debug("ParasiteRedirect: 没有启用或没有任务");
                return null;
            }
            $tasks = $config['tasks'];
        } else {
            // 从 Redis 加载所有任务
            $tasks = [];
            foreach ($taskIds as $taskId) {
                $task = getParasiteTaskFromRedis($taskId);
                if ($task) {
                    $tasks[] = $task;
                }
            }
            $this->logger->debug("ParasiteRedirect: 从Redis加载了 " . count($tasks) . " 个任务");
        }
        
        // 遍历所有启用的任务
        foreach ($tasks as $task) {
            $this->logger->debug("ParasiteRedirect: 检查任务 {$task['id']}, enabled=" . ($task['enabled'] ? '1' : '0'));
            
            if (empty($task['enabled'])) {
                continue;
            }
            
            // 验证蜘蛛筛选
            if (!$this->validateSpider($task['spider_filter'] ?? [])) {
                $this->logger->debug("ParasiteRedirect: 任务 {$task['id']} 蜘蛛验证未通过");
                continue; // 跳过此任务
            }
            
            $this->logger->debug("ParasiteRedirect: 任务 {$task['id']} 开始匹配, manage_type=" . ($task['manage_type'] ?? 'unknown'));
            
            $result = null;
            $manageType = $task['manage_type'] ?? 'directory';
            
            if ($manageType === 'directory') {
                // 按目录匹配
                $result = $this->checkByDirectory($task);
            } else {
                // 按域名匹配
                $result = $this->checkByDomain($task);
            }
            
            if ($result) {
                // 更新统计
                $this->incrementStats($task['id']);
                
                // 更新目录级别统计（如果有）
                if (is_array($result) && !empty($result['dir_id'])) {
                    $this->incrementDirStats($task['id'], $result['dir_id']);
                }
                
                // 执行重定向
                // result 可能是字符串(URL)或数组['url' => ..., 'redirect_type' => ...]
                if (is_array($result)) {
                    $targetUrl = $result['url'];
                    $redirectType = $result['redirect_type'] ?? 301;
                } else {
                    $targetUrl = $result;
                    $redirectType = $task['settings']['redirect_type'] ?? 301;
                }
                $this->redirect($targetUrl, $task['name'], intval($redirectType));
            }
        }
        
        return null;
    }
    
    /**
     * 按目录匹配
     */
    private function checkByDirectory($task) {
        // 方式1: 使用 directories 数组（多个目录规则）
        $directories = $task['directories'] ?? [];
        
        if (!empty($directories)) {
            foreach ($directories as $dir) {
                $path = $dir['path'] ?? '';
                $targetUrl = $dir['target_url'] ?? '';
                
                if (empty($path) || empty($targetUrl)) {
                    continue;
                }
                
                // 检查当前URI是否在该目录下
                if ($this->matchDirectory($path)) {
                    return $targetUrl;
                }
            }
        }
        
        // 方式2: 使用 source_path + source_domains + target_domains（单一源目录）
        // ⭐ 支持多源目录（逗号分隔）
        
        // ⭐⭐⭐ 修复严重逻辑错误：先检查域名，再检查目录
        $redirectMode = $task['redirect_mode'] ?? 'focus';
        $sourceDomains = $task['source_domains'] ?? [];
        
        // 第一步：检查当前域名是否在源域名列表中（所有模式都需要检查）
        if (!empty($sourceDomains)) {
            $domainMatched = false;
            foreach ($sourceDomains as $domain) {
                $domainName = is_array($domain) ? ($domain['domain'] ?? '') : $domain;
                if (empty($domainName)) {
                    continue;
                }
                if (DomainHelper::matchDomain($domainName, $this->currentHost)) {
                    $domainMatched = true;
                    $this->logger->debug("ParasiteRedirect: 域名匹配成功: {$domainName}");
                    break;
                }
            }
            if (!$domainMatched) {
                $this->logger->debug("ParasiteRedirect: 当前域名 {$this->currentHost} 不在源域名列表中，跳过此任务");
                return null;
            }
        }
        
        // 第二步：检查源目录
        $sourcePathInput = $task['source_path'] ?? '';
        $sourcePaths = $this->parseDirectories($sourcePathInput);
        
        $this->logger->debug("ParasiteRedirect: source_path={$sourcePathInput}, 解析后=" . json_encode($sourcePaths) . ", 当前URI={$this->currentUri}");
        
        if (empty($sourcePaths)) {
            $this->logger->debug("ParasiteRedirect: 源目录为空");
            return null;
        }
        
        // 检查当前URI是否匹配任意一个源目录
        $matchedSourcePath = null;
        foreach ($sourcePaths as $path) {
            if ($this->matchDirectory($path)) {
                $matchedSourcePath = $path;
                $this->logger->debug("ParasiteRedirect: 匹配到源目录: {$path}");
                break;
            }
        }
        
        if (!$matchedSourcePath) {
            $this->logger->debug("ParasiteRedirect: 没有匹配的源目录");
            return null; // 没有匹配的源目录
        }
        
        // 检查概率
        $probability = $task['settings']['probability'] ?? 100;
        if ($probability < 100 && mt_rand(1, 100) > $probability) {
            return null; // 不触发跳转
        }
        
        // 根据跳转模式选择目标域名
        $targetDomain = $this->selectTargetDomainForDirectory($task);
        if (empty($targetDomain)) {
            return null;
        }
        
        // 构建目标URL
        $protocol = $this->getCurrentProtocol();
        
        // 如果目标域名包含协议，提取出来
        if (preg_match('#^(https?)://(.+?)/?$#i', $targetDomain, $matches)) {
            $protocol = $matches[1];
            $targetDomain = rtrim($matches[2], '/');
        }
        
        // ⭐ 处理路径（支持目标目录功能）
        $finalPath = '';
        
        if (!empty($task['target_paths_enabled'])) {
            // 启用目标目录功能
            $targetPathsInput = $task['target_paths'] ?? '';
            $targetPaths = $this->parseDirectories($targetPathsInput);
            
            if (!empty($targetPaths)) {
                // 随机选择一个目标路径
                $targetPath = $targetPaths[array_rand($targetPaths)];
                
                // 替换占位符
                $targetPath = PlaceholderHelper::replace($targetPath);
                
                // 判断目标路径类型
                if (substr($targetPath, -1) === '/') {
                    // 目录型：拼接剩余路径
                    $sourcePath = '/' . trim($matchedSourcePath, '/') . '/';
                    $remainingPath = substr($this->currentUri, strlen($sourcePath));
                    if ($remainingPath === false) {
                        $remainingPath = '';
                    }
                    $finalPath = rtrim($targetPath, '/') . '/' . ltrim($remainingPath, '/');
                } else {
                    // 文件型：直接使用目标路径
                    $finalPath = $targetPath;
                }
            } else {
                // ⭐ 修复漏洞：目标路径为空时，跳过跳转（避免无限循环）
                return null;
            }
        } else {
            // 未启用目标目录功能，使用原有逻辑
            $pathMode = $task['settings']['path_mode'] ?? 'strip_prefix';
            
            if ($pathMode === 'strip_prefix') {
                // 去除源路径前缀
                $sourcePath = '/' . trim($matchedSourcePath, '/') . '/';
                $finalPath = substr($this->currentUri, strlen($sourcePath) - 1);
                if ($finalPath === false || $finalPath === '') {
                    $finalPath = '/';
                }
            } else {
                // 保持完整路径
                $finalPath = $this->currentUri;
            }
            
            // 应用URI替换规则
            $finalPath = $this->applyUriReplacements($task, $finalPath);
        }
        
        $targetUrl = $protocol . '://' . $targetDomain . $finalPath;
        
        // 最终占位符替换（如果URI替换规则中有占位符）
        $targetUrl = PlaceholderHelper::replace($targetUrl);
        
        return $targetUrl;
    }
    
    /**
     * 按域名匹配
     */
    private function checkByDomain($task) {
        // 检查源域名是否匹配
        $sourceDomain = $task['source_domain'] ?? '';
        
        if (empty($sourceDomain)) {
            return null;
        }
        
        // 如果当前域名不匹配源域名，直接返回
        if (!DomainHelper::matchDomain($sourceDomain, $this->currentHost)) {
            return null;
        }
        
        // 域名匹配成功，检查是否有目录规则
        $directories = $task['directories'] ?? [];
        
        if (!empty($directories)) {
            // 遍历目录规则，查找匹配的目录
            foreach ($directories as $dir) {
                if (empty($dir['enabled']) || empty($dir['path'])) {
                    continue;
                }
                
                // 检查当前URI是否匹配该目录
            if ($this->matchDirectory($dir['path'])) {
                // 检查概率
                $probability = $dir['probability'] ?? 100;
                if ($probability < 100 && mt_rand(1, 100) > $probability) {
                    continue; // 不触发跳转，继续检查下一个规则
                }
                    
                    // 匹配成功，选择目标域名
                    $targetDomain = $this->selectTargetDomain($dir);
                    if (empty($targetDomain)) {
                        continue;
                    }
                    
                    // 处理路径
                    $pathMode = $dir['path_mode'] ?? 'strip_prefix';
                    $targetPath = $this->currentUri;
                    
                    if ($pathMode === 'strip_prefix') {
                        // 去除源目录前缀
                        $sourcePath = '/' . trim($dir['path'], '/') . '/';
                        $targetPath = substr($this->currentUri, strlen($sourcePath) - 1);
                        if ($targetPath === false || $targetPath === '') {
                            $targetPath = '/';
                        }
                    }
                    
                    // 确保目标域名有协议
                    if (!preg_match('#^https?://#', $targetDomain)) {
                        $targetDomain = 'http://' . $targetDomain;
                    }
                    
                    // 应用URI替换规则
                    $targetPath = $this->applyUriReplacements($task, $targetPath);
                    
                    // 构建目标URL
                    $targetDomain = rtrim($targetDomain, '/');
                    
                    // 如果目标域名已经是完整URL（包含路径），不再添加路径
                    if (preg_match('#^https?://[^/]+/.+#', $targetDomain)) {
                        // 目标域名已包含路径，直接使用
                        $targetUrl = $targetDomain;
                    } else if ($targetPath && $targetPath !== '/') {
                        // 目标域名是纯域名，添加路径
                        $targetUrl = $targetDomain . $targetPath;
                    } else {
                        // 没有路径，添加根路径
                        $targetUrl = $targetDomain . '/';
                    }
                    
                    // 替换占位符
                    $targetUrl = PlaceholderHelper::replace($targetUrl);
                    
                    $redirectType = $dir['redirect_type'] ?? 301;
                    
                    return [
                        'url' => $targetUrl,
                        'redirect_type' => $redirectType,
                        'dir_id' => $dir['id'] ?? null  // 记录触发跳转的目录规则ID
                    ];
                }
            }
        }
        
        // 如果没有匹配的目录规则，使用默认目标URL（如果有）
        $targetUrl = $task['target_url'] ?? '';
        if (!empty($targetUrl)) {
            return $targetUrl;
        }
        
        return null;
    }
    
    /**
     * 选择目标域名（支持单个或多个）
     * 优先使用 target_domains 数组，如果不存在则使用 target_domain 字符串（向后兼容）
     */
    private function selectTargetDomain($dir) {
        // 优先使用 target_domains（多个目标域名）
        $targetDomains = $dir['target_domains'] ?? [];
        
        if (!empty($targetDomains) && is_array($targetDomains)) {
            // 过滤出有效的目标域名
            $validDomains = [];
            
            foreach ($targetDomains as $domain) {
                // 支持两种格式：
                // 1. 简单字符串：'target.com'
                // 2. 对象格式：{'domain': 'target.com', 'enabled': true}
                if (is_string($domain)) {
                    $domainStr = trim($domain);
                    if (!empty($domainStr)) {
                        $validDomains[] = $domainStr;
                    }
                } elseif (is_array($domain)) {
                    // 对象格式，检查是否启用
                    $enabled = $domain['enabled'] ?? true; // 默认启用
                    if ($enabled) {
                        $domainStr = trim($domain['domain'] ?? '');
                        if (!empty($domainStr)) {
                            $validDomains[] = $domainStr;
                        }
                    }
                }
            }
            
            // 如果有有效的域名，随机选择一个
            if (!empty($validDomains)) {
                return $validDomains[array_rand($validDomains)];
            }
        }
        
        // 向后兼容：使用单个 target_domain
        return trim($dir['target_domain'] ?? '');
    }
    
    /**
     * 匹配目录
     */
    private function matchDirectory($path) {
        // 标准化路径
        $path = '/' . trim($path, '/') . '/';
        $uri = strtok($this->currentUri, '?'); // 移除查询参数
        
        // 检查URI是否以该路径开头
        return strpos($uri, $path) === 0;
    }
    
    /**
     * 解析目录列表（支持逗号分隔）
     * 
     * @param string $input 逗号分隔的目录字符串
     * @return array 目录数组
     */
    private function parseDirectories($input) {
        if (empty($input)) {
            return [];
        }
        
        // ⭐ 支持逗号和换行分隔
        $input = str_replace(["\r\n", "\r"], "\n", $input); // 统一换行符
        $dirs = preg_split('/[,\n]+/', $input); // 按逗号或换行分隔
        $dirs = array_map('trim', $dirs);
        
        // 过滤空值
        return array_filter($dirs, function($dir) {
            return !empty($dir);
        });
    }
    
    /**
     * 根据跳转模式选择目标域名（按目录管理模式）
     * 
     * @param array $task 任务配置
     * @return string|null 目标域名
     */
    private function selectTargetDomainForDirectory($task) {
        $redirectMode = $task['redirect_mode'] ?? 'focus';
        $sourceDomains = $task['source_domains'] ?? [];
        $targetDomains = $task['target_domains'] ?? [];
        
        switch ($redirectMode) {
            case 'focus':
                // 集权模式：从目标域名列表中随机选择
                if (empty($targetDomains)) {
                    return null;
                }
                return $targetDomains[array_rand($targetDomains)];
                
            case 'interlink':
                // 互连模式：从源域名列表中随机选择（排除当前域名）
                $this->logger->debug("ParasiteRedirect: 互连模式 - 当前域名={$this->currentHost}, 源域名数=" . count($sourceDomains));
                
                $availableDomains = [];
                foreach ($sourceDomains as $domain) {
                    $domainName = is_array($domain) ? ($domain['domain'] ?? '') : $domain;
                    if (empty($domainName)) {
                        continue;
                    }
                    $this->logger->debug("ParasiteRedirect: 检查域名 {$domainName}");
                    // 排除当前域名
                    if (!DomainHelper::matchDomain($domainName, $this->currentHost)) {
                        $availableDomains[] = $domainName;
                        $this->logger->debug("ParasiteRedirect: 添加到可用域名: {$domainName}");
                    } else {
                        $this->logger->debug("ParasiteRedirect: 排除当前域名: {$domainName}");
                    }
                }
                
                $this->logger->debug("ParasiteRedirect: 可用域名数=" . count($availableDomains) . ", 列表=" . json_encode($availableDomains));
                
                if (empty($availableDomains)) {
                    $this->logger->debug("ParasiteRedirect: 互连模式没有可用域名");
                    return null;
                }
                
                $selected = $availableDomains[array_rand($availableDomains)];
                $this->logger->debug("ParasiteRedirect: 互连模式选择域名: {$selected}");
                return $selected;
                
            case 'one_to_one':
                // 一对一模式：使用当前域名
                return $this->currentHost;
                
            default:
                return null;
        }
    }
    
    /**
     * 应用URI替换规则（支持多次替换）
     */
    private function applyUriReplacements($task, $uri) {
        // 寄生重定向使用 'replacements' 字段（在 settings 中）
        $replacements = $task['settings']['replacements'] ?? $task['replacements'] ?? [];
        
        if (empty($replacements)) {
            return $uri;
        }
        
        $newUri = $uri;
        $hasReplacement = false;
        
        // 遍历所有替换规则，依次执行
        foreach ($replacements as $rule) {
            $find = $rule['find'] ?? '';
            $replace = $rule['replace'] ?? '';
            
            if (empty($find)) continue;
            
            // 检查是否匹配
            if (strpos($newUri, $find) !== false) {
                $newUri = str_replace($find, $replace, $newUri);
                $hasReplacement = true;
            }
        }
        
        // 如果有任何替换发生，应用占位符替换
        if ($hasReplacement) {
            $newUri = PlaceholderHelper::replace($newUri);
        }
        
        return $newUri;
    }
    
    /**
     * 更新统计
     */
    private function incrementStats($taskId) {
        // ★ 只使用 Redis 更新统计，不再更新 JSON 文件
        // 原因：每次跳转都写入 JSON 会导致并发冲突，覆盖用户刚添加的任务
        // JSON 文件只在后台管理操作时写入，统计数据完全依赖 Redis
        require_once __DIR__ . '/../admin/redis_config.php';
        incrementParasiteTaskStats($taskId, 'total_redirects', 1);
        
        // ★ 已移除 JSON 写入逻辑，避免频繁刷新 parasites.json 导致数据丢失
    }
    
    /**
     * 更新单个目录规则的统计
     */
    private function incrementDirStats($taskId, $dirId) {
        if (empty($dirId)) {
            return;
        }
        
        require_once __DIR__ . '/../admin/redis_config.php';
        incrementParasiteDirStats($taskId, $dirId, 'total_redirects', 1);
    }
}

