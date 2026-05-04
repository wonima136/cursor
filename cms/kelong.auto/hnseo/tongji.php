<?php 
// 简单密码验证机制
ini_set('session.save_handler', 'files');
ini_set('session.save_path', '/tmp');
session_name('hnseo_tongji');
session_start();

// 设置密码（可以修改为您想要的密码）
$admin_password = 'abingou2025';

// 检查是否已经登录
if (!isset($_SESSION['tongji_logged_in']) || $_SESSION['tongji_logged_in'] !== true) {
    // 检查是否提交了密码
    if (isset($_POST['password'])) {
        if ($_POST['password'] === $admin_password) {
            $_SESSION['tongji_logged_in'] = true;
            // 密码正确，重新加载页面
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $login_error = '密码错误，请重试！';
        }
    }
    
    // 显示登录表单
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <title>蜘蛛统计 - 访问验证</title>
        <link href="static/js/skin/WdatePicker.css" rel="stylesheet" type="text/css" />
        <link href="static/css/admin.css" rel="stylesheet" type="text/css" />
        <style>
            body {
                background: #f5f5f5;
                font-family: "Microsoft YaHei", Arial, sans-serif;
                margin: 0;
                padding: 0;
            }
            .login-wrapper {
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                background: url('static/images/admin_bg.gif') repeat;
            }
            .login-container {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 3px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                width: 400px;
                overflow: hidden;
            }
            .login-header {
                background: #4a90e2;
                color: white;
                padding: 15px 20px;
                font-size: 16px;
                font-weight: bold;
                border-bottom: 1px solid #357abd;
            }
            .login-body {
                padding: 30px;
            }
            .login-title {
                color: #333;
                margin-bottom: 25px;
                font-size: 18px;
                text-align: center;
            }
            .form-group {
                margin-bottom: 20px;
            }
            .form-group label {
                display: block;
                margin-bottom: 8px;
                color: #666;
                font-size: 14px;
            }
            .form-group input[type="password"] {
                width: 100%;
                height: 40px;
                padding: 0 12px;
                border: 1px solid #ddd;
                border-radius: 3px;
                font-size: 14px;
                box-sizing: border-box;
                transition: border-color 0.3s;
            }
            .form-group input[type="password"]:focus {
                outline: none;
                border-color: #4a90e2;
                box-shadow: 0 0 5px rgba(74,144,226,0.3);
            }
            .login-btn {
                width: 100%;
                height: 40px;
                background: #4a90e2;
                color: white;
                border: none;
                border-radius: 3px;
                font-size: 14px;
                cursor: pointer;
                transition: background-color 0.3s;
            }
            .login-btn:hover {
                background: #357abd;
            }
            .error-message {
                background: #f8d7da;
                color: #721c24;
                padding: 10px;
                border: 1px solid #f5c6cb;
                border-radius: 3px;
                margin-bottom: 15px;
                font-size: 13px;
            }
            .login-footer {
                background: #f8f9fa;
                padding: 15px 20px;
                border-top: 1px solid #dee2e6;
                text-align: center;
                color: #6c757d;
                font-size: 12px;
            }
            .spider-icon {
                display: inline-block;
                margin-right: 8px;
                font-size: 18px;
            }
        </style>
    </head>
    <body>
        <div class="login-wrapper">
            <div class="login-container">
                <div class="login-header">
                    <span class="spider-icon">🕷️</span>蜘蛛统计系统 - 访问验证
                </div>
                
                <div class="login-body">
                    <div class="login-title">请输入访问密码</div>
                    
                    <?php if (isset($login_error)): ?>
                        <div class="error-message">
                            <strong>错误：</strong><?php echo $login_error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post">
                        <div class="form-group">
                            <label for="password">访问密码：</label>
                            <input type="password" id="password" name="password" placeholder="请输入系统访问密码" required autofocus>
                        </div>
                        <button type="submit" class="login-btn">进入统计系统</button>
                    </form>
                </div>
                
                <div class="login-footer">
                    蜘蛛统计系统 &copy; 2025 - 仅限授权用户访问
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 检查是否要退出登录
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include('zong.php');
?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>蜘蛛统计</title>
<link href="static/js/skin/WdatePicker.css" rel="stylesheet" type="text/css" />
<link href="static/css/admin.css" rel="stylesheet" type="text/css" />
<script type="text/javascript" charset="utf-8" src="static/js/jquery.js"></script>
<script type="text/javascript" src="static/js/highcharts.js"></script>
<script type="text/javascript" src="static/js/lingduseo.js"></script>
<script type="text/javascript" src="static/js/DatePicker/WdatePicker.js"></script>
</head>
<body class="body-main">
<ul id="admin_sub_title">
	<li class="sub"><a href="javascript:" onclick="showTab('records')">访问记录</a></li>
	<li class="sub"><a href="javascript:" onclick="showTab('domains')">域名明细</a></li>
	<li class="tips"><a href="javascript:void(0)" onclick="exportGlobalData(this)" style="color:orange">导出全局数据</a></li>
	<li class="tips"><a href="deltj.php" target="_blank" onclick="recount();" style="color:red">（清除全部蜘蛛）</a></li>
	<li class="tips"><a href="?logout=1" style="color:#ff6b6b" onclick="return confirm('确定要退出登录吗？')">退出登录</a></li>
</ul>
<div id="admin_right_b">
<!-- 分组选择器 -->
<div id="group_selector_bar" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 12px 20px; margin-bottom: 15px; border-radius: 5px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 8px rgba(102,126,234,0.3);">
	<div style="display: flex; align-items: center; gap: 20px;">
		<div style="display: flex; align-items: center;">
			<span style="color: #fff; font-weight: bold; margin-right: 15px; font-size: 14px;">📊 当前查看：</span>
			<div class="group-dropdown" style="position: relative; display: inline-block;">
				<button id="group_dropdown_btn" onclick="toggleGroupDropdown()" style="background: #fff; border: none; padding: 8px 35px 8px 15px; border-radius: 4px; font-size: 14px; cursor: pointer; min-width: 150px; text-align: left; box-shadow: 0 2px 5px rgba(0,0,0,0.1); position: relative;">
					<span id="current_group_name">全部域名</span>
					<span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 10px;">▼</span>
				</button>
				<div id="group_dropdown_menu" style="display: none; position: absolute; top: 100%; left: 0; background: #fff; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 200px; max-height: 300px; overflow-y: auto; z-index: 1000; margin-top: 5px;">
					<div onclick="selectGroup(null, '全部域名')" class="group-option" style="padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #eee; font-weight: bold; color: #667eea;">
						✓ 全部域名
					</div>
					<div id="group_list_container">
						<!-- 分组列表将通过JS加载 -->
					</div>
				</div>
			</div>
			<input type="hidden" id="current_group_id" value="">
		</div>
		<!-- 龙虎榜入口 -->
		<div style="display: flex; gap: 6px; padding-left: 20px; border-left: 1px solid rgba(255,255,255,0.3);">
			<button onclick="openLeaderboard('mobile')" style="background: rgba(255,255,255,0.95); color: #667eea; border: none; padding: 7px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; box-shadow: 0 2px 5px rgba(0,0,0,0.15); transition: all 0.3s;">
				📱 移动
			</button>
			<button onclick="openLeaderboard('pc')" style="background: rgba(255,255,255,0.95); color: #764ba2; border: none; padding: 7px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; box-shadow: 0 2px 5px rgba(0,0,0,0.15); transition: all 0.3s;">
				💻 PC
			</button>
			<span style="color: rgba(255,255,255,0.4); margin: 0 3px; line-height: 32px;">|</span>
			<button onclick="openLeaderboard('inner_mobile')" style="background: rgba(255,255,255,0.95); color: #ff5722; border: none; padding: 7px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; box-shadow: 0 2px 5px rgba(0,0,0,0.15); transition: all 0.3s;">
				📱 内页移动
			</button>
			<button onclick="openLeaderboard('inner_pc')" style="background: rgba(255,255,255,0.95); color: #ff9800; border: none; padding: 7px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; box-shadow: 0 2px 5px rgba(0,0,0,0.15); transition: all 0.3s;">
				💻 内页PC
			</button>
		</div>
	</div>
	<div style="display: flex; gap: 10px; flex-wrap: wrap;">
		<!-- 导出按钮组 -->
		<div style="display: flex; gap: 5px; margin-right: 10px; padding-right: 15px; border-right: 1px solid rgba(255,255,255,0.3);">
			<button onclick="showExportModal('all')" style="background: rgba(76,175,80,0.3); color: #fff; border: 1px solid rgba(76,175,80,0.5); padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s;" title="导出当前分组所有被抓取的URL">
				📥 导出全部
			</button>
			<button onclick="showExportModal('mobile')" style="background: rgba(33,150,243,0.3); color: #fff; border: 1px solid rgba(33,150,243,0.5); padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s;" title="导出百度移动蜘蛛抓取的URL">
				📱 导出移动
			</button>
			<button onclick="showExportModal('pc')" style="background: rgba(255,152,0,0.3); color: #fff; border: 1px solid rgba(255,152,0,0.5); padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s;" title="导出百度PC蜘蛛抓取的URL">
				💻 导出PC
			</button>
			<button onclick="showCustomExportModal()" style="background: rgba(156,39,176,0.3); color: #fff; border: 1px solid rgba(156,39,176,0.5); padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s;" title="指定域名导出数据">
				🎯 指定导出
			</button>
			<button onclick="showUrlQueryModal()" style="background: rgba(0,188,212,0.3); color: #fff; border: 1px solid rgba(0,188,212,0.5); padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s;" title="查询URL抓取情况">
				🔍 URL查询
			</button>
		</div>
		<!-- 权重分析入口 -->
		<a href="weight_analysis/index.php" target="_blank" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: #fff; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-size: 13px; transition: all 0.3s; text-decoration: none; display: inline-block; margin-right: 10px;" title="权重蜘蛛分析系统">
			🔬 权重分析
		</a>
		<!-- 重定向统计入口 -->
		<a href="redirect_stats.php" target="_blank" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: #fff; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-size: 13px; transition: all 0.3s; text-decoration: none; display: inline-block; margin-right: 10px;" title="接收重定向统计">
			🔄 重定向统计
		</a>
		<!-- 分组管理按钮 -->
		<button onclick="openAddGroupModal()" style="background: rgba(255,255,255,0.2); color: #fff; border: 1px solid rgba(255,255,255,0.3); padding: 8px 15px; border-radius: 4px; cursor: pointer; font-size: 13px; transition: all 0.3s;">
			➕ 添加分组
		</button>
		<button onclick="openManageGroupModal()" style="background: rgba(255,255,255,0.2); color: #fff; border: 1px solid rgba(255,255,255,0.3); padding: 8px 15px; border-radius: 4px; cursor: pointer; font-size: 13px; transition: all 0.3s;">
			⚙️ 管理分组
		</button>
	</div>
</div>
<style>
.group-option:hover { background: #f5f5f5; }
.group-option.active { background: #e8f4fd; color: #1890ff; }
#group_selector_bar button:hover { background: rgba(255,255,255,0.35) !important; }

/* 分组管理弹窗样式 */
.group-modal-overlay {
	display: none;
	position: fixed;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background: rgba(0,0,0,0.5);
	z-index: 9999;
	justify-content: center;
	align-items: center;
}
.group-modal {
	background: #fff;
	border-radius: 8px;
	box-shadow: 0 10px 40px rgba(0,0,0,0.3);
	width: 550px;
	max-width: 90%;
	max-height: 80vh;
	overflow: hidden;
	animation: modalSlideIn 0.3s ease;
}
@keyframes modalSlideIn {
	from { transform: translateY(-50px); opacity: 0; }
	to { transform: translateY(0); opacity: 1; }
}
.group-modal-header {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	color: #fff;
	padding: 15px 20px;
	display: flex;
	justify-content: space-between;
	align-items: center;
}
.group-modal-header h3 {
	margin: 0;
	font-size: 16px;
}
.group-modal-close {
	background: none;
	border: none;
	color: #fff;
	font-size: 20px;
	cursor: pointer;
	opacity: 0.8;
}
.group-modal-close:hover { opacity: 1; }
.group-modal-body {
	padding: 20px;
	max-height: 60vh;
	overflow-y: auto;
}
.group-form-group {
	margin-bottom: 15px;
}
.group-form-group label {
	display: block;
	margin-bottom: 5px;
	font-weight: bold;
	color: #333;
}
.group-form-group input[type="text"],
.group-form-group textarea {
	width: 100%;
	padding: 10px;
	border: 1px solid #ddd;
	border-radius: 4px;
	font-size: 14px;
	box-sizing: border-box;
}
.group-form-group textarea {
	height: 150px;
	resize: vertical;
}
.group-form-group input:focus,
.group-form-group textarea:focus {
	border-color: #667eea;
	outline: none;
	box-shadow: 0 0 5px rgba(102,126,234,0.3);
}
.group-modal-footer {
	padding: 15px 20px;
	background: #f8f9fa;
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	border-top: 1px solid #eee;
}
.btn-primary {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	color: #fff;
	border: none;
	padding: 10px 20px;
	border-radius: 4px;
	cursor: pointer;
	font-size: 14px;
}
.btn-primary:hover { opacity: 0.9; }
.btn-secondary {
	background: #6c757d;
	color: #fff;
	border: none;
	padding: 10px 20px;
	border-radius: 4px;
	cursor: pointer;
	font-size: 14px;
}
.btn-secondary:hover { background: #5a6268; }
.btn-danger {
	background: #dc3545;
	color: #fff;
	border: none;
	padding: 8px 15px;
	border-radius: 4px;
	cursor: pointer;
	font-size: 13px;
}
.btn-danger:hover { background: #c82333; }

/* 分组列表项 */
.group-list-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 15px;
	border: 1px solid #eee;
	border-radius: 4px;
	margin-bottom: 10px;
	background: #fafafa;
}
.group-list-item:hover {
	background: #f0f0f0;
}
.group-list-item .group-info {
	flex: 1;
}
.group-list-item .group-name {
	font-weight: bold;
	color: #333;
	font-size: 14px;
}
.group-list-item .group-domain-count {
	color: #666;
	font-size: 12px;
	margin-top: 3px;
}
.group-list-item .group-actions {
	display: flex;
	gap: 8px;
}
.group-list-item .group-actions button {
	padding: 5px 10px;
	font-size: 12px;
	border-radius: 3px;
	cursor: pointer;
	border: none;
}
.btn-edit {
	background: #17a2b8;
	color: #fff;
}
.btn-edit:hover { background: #138496; }

/* 冲突提示样式 */
.conflict-alert {
	background: #fff3cd;
	border: 1px solid #ffc107;
	border-radius: 4px;
	padding: 15px;
	margin-bottom: 15px;
}
.conflict-alert h4 {
	margin: 0 0 10px 0;
	color: #856404;
}
.conflict-list {
	max-height: 150px;
	overflow-y: auto;
}
.conflict-item {
	padding: 5px 0;
	color: #856404;
	font-size: 13px;
}
.conflict-actions {
	margin-top: 15px;
	display: flex;
	gap: 10px;
}
</style>

<!-- 添加分组弹窗 -->
<div id="add_group_modal" class="group-modal-overlay">
	<div class="group-modal">
		<div class="group-modal-header">
			<h3>➕ 添加新分组</h3>
			<button class="group-modal-close" onclick="closeAddGroupModal()">&times;</button>
		</div>
		<div class="group-modal-body">
			<div id="add_group_conflict_area" style="display:none;"></div>
			<div class="group-form-group">
				<label>分组名称 <span style="color:red">*</span></label>
				<input type="text" id="add_group_name" placeholder="例如：影视站群A">
			</div>
			<div class="group-form-group">
				<label>分组备注 <span style="color:#999;font-weight:normal">(可选)</span></label>
				<textarea id="add_group_remark" placeholder="记录分组的用途、特点等信息..." style="height: 60px;"></textarea>
			</div>
			<div class="group-form-group">
				<label>域名列表 <span style="color:#999;font-weight:normal">(每行一个域名)</span></label>
				<textarea id="add_group_domains" placeholder="movie1.com&#10;movie2.com&#10;film3.net"></textarea>
			</div>
		</div>
		<div class="group-modal-footer">
			<button class="btn-secondary" onclick="closeAddGroupModal()">取消</button>
			<button class="btn-primary" onclick="submitAddGroup()">确认添加</button>
		</div>
	</div>
</div>

<!-- 管理分组弹窗 -->
<div id="manage_group_modal" class="group-modal-overlay">
	<div class="group-modal" style="width: 650px;">
		<div class="group-modal-header">
			<h3>⚙️ 分组管理</h3>
			<button class="group-modal-close" onclick="closeManageGroupModal()">&times;</button>
		</div>
		<div class="group-modal-body">
			<div id="manage_group_list">
				<div style="text-align:center; color:#999; padding:30px;">加载中...</div>
			</div>
		</div>
		<div class="group-modal-footer">
			<button class="btn-secondary" onclick="closeManageGroupModal()">关闭</button>
		</div>
	</div>
</div>

<!-- 编辑分组弹窗 -->
<div id="edit_group_modal" class="group-modal-overlay">
	<div class="group-modal">
		<div class="group-modal-header">
			<h3>✏️ 编辑分组</h3>
			<button class="group-modal-close" onclick="closeEditGroupModal()">&times;</button>
		</div>
		<div class="group-modal-body">
			<input type="hidden" id="edit_group_id">
			<div id="edit_group_conflict_area" style="display:none;"></div>
			<div class="group-form-group">
				<label>分组名称 <span style="color:red">*</span></label>
				<input type="text" id="edit_group_name" placeholder="例如：影视站群A">
			</div>
			<div class="group-form-group">
				<label>分组备注 <span style="color:#999;font-weight:normal">(可选)</span></label>
				<textarea id="edit_group_remark" placeholder="记录分组的用途、特点等信息..." style="height: 60px;"></textarea>
			</div>
			<div class="group-form-group">
				<label>域名列表 <span style="color:#999;font-weight:normal">(每行一个域名)</span></label>
				<textarea id="edit_group_domains" placeholder="movie1.com&#10;movie2.com&#10;film3.net"></textarea>
			</div>
		</div>
		<div class="group-modal-footer">
			<button class="btn-secondary" onclick="closeEditGroupModal()">取消</button>
			<button class="btn-primary" onclick="submitEditGroup()">保存修改</button>
		</div>
	</div>
</div>

<!-- 查看/编辑备注弹窗 -->
<div id="view_remark_modal" class="group-modal-overlay">
	<div class="group-modal" style="width: 550px;">
		<div class="group-modal-header" style="background: linear-gradient(135deg, #607D8B 0%, #455A64 100%);">
			<h3>📝 <span id="view_remark_title">分组备注</span></h3>
			<button class="group-modal-close" onclick="closeViewRemarkModal()">&times;</button>
		</div>
		<div class="group-modal-body" style="padding: 20px;">
			<input type="hidden" id="view_remark_group_id">
			<!-- 查看模式 -->
			<div id="view_remark_display" style="background: #f9f9f9; padding: 15px; border-radius: 6px; min-height: 600px; white-space: pre-wrap; word-break: break-all; color: #333; line-height: 1.6;">
				暂无备注
			</div>
			<!-- 编辑模式 -->
			<div id="edit_remark_area" style="display: none;">
				<textarea id="edit_remark_input" placeholder="输入备注信息..." style="width: 100%; height: 600px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; resize: vertical;"></textarea>
			</div>
		</div>
		<div class="group-modal-footer">
			<!-- 查看模式按钮 -->
			<div id="view_remark_buttons">
				<button class="btn-secondary" onclick="closeViewRemarkModal()">关闭</button>
				<button class="btn-primary" onclick="switchToEditRemark()" style="background: #607D8B;">编辑备注</button>
			</div>
			<!-- 编辑模式按钮 -->
			<div id="edit_remark_buttons" style="display: none;">
				<button class="btn-secondary" onclick="cancelEditRemark()">取消</button>
				<button class="btn-primary" onclick="saveRemark()">保存备注</button>
			</div>
		</div>
	</div>
</div>

<!-- 导出选项弹窗 -->
<div id="export_modal" class="group-modal-overlay">
	<div class="group-modal" style="width: 500px;">
		<div class="group-modal-header" style="background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);">
			<h3>📥 导出设置</h3>
			<button class="group-modal-close" onclick="closeExportModal()">&times;</button>
		</div>
		<div class="group-modal-body" style="padding: 25px;">
			<p id="export_modal_info" style="margin-bottom: 20px; color: #666; font-size: 14px; text-align: center;"></p>
			
			<!-- 日期范围选择 -->
			<div style="margin-bottom: 20px;">
				<label style="display: block; margin-bottom: 10px; font-weight: bold; color: #333;">📅 选择日期范围：</label>
				<div style="display: flex; flex-wrap: wrap; gap: 8px;">
					<button type="button" onclick="selectDateRange(1)" class="date-range-btn active" data-days="1" style="padding: 8px 16px; border: 1px solid #4CAF50; background: #4CAF50; color: #fff; border-radius: 4px; cursor: pointer; font-size: 13px;">当天</button>
					<button type="button" onclick="selectDateRange(3)" class="date-range-btn" data-days="3" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">3天</button>
					<button type="button" onclick="selectDateRange(7)" class="date-range-btn" data-days="7" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">7天</button>
					<button type="button" onclick="selectDateRange(10)" class="date-range-btn" data-days="10" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">10天</button>
					<button type="button" onclick="selectDateRange(20)" class="date-range-btn" data-days="20" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">20天</button>
					<button type="button" onclick="selectDateRange(30)" class="date-range-btn" data-days="30" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">30天</button>
					<button type="button" onclick="selectDateRange(0)" class="date-range-btn" data-days="0" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 13px;">自定义</button>
				</div>
				<div id="custom_date_range" style="display: none; margin-top: 12px; padding: 12px; background: #f9f9f9; border-radius: 4px;">
					<span>从</span>
					<input type="text" id="export_start_date" onclick="WdatePicker({ dateFmt:'yyyyMMdd'})" class="input Wdate" style="width: 100px; margin: 0 5px;" value="<?php echo date('Ymd', strtotime('-7 day')); ?>">
					<span>到</span>
					<input type="text" id="export_end_date" onclick="WdatePicker({ dateFmt:'yyyyMMdd'})" class="input Wdate" style="width: 100px; margin: 0 5px;" value="<?php echo date('Ymd'); ?>">
				</div>
				<p id="date_range_hint" style="margin-top: 8px; color: #888; font-size: 12px;">将导出 <?php echo date('Y-m-d'); ?> 的数据</p>
			</div>
			
			<!-- 格式选择 -->
			<div>
				<label style="display: block; margin-bottom: 10px; font-weight: bold; color: #333;">📁 选择导出格式：</label>
				<div style="display: flex; gap: 15px; justify-content: center;">
					<button onclick="doExport('txt')" style="background: #2196F3; color: #fff; border: none; padding: 15px 25px; border-radius: 8px; cursor: pointer; font-size: 14px; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.3s; min-width: 110px;">
						<span style="font-size: 24px;">📄</span>
						<span>TXT 格式</span>
						<span style="font-size: 10px; opacity: 0.8;">仅URL列表</span>
					</button>
					<button onclick="doExport('csv')" style="background: #4CAF50; color: #fff; border: none; padding: 15px 25px; border-radius: 8px; cursor: pointer; font-size: 14px; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.3s; min-width: 110px;">
						<span style="font-size: 24px;">📊</span>
						<span>CSV 格式</span>
						<span style="font-size: 10px; opacity: 0.8;">含抓取统计</span>
					</button>
				</div>
			</div>
		</div>
	</div>
</div>
<style>
.date-range-btn:hover { border-color: #4CAF50 !important; color: #4CAF50 !important; }
.date-range-btn.active { background: #4CAF50 !important; color: #fff !important; border-color: #4CAF50 !important; }
</style>

<!-- 指定域名导出弹窗 -->
<div id="custom_export_modal" class="group-modal-overlay">
	<div class="group-modal" style="width: 550px;">
		<div class="group-modal-header" style="background: linear-gradient(135deg, #9C27B0 0%, #7B1FA2 100%);">
			<h3>🎯 指定域名导出</h3>
			<button class="group-modal-close" onclick="closeCustomExportModal()">&times;</button>
		</div>
		<div class="group-modal-body" style="padding: 25px;">
			<p style="margin-bottom: 15px; color: #666; font-size: 14px;">输入要导出的顶级域名（每行一个），系统将导出这些域名被蜘蛛抓取的URL记录。</p>
			
			<!-- 域名输入区 -->
			<div style="margin-bottom: 20px;">
				<label style="display: block; margin-bottom: 10px; font-weight: bold; color: #333;">📋 域名列表：</label>
				<textarea id="custom_export_domains" placeholder="example1.com&#10;example2.net&#10;example3.cn" style="width: 100%; height: 120px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; resize: vertical; font-family: monospace;"></textarea>
				<p style="margin-top: 5px; color: #888; font-size: 12px;">💡 提示：每行输入一个顶级域名，如 example.com</p>
			</div>
			
			<!-- 导出类型选择 -->
			<div style="margin-bottom: 20px;">
				<label style="display: block; margin-bottom: 10px; font-weight: bold; color: #333;">🔍 蜘蛛类型：</label>
				<div style="display: flex; gap: 10px; flex-wrap: wrap;">
					<label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
						<input type="radio" name="custom_export_type" value="all" checked> 全部蜘蛛
					</label>
					<label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
						<input type="radio" name="custom_export_type" value="mobile"> 百度移动
					</label>
					<label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
						<input type="radio" name="custom_export_type" value="pc"> 百度PC
					</label>
				</div>
			</div>
			
			<!-- 链接过滤条件 -->
			<div style="margin-bottom: 20px;">
				<label style="display: block; margin-bottom: 10px; font-weight: bold; color: #333;">🔗 链接过滤：</label>
				<div style="display: flex; gap: 15px; flex-wrap: wrap;">
					<label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
						<input type="checkbox" name="custom_export_filter" id="filter_inner_only" value="inner_only"> 仅内页
					</label>
					<label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
						<input type="checkbox" name="custom_export_filter" id="filter_subdomain_only" value="subdomain_only"> 仅二级域名
					</label>
				</div>
				<p style="margin-top: 8px; color: #888; font-size: 12px;">
					💡 仅内页：排除首页，只导出内页链接<br>
					💡 仅二级域名：排除 www/m/wap 等主域，只导出其他二级域名（不含内页）
				</p>
			</div>
			
			<!-- 日期范围选择 -->
			<div style="margin-bottom: 20px;">
				<label style="display: block; margin-bottom: 10px; font-weight: bold; color: #333;">📅 日期范围：</label>
				<div style="display: flex; flex-wrap: wrap; gap: 8px;">
					<button type="button" onclick="selectCustomDateRange(1)" class="custom-date-range-btn active" data-days="1" style="padding: 8px 14px; border: 1px solid #9C27B0; background: #9C27B0; color: #fff; border-radius: 4px; cursor: pointer; font-size: 12px;">当天</button>
					<button type="button" onclick="selectCustomDateRange(3)" class="custom-date-range-btn" data-days="3" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">3天</button>
					<button type="button" onclick="selectCustomDateRange(7)" class="custom-date-range-btn" data-days="7" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">7天</button>
					<button type="button" onclick="selectCustomDateRange(10)" class="custom-date-range-btn" data-days="10" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">10天</button>
					<button type="button" onclick="selectCustomDateRange(20)" class="custom-date-range-btn" data-days="20" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">20天</button>
					<button type="button" onclick="selectCustomDateRange(30)" class="custom-date-range-btn" data-days="30" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">30天</button>
					<button type="button" onclick="selectCustomDateRange(0)" class="custom-date-range-btn" data-days="0" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">自定义</button>
				</div>
				<div id="custom_date_range_inputs" style="display: none; margin-top: 12px; padding: 12px; background: #f9f9f9; border-radius: 4px;">
					<span>从</span>
					<input type="text" id="custom_export_start_date" onclick="WdatePicker({ dateFmt:'yyyyMMdd'})" class="input Wdate" style="width: 100px; margin: 0 5px;" value="<?php echo date('Ymd', strtotime('-7 day')); ?>">
					<span>到</span>
					<input type="text" id="custom_export_end_date" onclick="WdatePicker({ dateFmt:'yyyyMMdd'})" class="input Wdate" style="width: 100px; margin: 0 5px;" value="<?php echo date('Ymd'); ?>">
				</div>
				<p id="custom_date_range_hint" style="margin-top: 8px; color: #888; font-size: 12px;">将导出 <?php echo date('Y-m-d'); ?> 的数据</p>
			</div>
			
			<!-- 格式选择与导出 -->
			<div>
				<label style="display: block; margin-bottom: 10px; font-weight: bold; color: #333;">📁 导出格式：</label>
				<div style="display: flex; gap: 15px; justify-content: center;">
					<button onclick="doCustomExport('txt')" style="background: #2196F3; color: #fff; border: none; padding: 15px 25px; border-radius: 8px; cursor: pointer; font-size: 14px; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.3s; min-width: 110px;">
						<span style="font-size: 24px;">📄</span>
						<span>TXT 格式</span>
						<span style="font-size: 10px; opacity: 0.8;">仅URL列表</span>
					</button>
					<button onclick="doCustomExport('csv')" style="background: #4CAF50; color: #fff; border: none; padding: 15px 25px; border-radius: 8px; cursor: pointer; font-size: 14px; display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all 0.3s; min-width: 110px;">
						<span style="font-size: 24px;">📊</span>
						<span>CSV 格式</span>
						<span style="font-size: 10px; opacity: 0.8;">含抓取统计</span>
					</button>
				</div>
			</div>
		</div>
	</div>
</div>
<!-- URL查询弹窗 -->
<div id="url_query_modal" class="group-modal-overlay">
	<div class="group-modal" style="width: 750px; max-height: 90vh;">
		<div class="group-modal-header" style="background: linear-gradient(135deg, #00BCD4 0%, #0097A7 100%);">
			<h3>🔍 URL抓取查询</h3>
			<button class="group-modal-close" onclick="closeUrlQueryModal()">&times;</button>
		</div>
		<div class="group-modal-body" style="padding: 20px; max-height: calc(90vh - 120px); overflow-y: auto;">
			<!-- URL输入区 -->
			<div style="margin-bottom: 20px;">
				<label style="display: block; margin-bottom: 10px; font-weight: bold; color: #333;">📋 输入URL（每行一个）：</label>
				<textarea id="url_query_input" placeholder="http://example.com/page1.html&#10;http://example.com/page2.html&#10;example.com/page3.html" style="width: 100%; height: 120px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; resize: vertical; font-family: monospace;"></textarea>
				<p style="margin-top: 5px; color: #888; font-size: 12px;">💡 提示：支持带协议或不带协议的URL，不带协议时会自动尝试http和https</p>
			</div>
			
			<!-- 日期范围选择 -->
			<div style="margin-bottom: 20px;">
				<label style="display: block; margin-bottom: 10px; font-weight: bold; color: #333;">📅 时间范围：</label>
				<div style="display: flex; flex-wrap: wrap; gap: 8px;">
					<button type="button" onclick="selectUrlQueryDateRange(1)" class="url-query-date-btn active" data-days="1" style="padding: 8px 14px; border: 1px solid #00BCD4; background: #00BCD4; color: #fff; border-radius: 4px; cursor: pointer; font-size: 12px;">当天</button>
					<button type="button" onclick="selectUrlQueryDateRange(3)" class="url-query-date-btn" data-days="3" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">3天</button>
					<button type="button" onclick="selectUrlQueryDateRange(7)" class="url-query-date-btn" data-days="7" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">7天</button>
					<button type="button" onclick="selectUrlQueryDateRange(10)" class="url-query-date-btn" data-days="10" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">10天</button>
					<button type="button" onclick="selectUrlQueryDateRange(20)" class="url-query-date-btn" data-days="20" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">20天</button>
					<button type="button" onclick="selectUrlQueryDateRange(30)" class="url-query-date-btn" data-days="30" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">30天</button>
					<button type="button" onclick="selectUrlQueryDateRange(0)" class="url-query-date-btn" data-days="0" style="padding: 8px 14px; border: 1px solid #ddd; background: #fff; color: #333; border-radius: 4px; cursor: pointer; font-size: 12px;">自定义</button>
				</div>
				<div id="url_query_custom_date" style="display: none; margin-top: 12px; padding: 12px; background: #f9f9f9; border-radius: 4px;">
					<span>从</span>
					<input type="text" id="url_query_start_date" onclick="WdatePicker({ dateFmt:'yyyyMMdd'})" class="input Wdate" style="width: 100px; margin: 0 5px;" value="<?php echo date('Ymd', strtotime('-7 day')); ?>">
					<span>到</span>
					<input type="text" id="url_query_end_date" onclick="WdatePicker({ dateFmt:'yyyyMMdd'})" class="input Wdate" style="width: 100px; margin: 0 5px;" value="<?php echo date('Ymd'); ?>">
				</div>
				<p id="url_query_date_hint" style="margin-top: 8px; color: #888; font-size: 12px;">将查询 <?php echo date('Y-m-d'); ?> 的数据</p>
			</div>
			
			<!-- 查询按钮 -->
			<div style="text-align: center; margin-bottom: 20px;">
				<button onclick="doUrlQuery()" style="background: linear-gradient(135deg, #00BCD4 0%, #0097A7 100%); color: #fff; border: none; padding: 12px 40px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold;">
					🔍 开始查询
				</button>
			</div>
			
			<!-- 查询结果区域 -->
			<div id="url_query_result_area" style="display: none;">
				<div style="border-top: 2px solid #00BCD4; padding-top: 15px;">
					<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
						<div>
							<strong style="color: #333;">📊 查询结果</strong>
							<span id="url_query_summary" style="color: #666; font-size: 13px; margin-left: 10px;"></span>
						</div>
						<button onclick="exportUrlQueryResult()" style="background: #4CAF50; color: #fff; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-size: 12px; margin-right: 8px;">
							📥 导出CSV
						</button>
						<button onclick="copyMatchedUrls()" style="background: #2196F3; color: #fff; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-size: 12px;">
							📋 复制链接
						</button>
					</div>
					<div style="overflow-x: auto;">
						<table id="url_query_result_table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
							<thead>
								<tr style="background: #f5f5f5;">
									<th style="padding: 10px; border: 1px solid #ddd; text-align: left;">URL</th>
									<th style="padding: 10px; border: 1px solid #ddd; text-align: center; width: 80px;">PC抓取</th>
									<th style="padding: 10px; border: 1px solid #ddd; text-align: center; width: 80px;">移动抓取</th>
									<th style="padding: 10px; border: 1px solid #ddd; text-align: center; width: 80px;">抓取总数</th>
								</tr>
							</thead>
							<tbody id="url_query_result_body">
							</tbody>
						</table>
					</div>
				</div>
			</div>
			
			<!-- 加载中提示 -->
			<div id="url_query_loading" style="display: none; text-align: center; padding: 30px;">
				<div style="color: #00BCD4; font-size: 16px;">⏳ 正在查询中...</div>
			</div>
		</div>
		<div class="group-modal-footer">
			<button class="btn-secondary" onclick="closeUrlQueryModal()">关闭</button>
		</div>
	</div>
</div>

<style>
.custom-date-range-btn:hover { border-color: #9C27B0 !important; color: #9C27B0 !important; }
.custom-date-range-btn.active { background: #9C27B0 !important; color: #fff !important; border-color: #9C27B0 !important; }
.url-query-date-btn:hover { border-color: #00BCD4 !important; color: #00BCD4 !important; }
.url-query-date-btn.active { background: #00BCD4 !important; color: #fff !important; border-color: #00BCD4 !important; }
</style>

<script>
// 分组相关全局变量
var currentGroupId = null;
var groupsData = [];

// 页面加载时初始化分组列表
$(document).ready(function() {
	loadGroupsList();
});

// 加载分组列表
function loadGroupsList() {
	$.ajax({
		url: 'groups_manager.php?action=list',
		type: 'GET',
		dataType: 'json',
		success: function(res) {
			if (res.success) {
				groupsData = res.groups;
				renderGroupDropdown(res.groups);
			}
		}
	});
}

// 渲染分组下拉菜单
function renderGroupDropdown(groups) {
	var html = '';
	groups.forEach(function(group) {
		var isActive = (currentGroupId == group.id) ? 'active' : '';
		html += '<div onclick="selectGroup(' + group.id + ', \'' + escapeHtml(group.name) + '\')" class="group-option ' + isActive + '" style="padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #eee;">';
		html += '<span style="margin-right:8px;">📁</span>' + escapeHtml(group.name);
		html += '<span style="color:#999; font-size:12px; margin-left:10px;">(' + group.domains.length + '个域名)</span>';
		html += '</div>';
	});
	
	if (groups.length === 0) {
		html = '<div style="padding: 15px; text-align:center; color:#999;">暂无分组，点击"添加分组"创建</div>';
	}
	
	$('#group_list_container').html(html);
}

// HTML转义
function escapeHtml(text) {
	var div = document.createElement('div');
	div.appendChild(document.createTextNode(text));
	return div.innerHTML;
}

// 打开龙虎榜（新窗口）
function openLeaderboard(type) {
	var url = 'leaderboard.php?type=' + type;
	if (currentGroupId) {
		url += '&group_id=' + currentGroupId;
	}
	window.open(url, '_blank');
}

// 切换分组下拉菜单
function toggleGroupDropdown() {
	var menu = document.getElementById('group_dropdown_menu');
	menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

// 点击外部关闭下拉菜单
$(document).click(function(e) {
	if (!$(e.target).closest('.group-dropdown').length) {
		$('#group_dropdown_menu').hide();
	}
});

// 选择分组
function selectGroup(groupId, groupName) {
	currentGroupId = groupId;
	$('#current_group_id').val(groupId || '');
	$('#current_group_name').text(groupName);
	$('#group_dropdown_menu').hide();
	
	// 更新下拉菜单选中状态
	loadGroupsList();
	
	// 重新加载数据
	reloadCurrentTabData();
}

// 重新加载当前标签页数据
function reloadCurrentTabData() {
	// 如果在访问记录标签页
	if ($('#records_tab').is(':visible')) {
		var currentDate = $('#sday').val() || '<?php echo $time; ?>';
		get_list('5000.php?zong--' + currentDate + '--p--1' + (currentGroupId ? '--g--' + currentGroupId : ''), true);
		
		// 同时刷新图表数据
		reloadCharts();
	}
	// 如果在域名明细标签页
	if ($('#domains_tab').is(':visible')) {
		loadDomainStats();
	}
}

// 刷新图表数据（支持分组）
function reloadCharts() {
	var groupParam = currentGroupId ? '&group_id=' + currentGroupId : '';
	
	// 刷新饼图
	var pieDay = $('#pie_tab span.cur').attr('data') || '0';
	$.ajax({
		url: './tongjis.php?a-' + pieDay + groupParam,
		success: function(data) {
			try {
				var result = JSON.parse(data);
				$('#chart_pie_day_box').html(result.html);
			} catch(e) { console.error('饼图刷新失败:', e); }
		}
	});
	
	// 刷新小时分布图
	var lineDay = $('#line_tab span.cur').attr('data') || '0';
	$.ajax({
		url: './tongjis.php?b-' + lineDay + groupParam,
		success: function(data) {
			try {
				var result = JSON.parse(data);
				$('#chart_line_day_box').html(result.html);
			} catch(e) { console.error('小时图刷新失败:', e); }
		}
	});
	
	// 刷新趋势图
	var weekDays = $('#week_tab span.cur').attr('data') || '10';
	var spiderType = $('#spider_type_select').val() || 'baidu';
	$.ajax({
		url: './tongjis.php?c-' + weekDays + '-' + spiderType + groupParam,
		success: function(data) {
			try {
				var result = JSON.parse(data);
				$('#chart_line_week_box').html(result.html);
			} catch(e) { console.error('趋势图刷新失败:', e); }
		}
	});
}

// 打开添加分组弹窗
function openAddGroupModal() {
	$('#add_group_name').val('');
	$('#add_group_remark').val('');
	$('#add_group_domains').val('');
	$('#add_group_conflict_area').hide().html('');
	$('#add_group_modal').css('display', 'flex');
}

// 关闭添加分组弹窗
function closeAddGroupModal() {
	$('#add_group_modal').hide();
}

// 提交添加分组
function submitAddGroup() {
	var name = $('#add_group_name').val().trim();
	var remark = $('#add_group_remark').val().trim();
	var domains = $('#add_group_domains').val().trim();
	
	if (!name) {
		alert('请输入分组名称');
		return;
	}
	
	$.ajax({
		url: 'groups_manager.php?action=create',
		type: 'POST',
		data: { name: name, remark: remark, domains: domains },
		dataType: 'json',
		success: function(res) {
			if (res.success) {
				alert('分组创建成功！');
				closeAddGroupModal();
				loadGroupsList();
			} else if (res.has_conflicts) {
				// 显示冲突提示
				showConflictAlert('add', res.conflicts, name, remark, domains);
			} else {
				alert(res.message || '创建失败');
			}
		},
		error: function() {
			alert('请求失败，请重试');
		}
	});
}

// 显示冲突提示
function showConflictAlert(mode, conflicts, name, remark, domains) {
	var html = '<div class="conflict-alert">';
	html += '<h4>⚠️ 发现以下域名已存在于其他分组：</h4>';
	html += '<div class="conflict-list">';
	conflicts.forEach(function(c) {
		html += '<div class="conflict-item">• ' + c.domain + ' → 已在【' + c.group_name + '】</div>';
	});
	html += '</div>';
	html += '<div class="conflict-actions">';
	html += '<button class="btn-primary" onclick="handleConflict(\'' + mode + '\', \'move\')">从原分组移动到新分组</button>';
	html += '<button class="btn-secondary" onclick="handleConflict(\'' + mode + '\', \'skip\')">跳过重复域名</button>';
	html += '</div>';
	html += '</div>';
	
	if (mode === 'add') {
		$('#add_group_conflict_area').html(html).show();
		// 存储冲突数据
		window.pendingConflicts = conflicts;
	} else {
		$('#edit_group_conflict_area').html(html).show();
		window.pendingConflicts = conflicts;
	}
}

// 处理冲突
function handleConflict(mode, handleMode) {
	var name, remark, domains, groupId;
	
	if (mode === 'add') {
		name = $('#add_group_name').val().trim();
		remark = $('#add_group_remark').val().trim();
		domains = $('#add_group_domains').val().trim();
		
		$.ajax({
			url: 'groups_manager.php?action=create_force',
			type: 'POST',
			data: {
				name: name,
				remark: remark,
				domains: domains,
				handle_mode: handleMode,
				conflict_domains: JSON.stringify(window.pendingConflicts)
			},
			dataType: 'json',
			success: function(res) {
				if (res.success) {
					var msg = '分组创建成功！';
					if (handleMode === 'move') {
						msg += '\n已移动 ' + res.moved_count + ' 个域名';
					} else {
						msg += '\n已跳过 ' + res.skipped_count + ' 个重复域名';
					}
					alert(msg);
					closeAddGroupModal();
					loadGroupsList();
				} else {
					alert(res.message || '创建失败');
				}
			}
		});
	}
}

// 打开管理分组弹窗
function openManageGroupModal() {
	$('#manage_group_modal').css('display', 'flex');
	loadManageGroupList();
}

// 关闭管理分组弹窗
function closeManageGroupModal() {
	$('#manage_group_modal').hide();
}

// 加载管理分组列表
function loadManageGroupList() {
	$.ajax({
		url: 'groups_manager.php?action=list',
		type: 'GET',
		dataType: 'json',
		success: function(res) {
			if (res.success) {
				var html = '';
				if (res.groups.length === 0) {
					html = '<div style="text-align:center; color:#999; padding:30px;">暂无分组</div>';
				} else {
					res.groups.forEach(function(group) {
						var hasRemark = group.remark && group.remark.trim() !== '';
						html += '<div class="group-list-item">';
						html += '<div class="group-info">';
						html += '<div class="group-name">📁 ' + escapeHtml(group.name);
						if (hasRemark) {
							html += ' <span style="color:#607D8B; font-size:12px; cursor:pointer;" onclick="viewGroupRemark(' + group.id + ', \'' + escapeHtml(group.name) + '\')">📝</span>';
						}
						html += '</div>';
						html += '<div class="group-domain-count">' + group.domains.length + ' 个域名 · 创建于 ' + group.created_at + '</div>';
						html += '</div>';
						html += '<div class="group-actions">';
						html += '<button class="btn-remark" onclick="viewGroupRemark(' + group.id + ', \'' + escapeHtml(group.name) + '\')" style="background:#607D8B; color:#fff; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; margin-right:5px;">备注</button>';
						html += '<button class="btn-edit" onclick="openEditGroupModal(' + group.id + ')">编辑</button>';
						html += '<button class="btn-danger" onclick="deleteGroup(' + group.id + ', \'' + escapeHtml(group.name) + '\')">删除</button>';
						html += '</div>';
						html += '</div>';
					});
				}
				$('#manage_group_list').html(html);
			}
		}
	});
}

// 打开编辑分组弹窗
function openEditGroupModal(groupId) {
	$.ajax({
		url: 'groups_manager.php?action=get&id=' + groupId,
		type: 'GET',
		dataType: 'json',
		success: function(res) {
			if (res.success) {
				$('#edit_group_id').val(res.group.id);
				$('#edit_group_name').val(res.group.name);
				$('#edit_group_remark').val(res.group.remark || '');
				$('#edit_group_domains').val(res.group.domains.join('\n'));
				$('#edit_group_conflict_area').hide().html('');
				$('#edit_group_modal').css('display', 'flex');
			} else {
				alert(res.message || '获取分组信息失败');
			}
		}
	});
}

// 查看分组备注
function viewGroupRemark(groupId, groupName) {
	$.ajax({
		url: 'groups_manager.php?action=get&id=' + groupId,
		type: 'GET',
		dataType: 'json',
		success: function(res) {
			if (res.success) {
				$('#view_remark_group_id').val(groupId);
				$('#view_remark_title').text(groupName + ' - 备注');
				var remark = res.group.remark && res.group.remark.trim() !== '' ? res.group.remark : '暂无备注';
				$('#view_remark_display').text(remark);
				$('#edit_remark_input').val(res.group.remark || '');
				
				// 显示查看模式
				$('#view_remark_display').show();
				$('#edit_remark_area').hide();
				$('#view_remark_buttons').show();
				$('#edit_remark_buttons').hide();
				
				$('#view_remark_modal').css('display', 'flex');
			} else {
				alert(res.message || '获取备注失败');
			}
		}
	});
}

// 切换到编辑模式
function switchToEditRemark() {
	$('#view_remark_display').hide();
	$('#edit_remark_area').show();
	$('#view_remark_buttons').hide();
	$('#edit_remark_buttons').show();
	$('#edit_remark_input').focus();
}

// 取消编辑
function cancelEditRemark() {
	$('#view_remark_display').show();
	$('#edit_remark_area').hide();
	$('#view_remark_buttons').show();
	$('#edit_remark_buttons').hide();
}

// 保存备注
function saveRemark() {
	var groupId = $('#view_remark_group_id').val();
	var remark = $('#edit_remark_input').val().trim();
	
	$.ajax({
		url: 'groups_manager.php?action=update_remark',
		type: 'POST',
		data: { id: groupId, remark: remark },
		dataType: 'json',
		success: function(res) {
			if (res.success) {
				// 更新显示
				$('#view_remark_display').text(remark || '暂无备注');
				
				// 切换回查看模式
				cancelEditRemark();
				
				// 刷新分组列表
				loadGroupsList();
				loadManageGroupList();
				
				alert('备注保存成功！');
			} else {
				alert(res.message || '保存失败');
			}
		},
		error: function() {
			alert('请求失败，请重试');
		}
	});
}

// 关闭查看备注弹窗
function closeViewRemarkModal() {
	$('#view_remark_modal').hide();
	// 重置为查看模式
	cancelEditRemark();
}

// 关闭编辑分组弹窗
function closeEditGroupModal() {
	$('#edit_group_modal').hide();
}

// 提交编辑分组
function submitEditGroup() {
	var id = $('#edit_group_id').val();
	var name = $('#edit_group_name').val().trim();
	var remark = $('#edit_group_remark').val().trim();
	var domains = $('#edit_group_domains').val().trim();
	
	if (!name) {
		alert('请输入分组名称');
		return;
	}
	
	$.ajax({
		url: 'groups_manager.php?action=update',
		type: 'POST',
		data: { id: id, name: name, remark: remark, domains: domains },
		dataType: 'json',
		success: function(res) {
			if (res.success) {
				alert('分组更新成功！');
				closeEditGroupModal();
				loadGroupsList();
				loadManageGroupList();
			} else if (res.has_conflicts) {
				showConflictAlert('edit', res.conflicts, name, remark, domains);
			} else {
				alert(res.message || '更新失败');
			}
		}
	});
}

// 删除分组
function deleteGroup(groupId, groupName) {
	if (!confirm('确定要删除分组【' + groupName + '】吗？\n\n注意：删除分组不会删除域名的访问记录。')) {
		return;
	}
	
	$.ajax({
		url: 'groups_manager.php?action=delete',
		type: 'POST',
		data: { id: groupId },
		dataType: 'json',
		success: function(res) {
			if (res.success) {
				alert('分组删除成功！');
				loadGroupsList();
				loadManageGroupList();
				
				// 如果删除的是当前选中的分组，切换回全部
				if (currentGroupId == groupId) {
					selectGroup(null, '全部域名');
				}
			} else {
				alert(res.message || '删除失败');
			}
		}
	});
}

// 导出类型临时存储
var pendingExportType = '';
var exportDateDays = 1; // 默认当天

// 显示导出选项弹窗
function showExportModal(type) {
	pendingExportType = type;
	exportDateDays = 1; // 重置为当天
	
	var typeNames = {
		'all': '全部链接',
		'mobile': '移动端链接',
		'pc': 'PC端链接'
	};
	var typeName = typeNames[type] || '链接';
	var groupName = $('#current_group_name').text() || '全部域名';
	
	$('#export_modal_info').html('分组：<b>' + escapeHtml(groupName) + '</b> | 类型：<b>' + typeName + '</b>');
	
	// 重置日期选择按钮状态
	$('.date-range-btn').removeClass('active').css({'background': '#fff', 'color': '#333', 'border-color': '#ddd'});
	$('.date-range-btn[data-days="1"]').addClass('active').css({'background': '#4CAF50', 'color': '#fff', 'border-color': '#4CAF50'});
	$('#custom_date_range').hide();
	updateDateRangeHint();
	
	$('#export_modal').css('display', 'flex');
}

// 选择日期范围
function selectDateRange(days) {
	exportDateDays = days;
	
	// 更新按钮状态
	$('.date-range-btn').removeClass('active').css({'background': '#fff', 'color': '#333', 'border-color': '#ddd'});
	$('.date-range-btn[data-days="' + days + '"]').addClass('active').css({'background': '#4CAF50', 'color': '#fff', 'border-color': '#4CAF50'});
	
	// 显示/隐藏自定义日期输入
	if (days === 0) {
		$('#custom_date_range').show();
	} else {
		$('#custom_date_range').hide();
	}
	
	updateDateRangeHint();
}

// 更新日期范围提示
function updateDateRangeHint() {
	var hint = '';
	var today = new Date();
	
	if (exportDateDays === 0) {
		// 自定义
		var startDate = $('#export_start_date').val() || '';
		var endDate = $('#export_end_date').val() || '';
		if (startDate && endDate) {
			hint = '将导出 ' + formatDateDisplay(startDate) + ' 至 ' + formatDateDisplay(endDate) + ' 的数据';
		} else {
			hint = '请选择开始和结束日期';
		}
	} else if (exportDateDays === 1) {
		hint = '将导出 ' + formatDate(today) + ' 的数据';
	} else {
		var startDate = new Date(today);
		startDate.setDate(startDate.getDate() - (exportDateDays - 1));
		hint = '将导出 ' + formatDate(startDate) + ' 至 ' + formatDate(today) + ' 的数据（共' + exportDateDays + '天）';
	}
	
	$('#date_range_hint').html(hint);
}

// 格式化日期为 YYYY-MM-DD
function formatDate(date) {
	var y = date.getFullYear();
	var m = ('0' + (date.getMonth() + 1)).slice(-2);
	var d = ('0' + date.getDate()).slice(-2);
	return y + '-' + m + '-' + d;
}

// 格式化 YYYYMMDD 为 YYYY-MM-DD
function formatDateDisplay(dateStr) {
	if (dateStr.length === 8) {
		return dateStr.substring(0,4) + '-' + dateStr.substring(4,6) + '-' + dateStr.substring(6,8);
	}
	return dateStr;
}

// 关闭导出弹窗
function closeExportModal() {
	$('#export_modal').hide();
	pendingExportType = '';
}

// 执行导出
function doExport(format) {
	var groupId = currentGroupId || '';
	
	// 构建日期参数
	var dateParam = '';
	if (exportDateDays === 0) {
		// 自定义日期范围
		var startDate = $('#export_start_date').val();
		var endDate = $('#export_end_date').val();
		if (!startDate || !endDate) {
			alert('请选择开始和结束日期');
			return;
		}
		dateParam = startDate + '-' + endDate;
	} else {
		dateParam = 'days_' + exportDateDays;
	}
	
	var exportUrl = 'export_group_urls.php?type=' + pendingExportType + '&format=' + format + '&range=' + dateParam;
	if (groupId) {
		exportUrl += '&group_id=' + groupId;
	}
	
	// 关闭弹窗并下载
	closeExportModal();
	window.location.href = exportUrl;
}

// 监听自定义日期变化
$(document).ready(function() {
	$('#export_start_date, #export_end_date').on('change', function() {
		updateDateRangeHint();
	});
	$('#custom_export_start_date, #custom_export_end_date').on('change', function() {
		updateCustomDateRangeHint();
	});
});

// ============== URL抓取查询功能 ==============
var urlQueryDateDays = 1;
var urlQueryResultData = []; // 存储查询结果用于导出

// 显示URL查询弹窗
function showUrlQueryModal() {
	urlQueryDateDays = 1;
	$('#url_query_input').val('');
	$('#url_query_result_area').hide();
	$('#url_query_loading').hide();
	
	// 重置日期按钮
	$('.url-query-date-btn').removeClass('active').css({
		'background': '#fff',
		'color': '#333',
		'border-color': '#ddd'
	});
	$('.url-query-date-btn[data-days="1"]').addClass('active').css({
		'background': '#00BCD4',
		'color': '#fff',
		'border-color': '#00BCD4'
	});
	$('#url_query_custom_date').hide();
	updateUrlQueryDateHint();
	
	$('#url_query_modal').css('display', 'flex');
}

// 关闭URL查询弹窗
function closeUrlQueryModal() {
	$('#url_query_modal').hide();
}

// 选择URL查询日期范围
function selectUrlQueryDateRange(days) {
	urlQueryDateDays = days;
	
	$('.url-query-date-btn').removeClass('active').css({
		'background': '#fff',
		'color': '#333',
		'border-color': '#ddd'
	});
	$('.url-query-date-btn[data-days="' + days + '"]').addClass('active').css({
		'background': '#00BCD4',
		'color': '#fff',
		'border-color': '#00BCD4'
	});
	
	$('#url_query_custom_date').toggle(days === 0);
	updateUrlQueryDateHint();
}

// 更新URL查询日期提示
function updateUrlQueryDateHint() {
	var hint = '';
	var today = new Date();
	
	if (urlQueryDateDays === 0) {
		var startDate = $('#url_query_start_date').val() || '';
		var endDate = $('#url_query_end_date').val() || '';
		if (startDate && endDate) {
			hint = '将查询 ' + formatDateDisplay(startDate) + ' 至 ' + formatDateDisplay(endDate) + ' 的数据';
		} else {
			hint = '请选择开始和结束日期';
		}
	} else if (urlQueryDateDays === 1) {
		hint = '将查询 ' + formatDate(today) + ' 的数据';
	} else {
		var startDate = new Date(today);
		startDate.setDate(startDate.getDate() - (urlQueryDateDays - 1));
		hint = '将查询 ' + formatDate(startDate) + ' 至 ' + formatDate(today) + ' 的数据（共' + urlQueryDateDays + '天）';
	}
	
	$('#url_query_date_hint').html(hint);
}

// 执行URL查询
function doUrlQuery() {
	var urlsInput = $('#url_query_input').val().trim();
	if (!urlsInput) {
		alert('请输入要查询的URL');
		return;
	}
	
	// 构建日期参数
	var rangeParam = '';
	if (urlQueryDateDays === 0) {
		var startDate = $('#url_query_start_date').val();
		var endDate = $('#url_query_end_date').val();
		if (!startDate || !endDate) {
			alert('请选择开始和结束日期');
			return;
		}
		rangeParam = startDate + '-' + endDate;
	} else {
		rangeParam = 'days_' + urlQueryDateDays;
	}
	
	// 显示加载中
	$('#url_query_result_area').hide();
	$('#url_query_loading').show();
	
	$.ajax({
		url: 'url_query.php',
		type: 'POST',
		data: {
			urls: urlsInput,
			range: rangeParam
		},
		dataType: 'json',
		success: function(res) {
			$('#url_query_loading').hide();
			
			if (res.success) {
				urlQueryResultData = res.data;
				renderUrlQueryResult(res);
			} else {
				alert(res.message || '查询失败');
			}
		},
		error: function() {
			$('#url_query_loading').hide();
			alert('请求失败，请重试');
		}
	});
}

// 渲染URL查询结果
function renderUrlQueryResult(res) {
	var data = res.data;
	var totalUrls = res.total_urls;
	var foundUrls = res.found_urls;
	
	// 过滤掉没有抓取记录的URL
	var filteredData = data.filter(function(item) {
		return item.total > 0;
	});
	
	// 更新用于导出的数据（只保留有记录的）
	urlQueryResultData = filteredData;
	
	// 计算PC和移动总抓取数
	var totalPc = 0;
	var totalMobile = 0;
	filteredData.forEach(function(item) {
		totalPc += item.pc;
		totalMobile += item.mobile;
	});
	
	$('#url_query_summary').html('共查询 ' + totalUrls + ' 个URL，找到 ' + foundUrls + ' 个有抓取记录 | <span style="color:#FF9800;">PC总抓取: ' + totalPc + '</span> | <span style="color:#2196F3;">移动总抓取: ' + totalMobile + '</span>');
	
	var html = '';
	if (filteredData.length === 0) {
		html = '<tr><td colspan="4" style="padding: 20px; text-align: center; color: #999;">未找到任何抓取记录</td></tr>';
	} else {
		filteredData.forEach(function(item) {
			html += '<tr>';
			html += '<td style="padding: 8px 10px; border: 1px solid #ddd; word-break: break-all;"><a href="' + escapeHtml(item.url) + '" target="_blank" style="color: #1976D2; text-decoration: none;">' + escapeHtml(item.url) + '</a></td>';
			html += '<td style="padding: 8px 10px; border: 1px solid #ddd; text-align: center;">' + item.pc + '</td>';
			html += '<td style="padding: 8px 10px; border: 1px solid #ddd; text-align: center;">' + item.mobile + '</td>';
			html += '<td style="padding: 8px 10px; border: 1px solid #ddd; text-align: center; font-weight: bold; color: #4CAF50;">' + item.total + '</td>';
			html += '</tr>';
		});
	}
	
	$('#url_query_result_body').html(html);
	$('#url_query_result_area').show();
}

// 导出URL查询结果为CSV
function exportUrlQueryResult() {
	if (urlQueryResultData.length === 0) {
		alert('没有可导出的数据');
		return;
	}
	
	// 构建CSV内容
	var csv = '\uFEFF'; // BOM for UTF-8
	csv += 'URL,PC抓取,移动抓取,抓取总数\n';
	
	urlQueryResultData.forEach(function(item) {
		csv += '"' + item.url.replace(/"/g, '""') + '",' + item.pc + ',' + item.mobile + ',' + item.total + '\n';
	});
	
	// 创建下载
	var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
	var link = document.createElement('a');
	var url = URL.createObjectURL(blob);
	link.setAttribute('href', url);
	link.setAttribute('download', 'URL抓取查询_' + formatDate(new Date()).replace(/-/g, '') + '.csv');
	link.style.visibility = 'hidden';
	document.body.appendChild(link);
	link.click();
	document.body.removeChild(link);
}

// 复制匹配到的链接
function copyMatchedUrls() {
	if (urlQueryResultData.length === 0) {
		alert('没有可复制的链接');
		return;
	}
	
	// 构建链接列表
	var urls = urlQueryResultData.map(function(item) {
		return item.url;
	}).join('\n');
	
	// 复制到剪贴板
	if (navigator.clipboard && navigator.clipboard.writeText) {
		navigator.clipboard.writeText(urls).then(function() {
			alert('已复制 ' + urlQueryResultData.length + ' 个链接到剪贴板');
		}).catch(function() {
			fallbackCopyUrls(urls);
		});
	} else {
		fallbackCopyUrls(urls);
	}
}

// 备用复制方法
function fallbackCopyUrls(text) {
	var textarea = document.createElement('textarea');
	textarea.value = text;
	textarea.style.position = 'fixed';
	textarea.style.left = '-9999px';
	document.body.appendChild(textarea);
	textarea.select();
	try {
		document.execCommand('copy');
		alert('已复制 ' + urlQueryResultData.length + ' 个链接到剪贴板');
	} catch (err) {
		alert('复制失败，请手动复制');
	}
	document.body.removeChild(textarea);
}

// ============== 指定域名导出功能 ==============
var customExportDateDays = 1;

// 显示指定域名导出弹窗
function showCustomExportModal() {
	customExportDateDays = 1;
	$('#custom_export_domains').val('');
	$('input[name="custom_export_type"][value="all"]').prop('checked', true);
	
	// 重置日期选择按钮状态
	$('.custom-date-range-btn').removeClass('active').css({'background': '#fff', 'color': '#333', 'border-color': '#ddd'});
	$('.custom-date-range-btn[data-days="1"]').addClass('active').css({'background': '#9C27B0', 'color': '#fff', 'border-color': '#9C27B0'});
	$('#custom_date_range_inputs').hide();
	updateCustomDateRangeHint();
	
	$('#custom_export_modal').css('display', 'flex');
}

// 关闭指定域名导出弹窗
function closeCustomExportModal() {
	$('#custom_export_modal').hide();
}

// 选择自定义导出日期范围
function selectCustomDateRange(days) {
	customExportDateDays = days;
	
	// 更新按钮状态
	$('.custom-date-range-btn').removeClass('active').css({'background': '#fff', 'color': '#333', 'border-color': '#ddd'});
	$('.custom-date-range-btn[data-days="' + days + '"]').addClass('active').css({'background': '#9C27B0', 'color': '#fff', 'border-color': '#9C27B0'});
	
	// 显示/隐藏自定义日期输入
	if (days === 0) {
		$('#custom_date_range_inputs').show();
	} else {
		$('#custom_date_range_inputs').hide();
	}
	
	updateCustomDateRangeHint();
}

// 更新自定义导出日期范围提示
function updateCustomDateRangeHint() {
	var hint = '';
	var today = new Date();
	
	if (customExportDateDays === 0) {
		var startDate = $('#custom_export_start_date').val() || '';
		var endDate = $('#custom_export_end_date').val() || '';
		if (startDate && endDate) {
			hint = '将导出 ' + formatDateDisplay(startDate) + ' 至 ' + formatDateDisplay(endDate) + ' 的数据';
		} else {
			hint = '请选择开始和结束日期';
		}
	} else if (customExportDateDays === 1) {
		hint = '将导出 ' + formatDate(today) + ' 的数据';
	} else {
		var startDate = new Date(today);
		startDate.setDate(startDate.getDate() - (customExportDateDays - 1));
		hint = '将导出 ' + formatDate(startDate) + ' 至 ' + formatDate(today) + ' 的数据（共' + customExportDateDays + '天）';
	}
	
	$('#custom_date_range_hint').html(hint);
}

// 执行指定域名导出
function doCustomExport(format) {
	var domains = $('#custom_export_domains').val().trim();
	if (!domains) {
		alert('请输入要导出的域名');
		return;
	}
	
	var exportType = $('input[name="custom_export_type"]:checked').val();
	
	// 获取链接过滤条件
	var filterInnerOnly = $('#filter_inner_only').is(':checked') ? '1' : '0';
	var filterSubdomainOnly = $('#filter_subdomain_only').is(':checked') ? '1' : '0';
	
	// 构建日期参数
	var rangeParam = '';
	if (customExportDateDays === 0) {
		var startDate = $('#custom_export_start_date').val();
		var endDate = $('#custom_export_end_date').val();
		if (!startDate || !endDate) {
			alert('请选择开始和结束日期');
			return;
		}
		rangeParam = startDate + '-' + endDate;
	} else {
		rangeParam = 'days_' + customExportDateDays;
	}
	
	// 使用POST提交域名列表
	var form = $('<form>', {
		'action': 'export_custom_domains.php',
		'method': 'POST',
		'target': '_blank'
	});
	form.append($('<input>', {'type': 'hidden', 'name': 'domains', 'value': domains}));
	form.append($('<input>', {'type': 'hidden', 'name': 'type', 'value': exportType}));
	form.append($('<input>', {'type': 'hidden', 'name': 'range', 'value': rangeParam}));
	form.append($('<input>', {'type': 'hidden', 'name': 'format', 'value': format}));
	form.append($('<input>', {'type': 'hidden', 'name': 'filter_inner', 'value': filterInnerOnly}));
	form.append($('<input>', {'type': 'hidden', 'name': 'filter_subdomain', 'value': filterSubdomainOnly}));
	
	$('body').append(form);
	form.submit();
	form.remove();
	
	closeCustomExportModal();
}

// 原有的监听器绑定
$(document).ready(function() {
	$('#export_start_date, #export_end_date').on('change', function() {
		updateDateRangeHint();
	});
});
</script>
<?php  ?>

<!-- 访问记录标签页 -->
<div id="records_tab" class="tab_content">
<div style="height: 300px;">
	<div style="position: relative;">
		<div id="line_tab" class="chart_tab" style="margin-left:31%;">
			<span class="cur" data="0">今日</span>
			<span data="1">昨日</span>
			<span data="2">前日</span>
		</div>
		<div id="chart_line_day_box" class="chart_box"><?php echo $b ?></div>
	</div>
	<div style="position: relative;text-align:right">
		<div id="pie_tab" class="chart_tab" style="text-align: right;width: 30%;left: -10px;">
			<span class="cur" data="0">今日</span>
			<span data="1">昨日</span>
			<span data="7">7日</span>
			<span data="30">30日</span>
			<span data="365">1年</span>
		</div>
		<div id="chart_pie_day_box" class="chart_box"><?php echo $a; ?></div>
	</div>
</div>
<div style="position: relative;">
	<div class="type_tab" id="week_type_tab" style="display:none;">
		<span class="cur" data="">全部</span>
	</div>
	<span id="week_type" data=""></span>
	<div id="week_tab" class="chart_tab">
		<span data="10" class="cur">近10日</span>
		<span data="30">近30日</span>
		<span data="365">近1年</span>
	</div>
	<div id="chart_line_week_box" class="chart_box">
		<div id="week_spider_select" style="position:absolute; top:15px; right:15px; z-index:1000;">
			<select id="spider_type_select" style="padding:4px 10px; border:1px solid #ccc; border-radius:3px; font-size:12px; background:#fff; cursor:pointer; outline:none; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
				<option value="baidu" selected>百度</option>
				<option value="google">谷歌</option>
				<option value="sogou">搜狗</option>
				<option value="360">360</option>
				<option value="yisou">神马</option>
				<option value="byte">今日头条</option>
				<option value="">全部</option>
			</select>
		</div>
		<?php echo $c; ?>
	</div>
</div>
<br>
<script type="text/javascript">
// 获取带分组参数的图表URL
function getChartUrl(baseUrl) {
	if (currentGroupId) {
		return baseUrl + '&group_id=' + currentGroupId;
	}
	return baseUrl;
}

$(function () {
	$('#pie_tab span').click(function(){
		$(this).siblings().removeClass('cur').end().addClass('cur');
		var dayValue = $(this).attr('data');
		var gurl = getChartUrl('./tongjis.php?a-'+dayValue);
		$('#chart_pie_day_box .highcharts-container').css({ opacity: 0.3 });
		$('#chart_pie_day').append('<div class="loading">加载中...</div>');
		$.ajax({
			url:gurl,
			timeout: 10000,
			success:function(data){
				try {
					var data=JSON.parse(data);
					$('#chart_pie_day_box').html(data.html);
					$('#chart_pie_day .loading').remove();
				} catch(e) {
					console.error('饼图数据解析错误:', e);
					$('#chart_pie_day_box').html('<div style="text-align:center;padding:50px;">数据加载失败</div>');
					$('#chart_pie_day .loading').remove();
				}
			},
			error: function(xhr, status, error) {
				console.error('饼图AJAX请求失败:', status, error);
				$('#chart_pie_day_box').html('<div style="text-align:center;padding:50px;">网络请求失败</div>');
				$('#chart_pie_day .loading').remove();
			}
		});
		
		// 联动更新折线图（时段分布图）- 只对0,1,2有效
		if (dayValue == '0' || dayValue == '1' || dayValue == '2') {
			// 同步更新折线图选项卡高亮
			$('#line_tab span').removeClass('cur');
			$('#line_tab span[data="' + dayValue + '"]').addClass('cur');
			
			// 刷新折线图
			var lineUrl = getChartUrl('./tongjis.php?b-'+dayValue);
			$('#chart_line_day_box .highcharts-container').css({ opacity: 0.3 });
	$.ajax({
				url:lineUrl,
		success:function(data){
					var data=JSON.parse(data);
					$('#chart_line_day_box').html(data.html);
		}
	});
		}
	});
	
	$('#line_tab span').click(function(){
		$(this).siblings().removeClass('cur').end().addClass('cur');
		var dayValue = $(this).attr('data');
		var gurl = getChartUrl('./tongjis.php?b-'+dayValue);
		$('#chart_line_day_box .highcharts-container').css({ opacity: 0.3 });
		$('#chart_line_day').append('<div class="loading">加载中...</div>');
		$.ajax({
			url:gurl,
			success:function(data){
				var data=JSON.parse(data);
				$('#chart_line_day_box').html(data.html);
				$('#chart_line_day .loading').remove();
			}
		});
		
		// 联动更新饼图
		$('#pie_tab span').removeClass('cur');
		$('#pie_tab span[data="' + dayValue + '"]').addClass('cur');
		
		var pieUrl = getChartUrl('./tongjis.php?a-'+dayValue);
		$('#chart_pie_day_box .highcharts-container').css({ opacity: 0.3 });
		$.ajax({
			url:pieUrl,
			success:function(data){
				var data=JSON.parse(data);
				$('#chart_pie_day_box').html(data.html);
			}
		});
	});
	
	$('#week_type_tab span').click(function(){
		$(this).siblings().removeClass('cur').end().addClass('cur');
		$('#week_type').attr('data',$(this).attr('data'));
		var spider_type = $('#spider_type_select').val();
		var gurl = getChartUrl('./tongjis.php?c-'+$('#week_type').attr('data')+'-'+spider_type);
		$('#chart_line_week_box .highcharts-container').css({ opacity: 0.3 });
		$('#chart_line_week').append('<div class="loading">加载中...</div>');
		$.ajax({
			url:gurl,
			success:function(data){
				var data=JSON.parse(data);
				$('#chart_line_week_box').html(data.html);
				$('#chart_line_week .loading').remove();
			}
		});
	});
	
	$('#week_tab span').click(function(){
		$(this).siblings().removeClass('cur').end().addClass('cur');
		var spider_type = $('#spider_type_select').val();
		var gurl = getChartUrl('./tongjis.php?c-'+$(this).attr('data')+'-'+spider_type);
		$('#chart_line_week_box .highcharts-container').css({ opacity: 0.3 });
		$('#chart_line_week').append('<div class="loading">加载中...</div>');
		$.ajax({
			url:gurl,
			success:function(data){
				var data=JSON.parse(data);
				$('#chart_line_week_box').html(data.html);
				$('#chart_line_week .loading').remove();
			}
		});
	});
	
	// 蜘蛛类型下拉选择事件
	$('#spider_type_select').change(function(){
		var spider_type = $(this).val();
		var days = $('#week_tab span.cur').attr('data') || '10';
		var gurl = getChartUrl('./tongjis.php?c-'+days+'-'+spider_type);
		$('#chart_line_week_box .highcharts-container').css({ opacity: 0.3 });
		$('#chart_line_week').append('<div class="loading">加载中...</div>');
		$.ajax({
			url:gurl,
			success:function(data){
				var data=JSON.parse(data);
				$('#chart_line_week_box').html(data.html);
				$('#chart_line_week .loading').remove();
			}
		});
	});
});

// 上一天/下一天切换函数
function changeDaySpider(days) {
	var currentDate = $('#sday').val();
	if (!currentDate || currentDate.length !== 8) {
		alert('请先选择一个有效的日期');
		return;
	}
	
	// 解析日期 yyyyMMdd
	var year = parseInt(currentDate.substring(0, 4));
	var month = parseInt(currentDate.substring(4, 6)) - 1; // JavaScript月份从0开始
	var day = parseInt(currentDate.substring(6, 8));
	
	// 创建日期对象并加减天数
	var date = new Date(year, month, day);
	date.setDate(date.getDate() + days);
	
	// 格式化新日期为 yyyyMMdd
	var newYear = date.getFullYear();
	var newMonth = ('0' + (date.getMonth() + 1)).slice(-2);
	var newDay = ('0' + date.getDate()).slice(-2);
	var newDate = newYear + newMonth + newDay;
	
	// 更新输入框的值
	$('#sday').val(newDate);
	
	// 自动加载新日期的数据（包含分组参数）
	get_list('5000.php?zong--' + newDate + '--p--1' + (currentGroupId ? '--g--' + currentGroupId : ''), true);
	
	// 联动更新饼图和折线图
	updateChartsForDate(newDate);
}

// 根据指定日期更新饼图和折线图
function updateChartsForDate(dateStr) {
	// 计算选择的日期距离今天的天数差（用于更新选项卡高亮）
	var today = new Date();
	today.setHours(0, 0, 0, 0);
	
	var year = parseInt(dateStr.substring(0, 4));
	var month = parseInt(dateStr.substring(4, 6)) - 1;
	var day = parseInt(dateStr.substring(6, 8));
	var selectedDate = new Date(year, month, day);
	
	var diffTime = today.getTime() - selectedDate.getTime();
	var diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));
	
	// 更新折线图选项卡高亮（只有0,1,2才有对应选项卡）
	$('#line_tab span').removeClass('cur');
	if (diffDays >= 0 && diffDays <= 2) {
		$('#line_tab span[data="' + diffDays + '"]').addClass('cur');
	}
	
	// 更新饼图选项卡高亮（只有0,1才有对应选项卡）
	$('#pie_tab span').removeClass('cur');
	if (diffDays >= 0 && diffDays <= 1) {
		$('#pie_tab span[data="' + diffDays + '"]').addClass('cur');
	}
	
	// 刷新折线图 - 传递日期字符串
	var lineUrl = getChartUrl('./tongjis.php?b-' + dateStr);
	$('#chart_line_day_box .highcharts-container').css({ opacity: 0.3 });
	$.ajax({
		url: lineUrl,
		success: function(data) {
			var data = JSON.parse(data);
			$('#chart_line_day_box').html(data.html);
		}
	});
	
	// 刷新饼图 - 传递日期字符串（饼图会显示该天的蜘蛛占比）
	var pieUrl = getChartUrl('./tongjis.php?a-' + dateStr);
	$('#chart_pie_day_box .highcharts-container').css({ opacity: 0.3 });
	$.ajax({
		url: pieUrl,
		success: function(data) {
			var data = JSON.parse(data);
			$('#chart_pie_day_box').html(data.html);
		}
	});
}
</script>

<table border="0" align="center" cellpadding="3" cellspacing="0" class="table_b" style="margin-top:10px">
	<tbody>
	<?php
	$zongshu=$Googlebotsa+$baiduspidersa+$liuSpidersa+$Sogouspidersa+$Yisouspidersa+$Bytespidersa;
	echo "
	<tr class='tdbg item_title'>
		<td colspan='6'>
			<i class=\"typcn typcn-cog\"></i> 蜘蛛访问明细&nbsp;
			<button type=\"button\" class=\"button\" onclick=\"changeDaySpider(-1);\" style=\"padding:3px 8px;\">上一天</button>&nbsp;
			<input id=\"sday\" type=\"text\" onclick=\"WdatePicker({ dateFmt:'yyyyMMdd', onpicked:function(dp){var d=dp.cal.getDateStr(); get_list('5000.php?zong--'+d+'--p--1'+(currentGroupId?'--g--'+currentGroupId:''), true); updateChartsForDate(d);} })\" class=\"input Wdate\" style=\"width:85px;\" value=\"$time\" >&nbsp;
			<button type=\"button\" class=\"button\" onclick=\"changeDaySpider(1);\" style=\"padding:3px 8px;\">下一天</button>&nbsp;
			<button type=\"button\" class=\"button\" onclick=\"get_list('5000.php?zong--'+\$('#sday').val()+'--p--1'+(currentGroupId?'--g--'+currentGroupId:''), true); updateChartsForDate(\$('#sday').val());\">查看</button>&nbsp;<span id='scount'><span class='glist'><a href=''><font color='red'>全部($zongshu)</font></a>
			</span>&nbsp;<span class='glist'><a href='5000.php?Baiduspider--$time--p--1'><font>百度($baiduspidersa)</font></a></span>&nbsp;
			<span class='glist'><a href='5000.php?Googlebot--$time--p--1'><font>Google($Googlebotsa)</font></a></span>&nbsp;
			<span class='glist'><a href='5000.php?360Spider--$time--p--1'><font>360蜘蛛($liuSpidersa)</font></a>
			</span>&nbsp;<span class='glist'><a href='5000.php?Sogou--$time--p--1'><font>搜狗($Sogouspidersa)</font></a></span>&nbsp;
			<span class='glist'><a href='5000.php?Yisouspider--$time--p--1'><font>神马($Yisouspidersa)</font></a></span>&nbsp;
			<span class='glist'><a href='5000.php?Bytespider--$time--p--1'><font>今日头条($Bytespidersa)</font></a></span>&nbsp;</span></td>
	</tr>
	<tr>
	  <td width='50' align='center' class='title_bg'>id</td>
	  <td width='100' align='center' class='title_bg'>蜘蛛名称</td>
	  <td width='110' align='center' class='title_bg'>IP地址</td>
	   <td width='80' align='center' class='title_bg'>国家/城市</td>
      <td class='title_bg'>访问地址</td>
	  <td width='60' align='center' class='title_bg'>模型</td>
	  <td width='140' align='center' class='title_bg'>访问时间</td>
    </tr>
	</tbody>
	<tbody id='rlist'>
	";
	if($list){
	foreach($list as $k=>$v){
		echo "<tr class='tdbg'>
	<td align='center'>$k</td>
	<td align='center'>$v[2]</td>
	<td align='center'><a title='点击查询IP归属' href='https://www.ip138.com/iplookup.asp?ip=$v[1]&amp;action=2' target='_blank'>$v[1]</a></td>
	<td align='center'><font color='green'>中国</font></td>
	<td><a target='_blank' title='打开此链接' href='$v[3]'>$v[3]</a></td>
	<td align='center'>文章新闻</td>
	<td align='center'><font color='red'>$v[0]</font></td></tr>
		";
	}
	}else{
		echo "<tr bgcolor='#ffffff'>
			<td colspan='7' height='25' align='center'>暂无百度蜘蛛记录！</td>
		</tr>";
	}
	?>	
	</tbody>
	<tbody>
	<tr>
      <td colspan="7" class="tdbg content_page" align="center"><a>共 <font id="total"><?php echo $counts; ?></font> 条</a>&nbsp;<span class="glist" id="pages"><?php echo $paged ?></span></td>
	</tr>
	</tbody>
</table>
<script type="text/javascript">
bind_page();
function bind_page(){
	// 蜘蛛类型筛选点击
	$('.glist a').off('click').on('click', function(e){
		e.preventDefault();
		var href=$(this).attr('href');
		if(href){
			// 如果有分组且链接中没有分组参数，则添加分组参数
			if(currentGroupId && href.indexOf('--g--') === -1){
				href += '--g--' + currentGroupId;
			}
			get_list(href, true);
		}
	});
	
	// 分页链接点击
	$('#pages a').off('click').on('click', function(e){
		e.preventDefault();
		var href=$(this).attr('href');
		if(href){
			// 如果有分组且链接中没有分组参数，则添加分组参数
			if(currentGroupId && href.indexOf('--g--') === -1){
				href += '--g--' + currentGroupId;
			}
			get_list(href, false);
		}
	});
}

function get_list(url, scrollToTable){
	// 记录当前滚动位置
	var tableTop = $('#rlist').closest('table').offset().top - 100;
	
	$.ajax({
		url:url,
		success:function(data){
			var data=JSON.parse(data)
			
			$('#pages').html(data.pages);
			str='';
			$.each(data.list,function($n,$vo){
				str+='<tr class="tdbg">';
				str+='	<td align="center">'+$vo['id']+'</td>';
				str+='	<td align="center">'+$vo['name']+'</td>';
				str+='	<td align="center"><a title="点击查询IP归属" href="https://www.ip138.com/iplookup.asp?ip='+$vo['ip']+'&action=2" target="_blank">'+$vo['ip']+'</a></td>';
				str+='	<td align="center">'+$vo['city']+'</td>';
				str+='	<td>'+$vo['url']+'</td>';
				str+='	<td align="center">'+$vo['typename']+'</td>';
				str+='	<td align="center">'+$vo['time']+'</td>';
				str+='</tr>';
			});
			cstr='';
			$.each(data.scount,function($n,$vo){
				cstr+='<span class="glist"><a href="'+$vo['url']+'"><font '+($vo['key']==data.spider ? 'color="red"':'')+'>'+$vo['name']+'('+$vo['count']+')</font></a></span>&nbsp;';
			});
			$('#rlist').html(str);
			$('#scount').html(cstr);
			$('#total').html(data.total);
			
			bind_page();
			
			// 翻页时保持滚动位置在表格区域
			if(!scrollToTable){
				$('html, body').scrollTop(tableTop);
			}
		}
	});
}
</script>
<div class="runtime"></div>  
</div>
<!-- 访问记录标签页结束 -->

<!-- 域名明细标签页 -->
<div id="domains_tab" class="tab_content" style="display:none;">
	<div style="margin-bottom: 20px;">
		<span>时间范围：</span>
		<select id="date_range_type" onchange="toggleCustomRange()" style="margin-right: 10px;">
			<option value="today">今天</option>
			<option value="yesterday">昨天</option>
			<option value="week">最近7天</option>
			<option value="month">最近30天</option>
			<option value="custom">自定义范围</option>
		</select>
		
		<span id="custom_range_inputs" style="display:none;">
			从 <input id="start_date" type="text" onclick="WdatePicker({ dateFmt:'yyyyMMdd'})" class="input Wdate" style="width:85px;" value="<?php echo date('Ymd', strtotime('-7 day')); ?>" >
			到 <input id="end_date" type="text" onclick="WdatePicker({ dateFmt:'yyyyMMdd'})" class="input Wdate" style="width:85px;" value="<?php echo date('Ymd'); ?>" >
		</span>
		
		<button type="button" class="button" onclick="loadDomainStats();" style="margin-left: 10px;">查看</button>
		
		<span style="margin-left: 20px;">排序方式：</span>
		<select id="sort_type" style="margin-left: 5px;" onchange="refreshDomainStats()">
			<option value="baidu">按百度访问量</option>
			<option value="total">按总访问量</option>
			<option value="google">按谷歌访问量</option>
			<option value="sogou">按搜狗访问量</option>
			<option value="other">按其他蜘蛛访问量</option>
		</select>
		
		<span style="margin-left: 15px; color: #666;">点击域名查看详情</span>
	</div>
	
	<table border="0" align="center" cellpadding="3" cellspacing="0" class="table_b">
		<tbody>
			<tr class='tdbg item_title'>
				<td colspan='8'>
					<i class="typcn typcn-globe"></i> 域名访问统计 <span id="domain_stats_date"></span>
				</td>
			</tr>
			<tr>
				<td width='50' align='center' class='title_bg'>排名</td>
				<td width='200' align='center' class='title_bg'>域名</td>
				<td width='80' align='center' class='title_bg'>总访问</td>
				<td width='80' align='center' class='title_bg'>百度</td>
				<td width='80' align='center' class='title_bg'>谷歌</td>
				<td width='80' align='center' class='title_bg'>搜狗</td>
				<td width='80' align='center' class='title_bg'>其他</td>
				<td width='140' align='center' class='title_bg'>最后访问</td>
			</tr>
		</tbody>
		<tbody id='domain_list'>
			<tr bgcolor='#ffffff'>
				<td colspan='8' height='25' align='center'>点击"查看"按钮加载域名统计数据</td>
			</tr>
		</tbody>
	</table>
</div>


<script type="text/javascript">
// 标签页切换功能
function showTab(tabName) {
	// 隐藏所有标签页
	document.getElementById('records_tab').style.display = 'none';
	document.getElementById('domains_tab').style.display = 'none';
	
	// 显示选中的标签页
	document.getElementById(tabName + '_tab').style.display = 'block';
	
	// 更新标签样式
	var tabs = document.querySelectorAll('#admin_sub_title .sub a');
	tabs.forEach(function(tab) {
		tab.style.fontWeight = 'normal';
		tab.style.color = '#666';
	});
	
	if (tabName === 'records') {
		tabs[0].style.fontWeight = 'bold';
		tabs[0].style.color = '#333';
	} else if (tabName === 'domains') {
		tabs[1].style.fontWeight = 'bold';
		tabs[1].style.color = '#333';
		
		// 如果是域名明细标签页，且还没有加载过数据，则自动加载今天的数据
		var domainList = document.getElementById('domain_list');
		if (domainList && domainList.innerHTML.indexOf('点击"查看"按钮加载域名统计数据') !== -1) {
			// 设置默认为今天和百度排序
			document.getElementById('date_range_type').value = 'today';
			document.getElementById('sort_type').value = 'baidu';
			// 自动加载今天的数据
			setTimeout(function() {
				loadDomainStats();
			}, 100);
		}
	}
}

// 切换自定义范围输入框显示
function toggleCustomRange() {
	var rangeType = document.getElementById('date_range_type').value;
	var customInputs = document.getElementById('custom_range_inputs');
	
	if (rangeType === 'custom') {
		customInputs.style.display = 'inline';
	} else {
		customInputs.style.display = 'none';
	}
}

// 加载域名统计数据
function loadDomainStats() {
	var rangeType = document.getElementById('date_range_type').value;
	var dateRange;
	
	if (rangeType === 'custom') {
		var startDate = document.getElementById('start_date').value;
		var endDate = document.getElementById('end_date').value;
		
		if (!startDate || !endDate) {
			alert('请选择开始和结束日期');
			return;
		}
		
		if (startDate > endDate) {
			alert('开始日期不能大于结束日期');
			return;
		}
		
		dateRange = startDate + '-' + endDate;
	} else {
		dateRange = rangeType;
	}
	
	// 显示加载状态
	document.getElementById('domain_list').innerHTML = '<tr><td colspan="8" align="center">正在加载数据...</td></tr>';
	
	// 获取当前分组ID
	var groupId = currentGroupId || '';
	var url = 'domain_stats.php?action=domain_list&date_range=' + encodeURIComponent(dateRange);
	if (groupId) {
		url += '&group_id=' + groupId;
	}
	
	// AJAX请求域名统计数据
	var xhr = new XMLHttpRequest();
	xhr.open('GET', url, true);
	xhr.onreadystatechange = function() {
		if (xhr.readyState === 4 && xhr.status === 200) {
			try {
				var response = JSON.parse(xhr.responseText);
				if (response.success) {
					displayDomainStats(response.data, response.display_label, response.date_range);
				} else {
					document.getElementById('domain_list').innerHTML = '<tr><td colspan="8" align="center" style="color:red;">加载失败：' + (response.message || '未知错误') + '</td></tr>';
				}
			} catch (e) {
				document.getElementById('domain_list').innerHTML = '<tr><td colspan="8" align="center" style="color:red;">数据解析错误</td></tr>';
			}
		}
	};
	xhr.send();
}

// 刷新域名统计数据（仅重新排序，不重新加载）
function refreshDomainStats() {
	// 如果已经有数据，则重新排序显示
	if (window.currentDomainData && window.currentDomainData.length > 0) {
		var currentLabel = document.getElementById('domain_stats_date').innerHTML;
		var currentDateRange = window.currentDateRange || 'today';
		displayDomainStats(window.currentDomainData, currentLabel, currentDateRange);
	}
}

// 显示域名统计数据
function displayDomainStats(domains, displayLabel, dateRange) {
	var html = '';
	document.getElementById('domain_stats_date').innerHTML = displayLabel;
	
	// 存储当前的日期范围和数据，供域名详情和排序使用
	window.currentDateRange = dateRange;
	window.currentDomainData = domains;
	
	if (domains.length === 0) {
		html = '<tr><td colspan="8" align="center">暂无域名访问数据</td></tr>';
	} else {
		// 获取排序方式
		var sortType = document.getElementById('sort_type').value;
		
		// 根据排序方式对域名进行排序
		domains.sort(function(a, b) {
			var aValue, bValue;
			switch(sortType) {
				case 'baidu':
					aValue = a.spiders['百度'] || 0;
					bValue = b.spiders['百度'] || 0;
					break;
				case 'google':
					aValue = a.spiders['谷歌'] || 0;
					bValue = b.spiders['谷歌'] || 0;
					break;
				case 'sogou':
					aValue = a.spiders['搜狗'] || 0;
					bValue = b.spiders['搜狗'] || 0;
					break;
				case 'other':
					aValue = a.total - (a.spiders['百度'] || 0) - (a.spiders['谷歌'] || 0) - (a.spiders['搜狗'] || 0);
					bValue = b.total - (b.spiders['百度'] || 0) - (b.spiders['谷歌'] || 0) - (b.spiders['搜狗'] || 0);
					break;
				case 'total':
				default:
					aValue = a.total;
					bValue = b.total;
					break;
			}
			return bValue - aValue; // 降序排列
		});
		
		domains.forEach(function(domain, index) {
			var otherSpiders = domain.total - (domain.spiders['百度'] || 0) - (domain.spiders['谷歌'] || 0) - (domain.spiders['搜狗'] || 0);
			html += '<tr class="tdbg" style="cursor:pointer;" onclick="showDomainDetail(\'' + domain.domain + '\')">';
			html += '<td align="center">' + (index + 1) + '</td>';
			html += '<td><a href="javascript:void(0)" style="color:#1890ff;">' + domain.domain + '</a></td>';
			html += '<td align="center"><strong>' + domain.total + '</strong></td>';
			html += '<td align="center">' + (domain.spiders['百度'] || 0) + '</td>';
			html += '<td align="center">' + (domain.spiders['谷歌'] || 0) + '</td>';
			html += '<td align="center">' + (domain.spiders['搜狗'] || 0) + '</td>';
			html += '<td align="center">' + otherSpiders + '</td>';
			html += '<td align="center">' + (domain.last_visit || '-') + '</td>';
			html += '</tr>';
		});
	}
	
	document.getElementById('domain_list').innerHTML = html;
}

// 显示域名详情（新窗口打开）
function showDomainDetail(domain) {
	var dateRange = window.currentDateRange || 'today';
	var url = 'domain_detail.php?domain=' + encodeURIComponent(domain) + '&date_range=' + encodeURIComponent(dateRange);
	window.open(url, '_blank', 'width=1200,height=800,scrollbars=yes,resizable=yes');
}


// 导出全局数据功能
function exportGlobalData(element) {
	
	// 获取当前日期范围（如果在域名明细页面有选择的话）
	var dateRange = window.currentDateRange || 'today';
	
	// 获取触发元素
	var targetElement = element || document.querySelector('a[onclick*="exportGlobalData"]');
	
	if (!targetElement) {
		alert('导出功能初始化失败，请刷新页面重试！');
		return;
	}
	
	// 显示导出状态
	var originalText = targetElement.innerHTML;
	targetElement.innerHTML = '导出中...';
	targetElement.style.pointerEvents = 'none';
	
	try {
		// 方法1：使用window.open下载文件
		var downloadUrl = 'export_global_data.php?date_range=' + encodeURIComponent(dateRange);
		console.log('开始下载:', downloadUrl);
		
		// 创建临时链接进行下载
		var link = document.createElement('a');
		link.href = downloadUrl;
		link.download = '全局蜘蛛数据_' + new Date().toISOString().slice(0, 10) + '.csv';
		link.style.display = 'none';
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		
		// 恢复按钮状态
		setTimeout(function() {
			targetElement.innerHTML = originalText;
			targetElement.style.pointerEvents = 'auto';
		}, 1000);
		
	} catch(error) {
		// 错误处理
		targetElement.innerHTML = originalText;
		targetElement.style.pointerEvents = 'auto';
		alert('导出过程中出现错误：' + error.message + '\n\n请检查：\n1. export_global_data.php 文件是否存在\n2. 服务器是否正常运行\n3. 浏览器控制台是否有错误信息');
		console.error('导出错误:', error);
	}
}

// 获取日期范围标签
function getDateRangeLabel(dateRange) {
	switch(dateRange) {
		case 'today': return '今天';
		case 'yesterday': return '昨天';
		case 'week': return '一个星期';
		case 'month': return '一个月';
		default: 
			if (dateRange.includes('-')) {
				return '自定义范围 (' + dateRange + ')';
			}
			return dateRange;
	}
}

// 页面加载完成后默认显示访问记录标签页
document.addEventListener('DOMContentLoaded', function() {
	showTab('records');
});
</script>

</div>
</body>
</html>