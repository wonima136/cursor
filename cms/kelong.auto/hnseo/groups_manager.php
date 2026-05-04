<?php
/**
 * 分组管理API接口
 * 功能：分组的增删改查、域名管理
 * 同时维护 JSON 文件和 SQLite 数据库
 */
ini_set('session.save_handler', 'files');
ini_set('session.save_path', '/tmp');
session_name('hnseo_tongji');
session_start();

// 验证登录状态
if (!isset($_SESSION['tongji_logged_in']) || $_SESSION['tongji_logged_in'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/spider_db.php';

$groups_file = __DIR__ . '/groups.json';

// 读取分组数据
function loadGroups() {
    global $groups_file;
    if (file_exists($groups_file)) {
        $content = file_get_contents($groups_file);
        $data = json_decode($content, true);
        if ($data === null) {
            return ['groups' => [], 'next_id' => 1];
        }
        return $data;
    }
    return ['groups' => [], 'next_id' => 1];
}

// 保存分组数据（同时更新数据库）
function saveGroups($data) {
    global $groups_file;
    
    // 保存到JSON文件
    $result = file_put_contents($groups_file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    
    // 同步到数据库
    syncGroupsToDatabase($data);
    
    return $result !== false;
}

// 同步分组数据到数据库
function syncGroupsToDatabase($data) {
    try {
        $db = getSpiderDB();
        $pdo = $db->getDB();
        
        // 清空现有数据
        $pdo->exec('DELETE FROM group_domains');
        $pdo->exec('DELETE FROM domain_groups');
        
        // 插入新数据
        $group_stmt = $pdo->prepare('INSERT INTO domain_groups (id, name, created_at) VALUES (?, ?, ?)');
        $domain_stmt = $pdo->prepare('INSERT INTO group_domains (group_id, domain) VALUES (?, ?)');
        
        foreach ($data['groups'] as $group) {
            $group_stmt->execute(array(
                $group['id'],
                $group['name'],
                isset($group['created_at']) ? $group['created_at'] : date('Y-m-d H:i:s')
            ));
            
            foreach ($group['domains'] as $domain) {
                $domain_stmt->execute([$group['id'], $domain]);
            }
        }
        
        return true;
    } catch (Exception $e) {
        // error_log('Sync groups to database error: ' . $e->getMessage());
        return false;
    }
}

// 检查域名是否已存在于某个分组
function findDomainInGroups($domain, $excludeGroupId = null) {
    $data = loadGroups();
    foreach ($data['groups'] as $group) {
        if ($excludeGroupId !== null && $group['id'] == $excludeGroupId) {
            continue;
        }
        if (in_array($domain, $group['domains'])) {
            return $group;
        }
    }
    return null;
}

// 获取操作类型
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    // 获取所有分组列表
    case 'list':
        $data = loadGroups();
        echo json_encode([
            'success' => true,
            'groups' => $data['groups'],
            'total' => count($data['groups'])
        ]);
        break;

    // 获取单个分组详情
    case 'get':
        $id = intval(isset($_GET['id']) ? $_GET['id'] : 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的分组ID']);
            break;
        }
        
        $data = loadGroups();
        $found = null;
        foreach ($data['groups'] as $group) {
            if ($group['id'] == $id) {
                $found = $group;
                break;
            }
        }
        
        if ($found) {
            echo json_encode(['success' => true, 'group' => $found]);
        } else {
            echo json_encode(['success' => false, 'message' => '分组不存在']);
        }
        break;

    // 创建新分组
    case 'create':
        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $remark = trim(isset($_POST['remark']) ? $_POST['remark'] : '');
        $domainsInput = trim(isset($_POST['domains']) ? $_POST['domains'] : '');
        
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => '分组名称不能为空']);
            break;
        }
        
        // 解析域名列表（支持换行、逗号、空格分隔）
        $domains = [];
        if (!empty($domainsInput)) {
            $domainsInput = str_replace(["\r\n", "\r", ",", "，", " "], "\n", $domainsInput);
            $domainLines = explode("\n", $domainsInput);
            foreach ($domainLines as $d) {
                $d = trim($d);
                if (!empty($d)) {
                    $domains[] = $d;
                }
            }
            $domains = array_unique($domains);
        }
        
        // 检查域名是否已存在于其他分组
        $conflicts = [];
        $validDomains = [];
        foreach ($domains as $domain) {
            $existingGroup = findDomainInGroups($domain);
            if ($existingGroup) {
                $conflicts[] = [
                    'domain' => $domain,
                    'group_id' => $existingGroup['id'],
                    'group_name' => $existingGroup['name']
                ];
            } else {
                $validDomains[] = $domain;
            }
        }
        
        // 如果有冲突，返回冲突信息让用户选择
        if (!empty($conflicts)) {
            echo json_encode([
                'success' => false,
                'has_conflicts' => true,
                'conflicts' => $conflicts,
                'valid_domains' => $validDomains,
                'message' => '部分域名已存在于其他分组'
            ]);
            break;
        }
        
        // 创建分组
        $data = loadGroups();
        $newGroup = [
            'id' => $data['next_id'],
            'name' => $name,
            'remark' => $remark,
            'domains' => array_values($validDomains),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $data['groups'][] = $newGroup;
        $data['next_id']++;
        
        if (saveGroups($data)) {
            echo json_encode([
                'success' => true,
                'message' => '分组创建成功',
                'group' => $newGroup
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败，请检查文件权限']);
        }
        break;

    // 创建分组（强制模式：移动冲突域名或跳过）
    case 'create_force':
        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $remark = trim(isset($_POST['remark']) ? $_POST['remark'] : '');
        $domainsInput = trim(isset($_POST['domains']) ? $_POST['domains'] : '');
        $handleMode = isset($_POST['handle_mode']) ? $_POST['handle_mode'] : 'skip'; // 'move' 或 'skip'
        $conflictDomains = json_decode(isset($_POST['conflict_domains']) ? $_POST['conflict_domains'] : '[]', true);
        
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => '分组名称不能为空']);
            break;
        }
        
        // 解析域名列表
        $domains = [];
        if (!empty($domainsInput)) {
            $domainsInput = str_replace(["\r\n", "\r", ",", "，", " "], "\n", $domainsInput);
            $domainLines = explode("\n", $domainsInput);
            foreach ($domainLines as $d) {
                $d = trim($d);
                if (!empty($d)) {
                    $domains[] = $d;
                }
            }
            $domains = array_unique($domains);
        }
        
        $data = loadGroups();
        $finalDomains = [];
        
        foreach ($domains as $domain) {
            $isConflict = false;
            foreach ($conflictDomains as $c) {
                if ($c['domain'] === $domain) {
                    $isConflict = true;
                    break;
                }
            }
            
            if ($isConflict) {
                if ($handleMode === 'move') {
                    // 从原分组中移除
                    foreach ($data['groups'] as &$group) {
                        $key = array_search($domain, $group['domains']);
                        if ($key !== false) {
                            unset($group['domains'][$key]);
                            $group['domains'] = array_values($group['domains']);
                            break;
                        }
                    }
                    unset($group);
                    $finalDomains[] = $domain;
                }
                // skip模式下不添加冲突域名
            } else {
                $finalDomains[] = $domain;
            }
        }
        
        // 创建分组
        $newGroup = [
            'id' => $data['next_id'],
            'name' => $name,
            'remark' => $remark,
            'domains' => array_values($finalDomains),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $data['groups'][] = $newGroup;
        $data['next_id']++;
        
        if (saveGroups($data)) {
            echo json_encode([
                'success' => true,
                'message' => '分组创建成功',
                'group' => $newGroup,
                'moved_count' => ($handleMode === 'move') ? count($conflictDomains) : 0,
                'skipped_count' => ($handleMode === 'skip') ? count($conflictDomains) : 0
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败，请检查文件权限']);
        }
        break;

    // 更新分组
    case 'update':
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $remark = trim(isset($_POST['remark']) ? $_POST['remark'] : '');
        $domainsInput = trim(isset($_POST['domains']) ? $_POST['domains'] : '');
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的分组ID']);
            break;
        }
        
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => '分组名称不能为空']);
            break;
        }
        
        // 解析域名列表
        $domains = [];
        if (!empty($domainsInput)) {
            $domainsInput = str_replace(["\r\n", "\r", ",", "，", " "], "\n", $domainsInput);
            $domainLines = explode("\n", $domainsInput);
            foreach ($domainLines as $d) {
                $d = trim($d);
                if (!empty($d)) {
                    $domains[] = $d;
                }
            }
            $domains = array_unique($domains);
        }
        
        // 检查域名冲突（排除当前分组）
        $conflicts = [];
        $validDomains = [];
        foreach ($domains as $domain) {
            $existingGroup = findDomainInGroups($domain, $id);
            if ($existingGroup) {
                $conflicts[] = [
                    'domain' => $domain,
                    'group_id' => $existingGroup['id'],
                    'group_name' => $existingGroup['name']
                ];
            } else {
                $validDomains[] = $domain;
            }
        }
        
        if (!empty($conflicts)) {
            echo json_encode([
                'success' => false,
                'has_conflicts' => true,
                'conflicts' => $conflicts,
                'valid_domains' => $validDomains,
                'message' => '部分域名已存在于其他分组'
            ]);
            break;
        }
        
        // 更新分组
        $data = loadGroups();
        $updated = false;
        foreach ($data['groups'] as &$group) {
            if ($group['id'] == $id) {
                $group['name'] = $name;
                $group['remark'] = $remark;
                $group['domains'] = array_values($validDomains);
                $group['updated_at'] = date('Y-m-d H:i:s');
                $updated = true;
                break;
            }
        }
        unset($group);
        
        if (!$updated) {
            echo json_encode(['success' => false, 'message' => '分组不存在']);
            break;
        }
        
        if (saveGroups($data)) {
            echo json_encode(['success' => true, 'message' => '分组更新成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败，请检查文件权限']);
        }
        break;

    // 单独更新备注
    case 'update_remark':
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $remark = isset($_POST['remark']) ? $_POST['remark'] : '';
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的分组ID']);
            break;
        }
        
        $data = loadGroups();
        $updated = false;
        foreach ($data['groups'] as &$group) {
            if ($group['id'] == $id) {
                $group['remark'] = $remark;
                $group['updated_at'] = date('Y-m-d H:i:s');
                $updated = true;
                break;
            }
        }
        unset($group);
        
        if (!$updated) {
            echo json_encode(['success' => false, 'message' => '分组不存在']);
            break;
        }
        
        if (saveGroups($data)) {
            echo json_encode(['success' => true, 'message' => '备注更新成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败，请检查文件权限']);
        }
        break;

    // 删除分组
    case 'delete':
        $id = intval(isset($_POST['id']) ? $_POST['id'] : (isset($_GET['id']) ? $_GET['id'] : 0));
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的分组ID']);
            break;
        }
        
        $data = loadGroups();
        $newGroups = [];
        $deleted = false;
        
        foreach ($data['groups'] as $group) {
            if ($group['id'] == $id) {
                $deleted = true;
            } else {
                $newGroups[] = $group;
            }
        }
        
        if (!$deleted) {
            echo json_encode(['success' => false, 'message' => '分组不存在']);
            break;
        }
        
        $data['groups'] = $newGroups;
        
        if (saveGroups($data)) {
            echo json_encode(['success' => true, 'message' => '分组删除成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '删除失败，请检查文件权限']);
        }
        break;

    // 检查域名冲突
    case 'check_domains':
        $domainsInput = trim(isset($_POST['domains']) ? $_POST['domains'] : '');
        $excludeGroupId = isset($_POST['exclude_group_id']) ? intval($_POST['exclude_group_id']) : null;
        
        // 解析域名列表
        $domains = [];
        if (!empty($domainsInput)) {
            $domainsInput = str_replace(["\r\n", "\r", ",", "，", " "], "\n", $domainsInput);
            $domainLines = explode("\n", $domainsInput);
            foreach ($domainLines as $d) {
                $d = trim($d);
                if (!empty($d)) {
                    $domains[] = $d;
                }
            }
            $domains = array_unique($domains);
        }
        
        $conflicts = [];
        foreach ($domains as $domain) {
            $existingGroup = findDomainInGroups($domain, $excludeGroupId);
            if ($existingGroup) {
                $conflicts[] = [
                    'domain' => $domain,
                    'group_id' => $existingGroup['id'],
                    'group_name' => $existingGroup['name']
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'has_conflicts' => !empty($conflicts),
            'conflicts' => $conflicts,
            'total_domains' => count($domains),
            'conflict_count' => count($conflicts)
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => '未知操作']);
}

