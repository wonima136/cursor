/**
 * WAF 拦截系统后台 — 通用 JS
 */

/* ── Tab 切换（全局函数，供 onclick 直接调用）──────────── */
function switchTab(tabId) {
    var panels = document.querySelectorAll('.tab-panel');
    var btns   = document.querySelectorAll('.tab-btn');
    for (var i = 0; i < panels.length; i++) panels[i].classList.remove('active');
    for (var j = 0; j < btns.length;   j++) btns[j].classList.remove('active');

    var panel = document.getElementById('tab-' + tabId);
    var btn   = document.querySelector('[data-tab="' + tabId + '"]');
    if (panel) panel.classList.add('active');
    if (btn)   btn.classList.add('active');
    try { localStorage.setItem('waf_active_tab', tabId); } catch (e) {}
}

/* ── 侧边栏开关（移动端）────────────────────────────── */
function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    const mc = document.getElementById('mainContent');
    if (!sb) return;
    sb.classList.toggle('open');
    if (mc) mc.classList.toggle('sidebar-open');
}

/* 点击主内容区关闭侧边栏 */
document.addEventListener('DOMContentLoaded', function () {
    /* 恢复上次激活的 Tab（onclick 已处理点击，这里只做初始化） */
    var activeTab = 'global';
    try { activeTab = localStorage.getItem('waf_active_tab') || 'global'; } catch (e) {}
    if (window.location.hash) {
        var h = window.location.hash.replace('#tab-', '');
        if (['global','pc','mobile','maintain'].indexOf(h) !== -1) activeTab = h;
    }
    switchTab(activeTab);


    const mc = document.getElementById('mainContent');
    if (mc) {
        mc.addEventListener('click', function () {
            const sb = document.getElementById('sidebar');
            if (sb && sb.classList.contains('open')) {
                sb.classList.remove('open');
                mc.classList.remove('sidebar-open');
            }
        });
    }

    /* ── Alert 3 秒后自动消失 ──────────────────────── */
    document.querySelectorAll('.alert').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .5s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 500);
        }, 3000);
    });

    /* ── URL 参数中有 msg= 时 3 秒后清除 ───────────── */
    if (window.location.search.indexOf('msg=') !== -1) {
        // 维护相关消息自动切到维护 Tab
        if (window.location.search.indexOf('device_update') !== -1) {
            switchTab('maintain');
        }
        setTimeout(function () {
            const url = window.location.pathname;
            window.history.replaceState({}, document.title, url);
        }, 3200);
    }

    /* ── 广告模式 radio → 显示/隐藏 URL 输入框 ─────── */
    initAdModeToggle('pc');
    initAdModeToggle('mobile');

    /* ── 模板卡片点击选中 ───────────────────────────── */
    document.querySelectorAll('.template-card').forEach(function (card) {
        card.addEventListener('click', function () {
            const radio = card.querySelector('input[type="radio"]');
            if (!radio) return;
            radio.checked = true;
            document.querySelectorAll('.template-card').forEach(function (c) {
                c.classList.toggle('selected', c.querySelector('input[type="radio"]')?.checked);
            });
        });
    });

    /* ── radio-option 选中高亮 ──────────────────────── */
    document.querySelectorAll('.radio-option input[type="radio"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const group = radio.closest('.radio-group') || radio.closest('form');
            if (!group) return;
            group.querySelectorAll('.radio-option').forEach(function (opt) {
                const r = opt.querySelector('input[type="radio"]');
                opt.classList.toggle('selected', r && r.checked);
            });
        });
        // 初始化
        if (radio.checked) radio.dispatchEvent(new Event('change'));
    });

    /* ── 设备开关 checkbox 文字联动 + 统计注入行显示控制 ─── */
    var statInjectMap = {
        'pcSwitch':     'pcStatInjectRow',
        'mobileSwitch': 'mobileStatInjectRow'
    };
    ['pcSwitch', 'mobileSwitch'].forEach(function (id) {
        const cb = document.getElementById(id);
        if (!cb) return;
        cb.addEventListener('change', function () {
            const labelEl = document.getElementById(id + 'Label');
            if (labelEl) {
                labelEl.textContent = cb.checked ? '已启用' : '已关闭';
                labelEl.className   = 'toggle-text ' + (cb.checked ? 'toggle-on' : 'toggle-off');
            }
            const injectRow = document.getElementById(statInjectMap[id]);
            if (injectRow) injectRow.style.display = cb.checked ? 'none' : '';
        });
    });
});

/* 设备库更新确认 */
function confirmUpdate() {
    return confirm('确认更新设备库？\n\n更新期间页面约 10-30 秒无响应，请勿重复点击。');
}

/* 广告模式选择 → URL 输入框显示/隐藏 */
function initAdModeToggle(prefix) {
    const radios = document.querySelectorAll('input[name="' + prefix + '_ad_mode"]');
    radios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            syncAdUrlVisible(prefix);
        });
        if (radio.checked) syncAdUrlVisible(prefix);
    });
}

/* ── Redis 开关联动 ────────────────────────────────── */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.getElementById('redisEnabledToggle');
        var fields = document.getElementById('redisFields');
        var label  = document.getElementById('redisEnabledText');
        if (!toggle || !fields) return;
        toggle.addEventListener('change', function () {
            if (this.checked) {
                fields.style.display = '';
                if (label) { label.textContent = '已启用'; label.className = 'toggle-text toggle-on'; }
            } else {
                fields.style.display = 'none';
                if (label) { label.textContent = '已关闭'; label.className = 'toggle-text toggle-off'; }
            }
        });
    });
})();

function syncAdUrlVisible(prefix) {
    const selectedRadio = document.querySelector('input[name="' + prefix + '_ad_mode"]:checked');
    const urlWrap = document.getElementById(prefix + '_url_wrap');
    if (!urlWrap) return;
    const mode = selectedRadio ? selectedRadio.value : 'none';
    if (mode === 'iframe' || mode === 'redirect') {
        urlWrap.classList.add('visible');
        const labelEl = document.getElementById(prefix + '_url_label');
        if (labelEl) {
            labelEl.textContent = mode === 'iframe' ? 'iframe 覆盖地址' : 'JS 跳转目标地址';
        }
    } else {
        urlWrap.classList.remove('visible');
    }
}

