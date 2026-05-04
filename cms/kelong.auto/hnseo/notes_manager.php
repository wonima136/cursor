<?php
/**
 * 操作记录管理接口
 * 用于读取和保存域名操作记录
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

// 记录文件路径
$notes_file = __DIR__ . '/notes.txt';

// 获取请求动作
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    case 'read':
        // 读取记录文件
        if (file_exists($notes_file)) {
            $content = file_get_contents($notes_file);
            echo json_encode([
                'success' => true,
                'content' => $content,
                'last_modified' => date('Y-m-d H:i:s', filemtime($notes_file))
            ]);
        } else {
            // 文件不存在，创建默认内容
            $default_content = "# 域名操作记录\n# 格式：[日期] 域名 - 操作内容\n# =====================================\n\n";
            file_put_contents($notes_file, $default_content);
            chmod($notes_file, 0775);
            echo json_encode([
                'success' => true,
                'content' => $default_content,
                'last_modified' => date('Y-m-d H:i:s')
            ]);
        }
        break;
        
    case 'save':
        // 保存记录文件
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        
        if (empty($content)) {
            echo json_encode(['success' => false, 'message' => '内容不能为空']);
            break;
        }
        
        // 备份旧文件
        if (file_exists($notes_file)) {
            $backup_file = __DIR__ . '/notes.txt.bak';
            copy($notes_file, $backup_file);
        }
        
        // 写入新内容
        $result = file_put_contents($notes_file, $content, LOCK_EX);
        
        if ($result !== false) {
            chmod($notes_file, 0775);
            echo json_encode([
                'success' => true,
                'message' => '保存成功',
                'last_modified' => date('Y-m-d H:i:s')
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败，请检查文件权限']);
        }
        break;
        
    case 'append':
        // 追加记录（快捷添加）
        $domain = isset($_POST['domain']) ? $_POST['domain'] : '';
        $note = isset($_POST['note']) ? $_POST['note'] : '';
        
        if (empty($note)) {
            echo json_encode(['success' => false, 'message' => '记录内容不能为空']);
            break;
        }
        
        // 格式化记录
        $date = date('Y-m-d H:i');
        $new_line = "\n[{$date}] " . ($domain ? "{$domain} - " : "") . $note . "\n";
        
        // 追加到文件
        $result = file_put_contents($notes_file, $new_line, FILE_APPEND | LOCK_EX);
        
        if ($result !== false) {
            echo json_encode([
                'success' => true,
                'message' => '记录已添加',
                'added_line' => trim($new_line)
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '添加失败']);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '未知操作']);
}
?>

