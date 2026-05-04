<?php
/**
 * 蜘蛛筛选卡片组件
 * 统一的蜘蛛类型选择器，供所有模块使用
 */

/**
 * 渲染蜘蛛筛选卡片（完整版，包含启用开关）
 * @param string $id 唯一标识符（用于区分不同的选择器实例）
 * @param bool $enabled 是否启用蜘蛛筛选
 * @param array $types 蜘蛛类型配置
 */
function renderSpiderSelector($id = 'default', $enabled = false, $types = []) {
    // 默认配置
    if (empty($types)) {
        $types = [
            'baidu_pc' => false,
            'baidu_mobile' => false,
            'google' => false,
            'sogou' => false
        ];
    }
    
    ?>
    
    <!-- 蜘蛛筛选卡片 -->
    <div class="spider-selector-card">
        <div class="spider-selector-header">
            <h3>🕷️ 蜘蛛筛选配置</h3>
            <p class="spider-selector-desc">配置此任务针对哪些蜘蛛类型生效</p>
        </div>
        
        <div class="spider-selector-body">
            <!-- 启用开关 -->
            <div class="spider-selector-switch">
                <label class="switch-label">
                    <input type="checkbox" 
                           id="spiderFilterEnabled_<?php echo $id; ?>" 
                           name="spider_filter_enabled_<?php echo $id; ?>" 
                           value="1" 
                           <?php echo $enabled ? 'checked' : ''; ?>
                           onchange="toggleSpiderTypes('<?php echo $id; ?>')">
                    <span class="switch-slider"></span>
                    <span class="switch-text">启用蜘蛛筛选</span>
                </label>
                <p class="switch-hint">关闭则对所有访问者生效（包括蜘蛛和普通用户）</p>
            </div>
            
            <!-- 蜘蛛类型选择 -->
            <div id="spiderTypesContainer_<?php echo $id; ?>" class="spider-types-container" style="<?php echo $enabled ? '' : 'display: none;'; ?>">
                <div class="spider-types-header">
                    <span>选择蜘蛛类型：</span>
                    <span class="spider-types-hint">至少选择一种蜘蛛类型</span>
                </div>
                
                <div class="spider-types-grid">
                    <!-- 百度PC -->
                    <label class="spider-type-item">
                        <input type="checkbox" 
                               name="spider_type_baidu_pc_<?php echo $id; ?>" 
                               value="1" 
                               <?php echo $types['baidu_pc'] ? 'checked' : ''; ?>>
                        <div class="spider-type-content">
                            <div class="spider-type-name">百度PC</div>
                        </div>
                    </label>
                    
                    <!-- 百度移动 -->
                    <label class="spider-type-item">
                        <input type="checkbox" 
                               name="spider_type_baidu_mobile_<?php echo $id; ?>" 
                               value="1" 
                               <?php echo $types['baidu_mobile'] ? 'checked' : ''; ?>>
                        <div class="spider-type-content">
                            <div class="spider-type-name">百度移动</div>
                        </div>
                    </label>
                    
                    <!-- 谷歌蜘蛛 -->
                    <label class="spider-type-item">
                        <input type="checkbox" 
                               name="spider_type_google_<?php echo $id; ?>" 
                               value="1" 
                               <?php echo $types['google'] ? 'checked' : ''; ?>>
                        <div class="spider-type-content">
                            <div class="spider-type-name">谷歌蜘蛛</div>
                        </div>
                    </label>
                    
                    <!-- 搜狗蜘蛛 -->
                    <label class="spider-type-item">
                        <input type="checkbox" 
                               name="spider_type_sogou_<?php echo $id; ?>" 
                               value="1" 
                               <?php echo $types['sogou'] ? 'checked' : ''; ?>>
                        <div class="spider-type-content">
                            <div class="spider-type-name">搜狗蜘蛛</div>
                        </div>
                    </label>
                </div>
                
                <div class="spider-security-notice">
                    <div class="security-icon">🔒</div>
                    <div class="security-text">
                        <strong>安全验证：</strong>系统会自动验证蜘蛛IP真实性，防止假冒蜘蛛触发跳转
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <style>
    /* 蜘蛛筛选卡片样式 */
    .spider-selector-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
    }
    
    .spider-selector-header h3 {
        margin: 0 0 8px 0;
        font-size: 18px;
        font-weight: 600;
        color: var(--text);
    }
    
    .spider-selector-desc {
        margin: 0;
        font-size: 14px;
        color: var(--text-secondary);
    }
    
    .spider-selector-body {
        margin-top: 20px;
    }
    
    /* 启用开关 */
    .spider-selector-switch {
        padding: 16px;
        background: var(--bg-dark);
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .switch-label {
        display: flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
        user-select: none;
    }
    
    .switch-slider {
        position: relative;
        width: 48px;
        height: 24px;
        background: #ccc;
        border-radius: 24px;
        transition: all 0.3s;
        flex-shrink: 0;
    }
    
    .switch-slider::before {
        content: '';
        position: absolute;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: white;
        top: 2px;
        left: 2px;
        transition: all 0.3s;
    }
    
    input[type="checkbox"]:checked + .switch-slider {
        background: var(--primary);
    }
    
    input[type="checkbox"]:checked + .switch-slider::before {
        transform: translateX(24px);
    }
    
    .switch-label input[type="checkbox"] {
        display: none;
    }
    
    .switch-text {
        font-size: 15px;
        font-weight: 500;
        color: var(--text);
    }
    
    .switch-hint {
        margin: 8px 0 0 60px;
        font-size: 13px;
        color: var(--text-secondary);
    }
    
    /* 蜘蛛类型容器 */
    .spider-types-container {
        padding: 20px;
        background: var(--bg);
        border: 2px dashed var(--border);
        border-radius: 8px;
        animation: fadeIn 0.3s;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .spider-types-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--border);
    }
    
    .spider-types-header > span:first-child {
        font-size: 15px;
        font-weight: 600;
        color: var(--text);
    }
    
    .spider-types-hint {
        font-size: 13px;
        color: var(--text-secondary);
    }
    
    /* 蜘蛛类型网格 - 固定2x2四宫格布局 */
    .spider-types-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 16px;
        max-width: 400px;
    }
    
    .spider-type-item {
        position: relative;
        display: block;
        cursor: pointer;
        user-select: none;
    }
    
    .spider-type-item input[type="checkbox"] {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    
    .spider-type-content {
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 24px 16px;
        min-height: 80px;
        background: var(--bg-card);
        border: 2px solid var(--border);
        border-radius: 10px;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    
    .spider-type-item:hover .spider-type-content {
        border-color: var(--primary);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
        transform: translateY(-2px);
    }
    
    .spider-type-item input[type="checkbox"]:checked + .spider-type-content {
        border-color: var(--primary);
        border-width: 2px;
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.08) 0%, rgba(59, 130, 246, 0.12) 100%);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .spider-type-name {
        font-size: 16px;
        font-weight: 600;
        color: var(--text);
        line-height: 1.3;
    }
    
    .spider-type-item input[type="checkbox"]:checked + .spider-type-content .spider-type-name {
        color: var(--primary);
    }
    
    /* 安全提示 */
    .spider-security-notice {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px;
        background: rgba(59, 130, 246, 0.1);
        border: 1px solid rgba(59, 130, 246, 0.3);
        border-radius: 8px;
    }
    
    .security-icon {
        font-size: 20px;
        flex-shrink: 0;
    }
    
    .security-text {
        font-size: 13px;
        color: var(--text);
        line-height: 1.6;
    }
    
    .security-text strong {
        color: var(--primary);
    }
    </style>
    
    <script>
    // 切换蜘蛛类型显示/隐藏
    function toggleSpiderTypes(id) {
        const enabled = document.getElementById('spiderFilterEnabled_' + id).checked;
        const container = document.getElementById('spiderTypesContainer_' + id);
        
        if (enabled) {
            container.style.display = 'block';
        } else {
            container.style.display = 'none';
        }
    }
    
    // 获取蜘蛛筛选配置（供表单提交时调用）
    function getSpiderFilterConfig(id) {
        const enabled = document.getElementById('spiderFilterEnabled_' + id).checked;
        
        return {
            enabled: enabled,
            types: {
                baidu_pc: document.querySelector('input[name="spider_type_baidu_pc_' + id + '"]').checked,
                baidu_mobile: document.querySelector('input[name="spider_type_baidu_mobile_' + id + '"]').checked,
                google: document.querySelector('input[name="spider_type_google_' + id + '"]').checked,
                sogou: document.querySelector('input[name="spider_type_sogou_' + id + '"]').checked
            }
        };
    }
    
    // 兼容旧的调用方式（默认ID）
    if (!window.getSpiderFilterConfig) {
        window.getSpiderFilterConfig = getSpiderFilterConfig;
    }
    </script>
    
    <?php
}

/**
 * 渲染蜘蛛筛选卡片（编辑模式，仅显示蜘蛛类型选择）
 * @param string $id 唯一标识符
 * @param array $types 蜘蛛类型配置
 */
function renderSpiderSelectorEditMode($id = 'edit', $types = []) {
    // 默认配置
    if (empty($types)) {
        $types = [
            'baidu_pc' => false,
            'baidu_mobile' => false,
            'google' => false,
            'sogou' => false
        ];
    }
    
    ?>
    
    <!-- 蜘蛛筛选卡片（编辑模式） -->
    <div class="spider-selector-card-edit">
        <div class="spider-selector-header-edit">
            <h3>🕷️ 蜘蛛筛选配置</h3>
            <p class="spider-selector-desc-edit">选择此任务针对哪些蜘蛛类型生效</p>
        </div>
        
        <div class="spider-selector-body-edit">
            <!-- 蜘蛛类型选择（无启用开关） -->
            <div class="spider-types-grid-edit">
                <!-- 百度PC -->
                <label class="spider-type-item-edit">
                    <input type="checkbox" 
                           name="spider_type_baidu_pc_<?php echo $id; ?>" 
                           value="1" 
                           <?php echo $types['baidu_pc'] ? 'checked' : ''; ?>>
                    <div class="spider-type-content-edit">
                        <div class="spider-type-name-edit">百度PC</div>
                    </div>
                </label>
                
                <!-- 百度移动 -->
                <label class="spider-type-item-edit">
                    <input type="checkbox" 
                           name="spider_type_baidu_mobile_<?php echo $id; ?>" 
                           value="1" 
                           <?php echo $types['baidu_mobile'] ? 'checked' : ''; ?>>
                    <div class="spider-type-content-edit">
                        <div class="spider-type-name-edit">百度移动</div>
                    </div>
                </label>
                
                <!-- 谷歌蜘蛛 -->
                <label class="spider-type-item-edit">
                    <input type="checkbox" 
                           name="spider_type_google_<?php echo $id; ?>" 
                           value="1" 
                           <?php echo $types['google'] ? 'checked' : ''; ?>>
                    <div class="spider-type-content-edit">
                        <div class="spider-type-name-edit">谷歌蜘蛛</div>
                    </div>
                </label>
                
                <!-- 搜狗蜘蛛 -->
                <label class="spider-type-item-edit">
                    <input type="checkbox" 
                           name="spider_type_sogou_<?php echo $id; ?>" 
                           value="1" 
                           <?php echo $types['sogou'] ? 'checked' : ''; ?>>
                    <div class="spider-type-content-edit">
                        <div class="spider-type-name-edit">搜狗蜘蛛</div>
                    </div>
                </label>
            </div>
            
            <div class="spider-hint-edit">
                <span class="hint-icon">💡</span>
                <span class="hint-text">未勾选任何蜘蛛 = 对所有访问者生效</span>
            </div>
        </div>
    </div>
    
    <style>
    /* 编辑模式样式 */
    .spider-selector-card-edit {
        background: var(--bg-card);
        border-radius: 12px;
        padding: 20px;
    }
    
    .spider-selector-header-edit h3 {
        margin: 0 0 8px 0;
        font-size: 18px;
        font-weight: 600;
        color: var(--text);
    }
    
    .spider-selector-desc-edit {
        margin: 0;
        font-size: 14px;
        color: var(--text-secondary);
    }
    
    .spider-selector-body-edit {
        margin-top: 20px;
    }
    
    /* 蜘蛛类型网格 - 2x2布局 */
    .spider-types-grid-edit {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 16px;
    }
    
    .spider-type-item-edit {
        position: relative;
        display: block;
        cursor: pointer;
        user-select: none;
    }
    
    .spider-type-item-edit input[type="checkbox"] {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    
    .spider-type-content-edit {
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 20px 12px;
        min-height: 70px;
        background: var(--bg-dark);
        border: 2px solid var(--border);
        border-radius: 10px;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    
    .spider-type-item-edit:hover .spider-type-content-edit {
        border-color: var(--primary);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
        transform: translateY(-2px);
    }
    
    /* 选中状态 - 高亮显示 */
    .spider-type-item-edit input[type="checkbox"]:checked + .spider-type-content-edit {
        border-color: var(--primary);
        border-width: 3px;
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(59, 130, 246, 0.25) 100%);
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15), 0 4px 12px rgba(59, 130, 246, 0.3);
        transform: scale(1.02);
    }
    
    .spider-type-name-edit {
        font-size: 16px;
        font-weight: 600;
        color: var(--text);
        line-height: 1.3;
    }
    
    /* 选中状态的文字颜色 */
    .spider-type-item-edit input[type="checkbox"]:checked + .spider-type-content-edit .spider-type-name-edit {
        color: var(--primary);
        font-weight: 700;
    }
    
    /* 提示信息 */
    .spider-hint-edit {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 12px;
        background: rgba(59, 130, 246, 0.08);
        border: 1px solid rgba(59, 130, 246, 0.2);
        border-radius: 8px;
        font-size: 13px;
        color: var(--text-secondary);
    }
    
    .hint-icon {
        font-size: 16px;
        flex-shrink: 0;
    }
    
    .hint-text {
        line-height: 1.5;
    }
    </style>
    
    <script>
    // 获取编辑模式的蜘蛛筛选配置
    function getSpiderFilterConfigEditMode(id) {
        const baiduPc = document.querySelector('input[name="spider_type_baidu_pc_' + id + '"]').checked;
        const baiduMobile = document.querySelector('input[name="spider_type_baidu_mobile_' + id + '"]').checked;
        const google = document.querySelector('input[name="spider_type_google_' + id + '"]').checked;
        const sogou = document.querySelector('input[name="spider_type_sogou_' + id + '"]').checked;
        
        // 如果任何一个被选中，则enabled为true
        const enabled = baiduPc || baiduMobile || google || sogou;
        
        return {
            enabled: enabled,
            types: {
                baidu_pc: baiduPc,
                baidu_mobile: baiduMobile,
                google: google,
                sogou: sogou
            }
        };
    }
    </script>
    
    <?php
}
?>

