<?php
/**
 * 使用说明模态框组件
 * 为各个功能页面提供详细的使用说明
 */

// 定义各个功能的使用说明
$helpContents = [
    // 消耗池任务
    'task' => [
        'title' => '消耗池任务使用说明',
        'sections' => [
            [
                'title' => '📋 功能概述',
                'content' => '消耗池用于管理大量跳转链接，每个链接只会被使用一次。适合用于消耗式的跳转需求，如广告落地页、推广链接等。'
            ],
            [
                'title' => '➕ 添加链接',
                'items' => [
                    '✅ <strong>必须包含完整协议头</strong>：http:// 或 https://',
                    '✅ 示例：<code>https://example.com/page.html</code>',
                    '❌ 错误：<code>example.com/page.html</code>（缺少协议头）',
                    '✅ 支持批量导入：每行一个链接',
                    '✅ 支持占位符：<code>https://example.com/{数字8}.html</code>',
                    '⚠️ 导入时如果链接已存在，可选择跳过或覆盖'
                ]
            ],
            [
                'title' => '🎲 全局概率',
                'items' => [
                    '设置跳转触发的概率（0-100%）',
                    '示例：设置为 50%，则只有一半的访问会触发跳转',
                    '💡 可用于控制跳转频率，避免过于频繁',
                    '⚠️ 概率为 0% 时，任务不会执行任何跳转'
                ]
            ],
            [
                'title' => '⚡ 速度控制',
                'items' => [
                    '设置每秒最多消耗多少条链接',
                    '示例：设置为 10，则每秒最多跳转10次',
                    '💡 可用于控制链接消耗速度',
                    '⚠️ 设置为 0 表示不限速'
                ]
            ],
            [
                'title' => '📊 任务状态',
                'items' => [
                    '<strong>启用/禁用</strong>：控制任务是否执行跳转',
                    '<strong>总链接数</strong>：导入的链接总数',
                    '<strong>可用链接</strong>：还未被使用的链接数',
                    '<strong>跳转次数</strong>：已执行的跳转次数',
                    '⚠️ 当可用链接为0时，任务会自动完成'
                ]
            ],
            [
                'title' => '🔄 重置任务',
                'items' => [
                    '可以将所有已使用的链接重置为可用状态',
                    '💡 适合需要重复使用链接的场景',
                    '✅ 支持大批量链接（8000+）的重置',
                    '⚠️ 重置过程采用分批处理，请耐心等待'
                ]
            ],
            [
                'title' => '🔧 修复统计',
                'items' => [
                    '如果发现统计数据不准确，可点击"修复统计"按钮',
                    '系统会重新计算所有链接的统计数据',
                    '⚠️ 修复过程可能需要几秒钟，请耐心等待'
                ]
            ]
        ]
    ],
    
    // 大站池任务
    'bigsite_task' => [
        'title' => '大站池任务使用说明',
        'sections' => [
            [
                'title' => '📋 功能概述',
                'content' => '大站池用于将指定的源域名/URL跳转到大站URL池中的随机链接。适合用于权重传递、流量导流等场景。'
            ],
            [
                'title' => '➕ 添加跳转规则',
                'items' => [
                    '<strong>源域名/URL</strong>：',
                    '✅ 支持纯域名：<code>example.com</code>（不需要协议头）',
                    '✅ 支持完整URL：<code>http://example.com/page.html</code>',
                    '✅ 支持占位符域名：<code>{param}.example.com</code>',
                    '✅ 支持批量添加：每行一个',
                    '⚠️ 如果规则已存在，可选择跳过或覆盖'
                ]
            ],
            [
                'title' => '🎯 添加大站URL池',
                'items' => [
                    '✅ <strong>必须包含完整协议头</strong>：http:// 或 https://',
                    '✅ 示例：<code>https://baidu.com</code>',
                    '❌ 错误：<code>baidu.com</code>（缺少协议头）',
                    '✅ 支持完整URL：<code>https://baidu.com/s?wd=test</code>',
                    '✅ 支持占位符：<code>https://baidu.com/{数字8}</code>',
                    '✅ 支持批量添加：每行一个URL',
                    '💡 系统会随机从URL池中选择一个进行跳转'
                ]
            ],
            [
                'title' => '📊 URL类型',
                'items' => [
                    '<strong>纯域名</strong>：只包含域名，如 <code>https://baidu.com</code>',
                    '<strong>完整URL</strong>：包含路径和参数，如 <code>https://baidu.com/s?wd=test</code>',
                    '💡 在表格中会显示URL类型标签'
                ]
            ],
            [
                'title' => '🔄 跳转逻辑',
                'items' => [
                    '1. 访客访问源域名/URL',
                    '2. 系统从大站URL池中随机选择一个URL',
                    '3. 如果URL包含占位符，会自动替换',
                    '4. 执行301跳转到选中的大站URL',
                    '💡 每次跳转都会随机选择，实现流量分散'
                ]
            ],
            [
                'title' => '⚙️ 任务管理',
                'items' => [
                    '<strong>启用/禁用</strong>：控制任务是否执行跳转',
                    '<strong>清空规则</strong>：删除所有跳转规则',
                    '<strong>清空URL</strong>：删除所有大站URL',
                    '⚠️ 清空操作不可恢复，请谨慎操作'
                ]
            ]
        ]
    ],
    
    // 整站重定向
    'sitewide_task' => [
        'title' => '整站重定向使用说明',
        'sections' => [
            [
                'title' => '📋 功能概述',
                'content' => '整站重定向用于将整个网站的流量重定向到目标域名。支持跟随二级域名、URI路径、URI替换规则和备用URL池等高级功能。'
            ],
            [
                'title' => '🌐 源域名配置',
                'items' => [
                    '✅ 只需填写纯域名：<code>example.com</code>',
                    '❌ 不要包含协议头：<del><code>http://example.com</code></del>',
                    '✅ 支持占位符：<code>{param}.example.com</code>',
                    '✅ 支持批量添加：每行一个域名',
                    '💡 系统会自动匹配所有二级域名'
                ]
            ],
            [
                'title' => '🎯 目标域名配置',
                'items' => [
                    '<strong>纯域名模式</strong>：',
                    '✅ 只填写域名：<code>target.com</code>',
                    '❌ 不要包含协议头：<del><code>http://target.com</code></del>',
                    '',
                    '<strong>完整URL模式</strong>：',
                    '✅ 包含协议头：<code>https://target.com/page.html</code>',
                    '✅ 支持占位符：<code>https://target.com/{数字8}.html</code>',
                    '💡 使用完整URL时，会覆盖"跟随二级域名"和"URI跳转模式"设置',
                    '',
                    '✅ 支持批量添加：每行一个',
                    '💡 系统会随机选择一个目标域名进行跳转'
                ]
            ],
            [
                'title' => '🔀 跟随二级域名',
                'items' => [
                    '✅ 开启后，二级域名会保持一致',
                    '示例：<code>abc.example.com</code> → <code>abc.target.com</code>',
                    '❌ 关闭后，只跳转到主域名',
                    '示例：<code>abc.example.com</code> → <code>target.com</code>',
                    '⚠️ 如果目标域名使用完整URL，此选项无效'
                ]
            ],
            [
                'title' => '📍 URI跳转模式',
                'items' => [
                    '<strong>基础功能</strong>：',
                    '✅ 开启后，保持原始URI路径',
                    '示例：<code>example.com/page.html</code> → <code>target.com/page.html</code>',
                    '❌ 关闭后，只跳转到根路径',
                    '示例：<code>example.com/page.html</code> → <code>target.com</code>',
                    '',
                    '<strong>URI替换规则</strong>：',
                    '💡 可以设置特定URI的跳转规则',
                    '格式：<code>源URI</code> → <code>目标URI</code>',
                    '示例：<code>/old/page.html</code> → <code>/new/page.html</code>',
                    '✅ 支持多条替换规则，每行一对',
                    '✅ 支持正则表达式匹配',
                    '⚠️ 如果URI匹配替换规则，优先使用替换规则',
                    '',
                    '<strong>备用URL池</strong>：',
                    '💡 当URI不匹配任何替换规则时，从备用URL池随机选择',
                    '✅ 必须包含完整协议头：<code>https://target.com/page.html</code>',
                    '✅ 支持占位符：<code>https://target.com/{数字8}.html</code>',
                    '✅ 支持批量导入：每行一个URL',
                    '🔒 <strong>固定映射</strong>：相同源URL会固定跳转到相同目标URL',
                    '💡 可以点击"清空映射关系"重新随机分配'
                ]
            ],
            [
                'title' => '📊 URL类型说明',
                'items' => [
                    '<strong>静态URL</strong>：不包含占位符，每次跳转相同',
                    '示例：<code>https://target.com/page.html</code>',
                    '',
                    '<strong>动态URL</strong>：包含占位符，每次跳转不同',
                    '示例：<code>https://target.com/{数字8}.html</code>',
                    '💡 动态URL适合需要每次生成不同链接的场景'
                ]
            ],
            [
                'title' => '🔄 跳转优先级',
                'items' => [
                    '1. <strong>URI替换规则</strong>：如果URI匹配替换规则，使用替换后的URI',
                    '2. <strong>备用URL池</strong>：如果没有匹配的替换规则，从URL池随机选择',
                    '3. <strong>默认跳转</strong>：如果没有URL池，使用目标域名+原始URI',
                    '💡 这个优先级确保了最大的灵活性'
                ]
            ],
            [
                'title' => '🔀 状态码设置',
                'items' => [
                    '<strong>301 永久重定向</strong>：',
                    '• 搜索引擎会更新索引',
                    '• 权重会传递到目标页面',
                    '• 适合永久性的URL变更',
                    '',
                    '<strong>302 临时重定向</strong>：',
                    '• 搜索引擎保留原URL索引',
                    '• 权重不传递到目标页面',
                    '• 适合临时性的跳转'
                ]
            ],
            [
                'title' => '⚙️ 任务管理',
                'items' => [
                    '<strong>启用/禁用</strong>：控制任务是否执行跳转',
                    '<strong>清空源域名</strong>：删除所有源域名',
                    '<strong>清空目标域名</strong>：删除所有目标域名',
                    '<strong>清空URL池</strong>：删除所有备用URL',
                    '<strong>清空映射关系</strong>：重置URL固定映射',
                    '⚠️ 清空操作不可恢复，请谨慎操作',
                    '💡 新创建的任务默认为禁用状态，需手动启用'
                ]
            ]
        ]
    ],
    
    // 寄生重定向
    'parasite_task' => [
        'title' => '寄生重定向使用说明',
        'sections' => [
            [
                'title' => '📋 功能概述',
                'content' => '寄生重定向用于将特定目录或域名的流量重定向到目标URL。支持按目录匹配和按域名匹配两种模式，以及集权和分权两种跳转模式。'
            ],
            [
                'title' => '📁 按目录匹配',
                'items' => [
                    '<strong>目录路径</strong>：',
                    '✅ 格式：<code>/directory/</code>',
                    '✅ 示例：<code>/blog/</code>、<code>/news/</code>',
                    '⚠️ 必须以斜杠开头和结尾',
                    '💡 匹配该目录下的所有页面',
                    '',
                    '<strong>目标URL</strong>：',
                    '✅ <strong>必须包含完整协议头</strong>：<code>https://target.com</code>',
                    '✅ 支持占位符：<code>https://target.com/{数字8}.html</code>',
                    '❌ 错误：<code>target.com</code>（缺少协议头）'
                ]
            ],
            [
                'title' => '🌐 按域名匹配',
                'items' => [
                    '<strong>域名</strong>：',
                    '✅ 只填写纯域名：<code>example.com</code>',
                    '❌ 不要包含协议头：<del><code>http://example.com</code></del>',
                    '✅ 支持占位符：<code>{param}.example.com</code>',
                    '',
                    '<strong>目标URL</strong>：',
                    '✅ <strong>必须包含完整协议头</strong>：<code>https://target.com</code>',
                    '✅ 支持占位符：<code>https://target.com/{数字8}.html</code>'
                ]
            ],
            [
                'title' => '🔄 跳转模式',
                'items' => [
                    '<strong>集权模式</strong>：',
                    '• 所有流量都跳转到同一个目标URL',
                    '• 适合权重集中传递',
                    '示例：<code>/blog/page1.html</code> → <code>https://target.com</code>',
                    '示例：<code>/blog/page2.html</code> → <code>https://target.com</code>',
                    '',
                    '<strong>分权模式</strong>：',
                    '• 保留原始目录结构',
                    '• 适合内容对应跳转',
                    '示例：<code>/blog/page1.html</code> → <code>https://target.com/blog/page1.html</code>',
                    '示例：<code>/blog/page2.html</code> → <code>https://target.com/blog/page2.html</code>'
                ]
            ],
            [
                'title' => '📍 URI替换规则',
                'items' => [
                    '💡 可以设置特定URI的替换规则',
                    '格式：<code>源URI</code> → <code>目标URI</code>',
                    '示例：<code>aaa</code> → <code>bbb</code>',
                    '示例：<code>111</code> → <code>222</code>',
                    '✅ 支持多条替换规则，每行一对',
                    '✅ 会依次应用所有替换规则',
                    '💡 适合批量修改URL中的特定字符串'
                ]
            ],
            [
                'title' => '🎯 匹配优先级',
                'items' => [
                    '1. <strong>按目录匹配</strong>：优先检查目录规则',
                    '2. <strong>按域名匹配</strong>：如果目录不匹配，检查域名规则',
                    '💡 更精确的规则会优先匹配'
                ]
            ],
            [
                'title' => '🔀 状态码设置',
                'items' => [
                    '<strong>301 永久重定向</strong>：',
                    '• 搜索引擎会更新索引',
                    '• 权重会传递到目标页面',
                    '',
                    '<strong>302 临时重定向</strong>：',
                    '• 搜索引擎保留原URL索引',
                    '• 权重不传递到目标页面'
                ]
            ],
            [
                'title' => '⚙️ 任务管理',
                'items' => [
                    '<strong>启用/禁用</strong>：控制任务是否执行跳转',
                    '<strong>编辑规则</strong>：修改目录、域名或目标URL',
                    '<strong>删除规则</strong>：删除单条规则',
                    '💡 可以创建多个任务，每个任务有独立的规则',
                    '💡 新创建的任务默认为禁用状态，需手动启用'
                ]
            ]
        ]
    ],
    
    // 站群链轮
    'group_task' => [
        'title' => '站群链轮使用说明',
        'sections' => [
            [
                'title' => '📋 功能概述',
                'content' => '站群链轮用于在多个站点之间建立跳转链，实现权重传递和流量循环。支持顺序链轮和随机链轮两种模式，适合站群SEO优化。'
            ],
            [
                'title' => '🔗 添加站点',
                'items' => [
                    '✅ 只需填写纯域名：<code>site1.com</code>',
                    '❌ 不要包含协议头：<del><code>http://site1.com</code></del>',
                    '✅ 支持批量添加：每行一个域名',
                    '💡 至少需要2个站点才能形成链轮',
                    '💡 建议3-10个站点，太多会影响效果'
                ]
            ],
            [
                'title' => '🔄 链轮模式',
                'items' => [
                    '<strong>顺序模式（跑火车）</strong>：',
                    '• 站点按照添加顺序依次跳转',
                    '• 站点1 → 站点2 → 站点3 → 站点1',
                    '• 形成固定的循环链条',
                    '💡 适合有明确权重传递路径的场景',
                    '',
                    '<strong>随机模式</strong>：',
                    '• 每次随机选择下一个站点',
                    '• 站点1 → 随机（站点2/3/4...）',
                    '• 跳转路径不固定',
                    '💡 适合需要分散流量的场景',
                    '💡 更难被识别为站群'
                ]
            ],
            [
                'title' => '🎯 跳转设置',
                'items' => [
                    '<strong>跟随二级域名</strong>：',
                    '✅ 开启：<code>abc.site1.com</code> → <code>abc.site2.com</code>',
                    '❌ 关闭：<code>abc.site1.com</code> → <code>site2.com</code>',
                    '',
                    '<strong>跟随URI</strong>：',
                    '✅ 开启：<code>site1.com/page.html</code> → <code>site2.com/page.html</code>',
                    '❌ 关闭：<code>site1.com/page.html</code> → <code>site2.com</code>',
                    '',
                    '<strong>固定目标</strong>：',
                    '💡 可以设置整组跳转到固定的目标域名',
                    '示例：所有站点都跳转到 <code>target.com</code>',
                    '⚠️ 设置固定目标后，链轮逻辑不生效'
                ]
            ],
            [
                'title' => '🏷️ 权重关键词',
                'items' => [
                    '💡 可以添加权重二级/内页关键词',
                    '✅ 支持占位符：<code>news{小写字母3}</code>',
                    '✅ 每行一个关键词，系统会随机选择',
                    '',
                    '<strong>组合模式</strong>：',
                    '• <strong>组合二级域名</strong>：关键词拼接到二级域名',
                    '  示例：<code>news.site2.com</code>',
                    '• <strong>组合内页</strong>：关键词拼接到URI路径',
                    '  示例：<code>site2.com/news</code>',
                    '',
                    '⚠️ 单个域名设置固定目标时，不使用权重关键词'
                ]
            ],
            [
                'title' => '🔀 状态码设置',
                'items' => [
                    '<strong>301 永久重定向</strong>：',
                    '• 搜索引擎会更新索引',
                    '• 权重会传递到目标页面',
                    '',
                    '<strong>302 临时重定向</strong>：',
                    '• 搜索引擎保留原URL索引',
                    '• 权重不传递到目标页面'
                ]
            ],
            [
                'title' => '📊 统计信息',
                'items' => [
                    '<strong>站点数量</strong>：链轮中的站点总数',
                    '<strong>跳转次数</strong>：已执行的跳转总数',
                    '💡 可以查看每个站点的跳转详情'
                ]
            ],
            [
                'title' => '⚙️ 分组管理',
                'items' => [
                    '<strong>启用/禁用</strong>：控制整个链轮是否执行跳转',
                    '<strong>编辑分组</strong>：修改站点列表和跳转模式',
                    '<strong>删除分组</strong>：删除整个链轮',
                    '💡 可以创建多个链轮分组，互不干扰',
                    '💡 新创建的任务默认为禁用状态，需手动启用'
                ]
            ],
            [
                'title' => '💡 使用建议',
                'items' => [
                    '1. 站点数量建议3-10个，不宜过多',
                    '2. 站点内容相关性越高越好',
                    '3. 随机模式更隐蔽，不易被识别',
                    '4. 定期检查跳转日志，确保正常工作',
                    '5. 避免与其他跳转规则冲突',
                    '6. 建议开启"跟随URI"以保持用户体验'
                ]
            ]
        ]
    ],
    
    // 克隆重定向
    'clone_redirect' => [
        'title' => '克隆重定向使用说明',
        'sections' => [
            [
                'title' => '📋 功能概述',
                'content' => '克隆重定向用于管理克隆站群的跳转规则。支持三端跳转（@、www、m）和外部目标跳转两种模式，可以精确控制每个二级域名的跳转次数。'
            ],
            [
                'title' => '🔗 添加顶级域名',
                'items' => [
                    '✅ 只需填写纯域名：<code>example.com</code>',
                    '❌ 不要包含协议头：<del><code>http://example.com</code></del>',
                    '✅ 支持批量添加：每行一个域名',
                    '💡 每个顶级域名可以配置独立的跳转规则'
                ]
            ],
            [
                'title' => '🎯 跳转模式',
                'items' => [
                    '<strong>三端跳转</strong>：',
                    '• 在 @、www、m 三个二级域名之间跳转',
                    '• 示例：<code>www.example.com</code> → <code>m.example.com</code>',
                    '• 示例：<code>m.example.com</code> → <code>example.com</code>',
                    '• 示例：<code>example.com</code> → <code>www.example.com</code>',
                    '💡 适合克隆站群内部流量循环',
                    '⚠️ 顶级域名（@）不参与跳转',
                    '',
                    '<strong>外部目标</strong>：',
                    '• 跳转到指定的外部URL',
                    '✅ 必须包含完整协议头：<code>https://target.com</code>',
                    '✅ 支持占位符：<code>https://target.com/{数字8}.html</code>',
                    '💡 适合导流到其他网站'
                ]
            ],
            [
                'title' => '🔢 跳转次数限制',
                'items' => [
                    '💡 可以设置每个二级域名的最大跳转次数',
                    '示例：设置为 5，则每个二级域名最多跳转5次',
                    '✅ 每个二级域名独立计数',
                    '✅ 达到次数限制后，该二级域名不再跳转',
                    '💡 适合控制单个域名的跳转频率',
                    '⚠️ 设置为 0 表示不限制次数'
                ]
            ],
            [
                'title' => '🎲 跳转概率',
                'items' => [
                    '设置跳转触发的概率（0-100%）',
                    '示例：设置为 50%，则只有一半的访问会触发跳转',
                    '💡 可用于控制跳转频率',
                    '⚠️ 概率为 0% 时，任务不会执行任何跳转'
                ]
            ],
            [
                'title' => '🔀 状态码设置',
                'items' => [
                    '<strong>301 永久重定向</strong>：',
                    '• 搜索引擎会更新索引',
                    '• 权重会传递到目标页面',
                    '• 适合永久性的URL变更',
                    '',
                    '<strong>302 临时重定向</strong>：',
                    '• 搜索引擎保留原URL索引',
                    '• 权重不传递到目标页面',
                    '• 适合临时性的跳转'
                ]
            ],
            [
                'title' => '📊 统计信息',
                'items' => [
                    '<strong>总跳转次数</strong>：该站群组的总跳转数',
                    '<strong>活跃域名数</strong>：有跳转记录的域名数量',
                    '<strong>最后跳转时间</strong>：最近一次跳转的时间',
                    '💡 统计数据实时更新'
                ]
            ],
            [
                'title' => '⚙️ 站群组管理',
                'items' => [
                    '<strong>启用/禁用</strong>：控制整个站群组是否执行跳转',
                    '<strong>编辑站群组</strong>：修改域名列表和跳转设置',
                    '<strong>删除站群组</strong>：删除整个站群组',
                    '💡 可以创建多个站群组，互不干扰',
                    '💡 新创建的任务默认为禁用状态，需手动启用'
                ]
            ],
            [
                'title' => '💡 使用建议',
                'items' => [
                    '1. 三端跳转适合克隆站群内部权重传递',
                    '2. 外部目标适合导流到主站或其他网站',
                    '3. 合理设置跳转次数，避免过度跳转',
                    '4. 使用概率控制可以让跳转更自然',
                    '5. 定期检查统计数据，优化跳转策略',
                    '6. 顶级域名（@）不参与跳转是正常行为'
                ]
            ]
        ]
    ],
    
    // 智能集权重定向
    'focus_redirect' => [
        'title' => '智能集权重定向使用说明',
        'sections' => [
            [
                'title' => '📋 功能概述',
                'content' => '智能集权重定向是一个基于网站数据的SEO集权管理工具。它可以从sites.json中读取网站数据，根据域名和关键词智能匹配URL，然后将这些URL的权重集中到指定的目标URL。支持三端统一处理（@/www/m）、定时跳转、链接锁定等高级功能。'
            ],
            [
                'title' => '🎯 核心功能',
                'items' => [
                    '<strong>智能数据清洗</strong>：自动从sites.json提取域名和关键词信息',
                    '<strong>三端统一处理</strong>：自动识别并统一处理@、www、m三个二级域名',
                    '<strong>链接锁定机制</strong>：防止同一URL被多个任务同时操作',
                    '<strong>定时跳转控制</strong>：设置跳转有效期，过期后自动停止',
                    '<strong>固定映射关系</strong>：相同源URL始终跳转到相同目标URL',
                    '<strong>蜘蛛筛选</strong>：精确控制哪些搜索引擎蜘蛛触发跳转'
                ]
            ],
            [
                'title' => '📁 数据源配置',
                'items' => [
                    '<strong>域名列表</strong>：',
                    '✅ 格式：每行一个域名，如 <code>example.com</code>',
                    '✅ 支持顶级域名：<code>example.com</code>',
                    '✅ 支持二级域名：<code>www.example.com</code>、<code>m.example.com</code>',
                    '💡 顶级域名会自动匹配@、www、m三个二级域名',
                    '',
                    '<strong>关键词配置</strong>：',
                    '✅ 格式：每行一个关键词',
                    '✅ 示例：<code>七猫小说</code>、<code>番茄小说</code>',
                    '💡 系统会在sites.json中查找包含这些关键词的URL',
                    '',
                    '<strong>关键词来源</strong>：',
                    '• <strong>从数据中选择关键词</strong>：从sites.json中提取的关键词',
                    '• <strong>从用户输入匹配关键词</strong>：使用上面输入的关键词',
                    '💡 可以同时勾选两个选项'
                ]
            ],
            [
                'title' => '🔍 数据清洗功能',
                'items' => [
                    '<strong>首次清洗</strong>：',
                    '• 点击"清洗并分类网站数据"按钮',
                    '• 系统会读取 <code>../data/sites.json</code>',
                    '• 提取域名、关键词、URL等信息',
                    '• 存储到SQLite数据库（<code>admin/data/focus.db</code>）',
                    '',
                    '<strong>增量更新</strong>：',
                    '• 再次点击清洗按钮',
                    '• 只添加新数据，不更新已存在的历史数据',
                    '• 已锁定的链接不会被修改',
                    '',
                    '<strong>清洗进度</strong>：',
                    '• 实时显示清洗进度',
                    '• 显示处理的域名数量和关键词数量',
                    '• 清洗完成后自动刷新'
                ]
            ],
            [
                'title' => '🎯 集权目标配置',
                'items' => [
                    '<strong>目标URL列表</strong>：',
                    '✅ <strong>必须包含完整协议头</strong>：<code>https://target.com/page.html</code>',
                    '✅ 支持占位符：<code>https://target.com/{数字8}.html</code>',
                    '✅ 支持批量添加：每行一个URL',
                    '❌ 错误：<code>target.com</code>（缺少协议头）',
                    '',
                    '<strong>随机选择逻辑</strong>：',
                    '• 系统会从目标URL列表中随机选择一个',
                    '• 每个源URL第一次跳转时随机选择',
                    '• 后续该源URL始终跳转到相同的目标URL',
                    '💡 这确保了权重传递的稳定性'
                ]
            ],
            [
                'title' => '⏰ 定时跳转间隔',
                'items' => [
                    '<strong>时间设置</strong>：',
                    '• 支持天、小时、分钟三种单位',
                    '• 示例：设置为 7 天',
                    '• 示例：设置为 24 小时',
                    '• 示例：设置为 30 分钟',
                    '',
                    '<strong>跳转逻辑</strong>：',
                    '• 任务创建后，所有匹配的URL开始计时',
                    '• 在设定的时间内，访问会触发跳转',
                    '• 超过设定时间后，不再跳转',
                    '💡 适合临时性的权重传递需求',
                    '⚠️ 设置为 0 表示永不过期'
                ]
            ],
            [
                'title' => '🔒 链接锁定机制',
                'items' => [
                    '<strong>自动锁定</strong>：',
                    '• 点击"保存任务配置"时自动锁定匹配的URL',
                    '• 锁定的URL不能被其他任务操作',
                    '• 防止权重传递冲突',
                    '',
                    '<strong>自动解锁</strong>：',
                    '• 删除任务时自动解锁所有URL',
                    '• 定时跳转过期后，URL仍保持锁定状态',
                    '💡 需要手动删除任务才能解锁',
                    '',
                    '<strong>锁定状态查看</strong>：',
                    '• 任务卡片显示"锁定URL数量"',
                    '• 任务详情页显示"匹配的链接列表"',
                    '• 可以查看、编辑、导出锁定的URL'
                ]
            ],
            [
                'title' => '🔄 三端跳转逻辑',
                'items' => [
                    '<strong>三端识别</strong>：',
                    '• <code>@</code>：顶级域名，如 <code>example.com</code>',
                    '• <code>www</code>：www二级域名，如 <code>www.example.com</code>',
                    '• <code>m</code>：移动端二级域名，如 <code>m.example.com</code>',
                    '',
                    '<strong>统一处理</strong>：',
                    '• 用户输入顶级域名 <code>example.com</code>',
                    '• 系统自动匹配三个二级域名的所有URL',
                    '• 三个二级域名共享同一个定时器',
                    '• 任意一个二级域名跳转后，其他两个也会更新计时',
                    '',
                    '<strong>集权目标处理</strong>：',
                    '💡 如果集权目标URL的域名与当前访问域名相同，不跳转',
                    '示例：访问 <code>example.com/page1.html</code>，目标是 <code>example.com/page2.html</code>',
                    '结果：不跳转（避免站内循环）'
                ]
            ],
            [
                'title' => '🔀 跳转规则配置',
                'items' => [
                    '<strong>跳转类型</strong>：',
                    '• <strong>301 永久重定向</strong>：权重传递到目标页面',
                    '• <strong>302 临时重定向</strong>：权重不传递',
                    '',
                    '<strong>跳转概率</strong>：',
                    '• 设置0-100%的触发概率',
                    '• 示例：50% 表示只有一半的访问触发跳转',
                    '💡 可用于A/B测试或控制跳转频率'
                ]
            ],
            [
                'title' => '📊 统计信息',
                'items' => [
                    '<strong>任务卡片显示</strong>：',
                    '• 锁定URL数量',
                    '• 目标关键词（前3个，带计数）',
                    '• 跳转次数',
                    '• 倒计时（距离过期的时间）',
                    '',
                    '<strong>任务详情显示</strong>：',
                    '• 匹配的链接列表（支持分页）',
                    '• 每个URL的跳转次数',
                    '• 最后跳转时间',
                    '• 支持编辑、删除、导出CSV'
                ]
            ],
            [
                'title' => '🕷️ 蜘蛛筛选',
                'items' => [
                    '可以精确控制哪些搜索引擎蜘蛛触发跳转：',
                    '• <strong>百度PC</strong>：Baiduspider',
                    '• <strong>百度移动</strong>：Baiduspider（移动版）',
                    '• <strong>谷歌</strong>：Googlebot',
                    '• <strong>搜狗</strong>：Sogou Spider',
                    '',
                    '💡 可以单独启用/禁用每个蜘蛛类型',
                    '💡 支持在任务卡片上快速调整'
                ]
            ],
            [
                'title' => '⚙️ 任务管理',
                'items' => [
                    '<strong>创建任务</strong>：',
                    '1. 输入任务名称',
                    '2. 选择蜘蛛筛选',
                    '3. 进入任务详情页配置',
                    '',
                    '<strong>编辑任务</strong>：',
                    '• 修改数据源配置',
                    '• 修改集权目标',
                    '• 调整跳转规则',
                    '• 重新提取链接',
                    '',
                    '<strong>删除任务</strong>：',
                    '• 自动解锁所有URL',
                    '• 清除Redis缓存',
                    '• 删除SQLite记录',
                    '⚠️ 删除操作不可恢复'
                ]
            ],
            [
                'title' => '💡 使用建议',
                'items' => [
                    '1. 首次使用前，先执行"清洗并分类网站数据"',
                    '2. 合理设置定时跳转间隔，避免过度跳转',
                    '3. 使用三端统一处理可以简化配置',
                    '4. 定期检查锁定URL数量，避免资源浪费',
                    '5. 集权目标URL要确保可访问',
                    '6. 不要将集权目标设置为源域名本身',
                    '7. 建议使用301跳转进行权重传递',
                    '8. 可以使用概率控制让跳转更自然'
                ]
            ],
            [
                'title' => '🔌 API对接说明',
                'content' => '如果其他程序需要对接使用智能集权重定向功能，需要创建符合以下格式的JSON文档（sites.json）：'
            ],
            [
                'title' => '📄 sites.json 数据格式',
                'items' => [
                    '<strong>文件位置</strong>：<code>../data/sites.json</code>',
                    '<strong>编码格式</strong>：UTF-8',
                    '<strong>文件格式</strong>：JSON数组',
                    '',
                    '<strong>必需字段</strong>：',
                    '• <code>domain</code>（字符串）：顶级域名，如 <code>"example.com"</code>',
                    '• <code>subdomain_prefix_shared_tdk</code>（数组）：包含关键词信息的数组',
                    '',
                    '<strong>可选字段</strong>：',
                    '• <code>brand_name</code>（字符串）：品牌名称，如 <code>"七猫小说"</code>',
                    '• <code>data_type</code>（字符串）：数据类型，如 <code>"novel"</code>',
                    '• <code>group</code>（字符串）：分组名称，如 <code>"group1"</code>',
                    '',
                    '<strong>subdomain_prefix_shared_tdk 数组格式</strong>：',
                    '每个元素是一个对象，包含：',
                    '• <code>prefix</code>（字符串）：二级域名前缀，如 <code>"www"</code>',
                    '• <code>title</code>（字符串）：页面标题（可选）',
                    '• <code>keywords</code>（字符串）：关键词（可选）',
                    '• <code>description</code>（字符串）：描述（可选）'
                ]
            ],
            [
                'title' => '📝 JSON示例',
                'items' => [
                    '<strong>完整示例</strong>：',
                    '<code>{</code>',
                    '<code>  "domain": "example.com",</code>',
                    '<code>  "brand_name": "七猫小说",</code>',
                    '<code>  "data_type": "novel",</code>',
                    '<code>  "group": "group1",</code>',
                    '<code>  "subdomain_prefix_shared_tdk": [</code>',
                    '<code>    {</code>',
                    '<code>      "prefix": "www",</code>',
                    '<code>      "title": "七猫小说-首页",</code>',
                    '<code>      "keywords": "小说,免费小说,在线阅读",</code>',
                    '<code>      "description": "七猫小说官网"</code>',
                    '<code>    },</code>',
                    '<code>    {</code>',
                    '<code>      "prefix": "m",</code>',
                    '<code>      "title": "七猫小说-移动版"</code>',
                    '<code>    }</code>',
                    '<code>  ]</code>',
                    '<code>}</code>',
                    '',
                    '<strong>最小示例</strong>：',
                    '<code>{</code>',
                    '<code>  "domain": "example.com",</code>',
                    '<code>  "subdomain_prefix_shared_tdk": []</code>',
                    '<code>}</code>'
                ]
            ],
            [
                'title' => '🔄 数据处理流程',
                'items' => [
                    '1. <strong>读取JSON</strong>：系统读取 <code>../data/sites.json</code>',
                    '2. <strong>提取域名</strong>：提取每个对象的 <code>domain</code> 字段',
                    '3. <strong>提取关键词</strong>：',
                    '   • 从 <code>brand_name</code> 提取',
                    '   • 从 <code>subdomain_prefix_shared_tdk</code> 数组的 <code>title</code>、<code>keywords</code> 提取',
                    '4. <strong>生成URL</strong>：',
                    '   • 组合域名和二级域名前缀',
                    '   • 生成完整的URL列表',
                    '5. <strong>存储数据</strong>：',
                    '   • 存储到SQLite数据库（<code>admin/data/focus.db</code>）',
                    '   • 建立域名、关键词、URL的关联关系',
                    '6. <strong>快速查询</strong>：',
                    '   • 用户输入域名和关键词',
                    '   • 系统从SQLite快速查询匹配的URL',
                    '   • 返回结果供用户选择'
                ]
            ],
            [
                'title' => '⚠️ 注意事项',
                'items' => [
                    '1. JSON文件必须是有效的JSON格式',
                    '2. 所有字符串必须使用UTF-8编码',
                    '3. <code>domain</code> 字段必须是有效的域名',
                    '4. <code>subdomain_prefix_shared_tdk</code> 必须是数组（可以为空）',
                    '5. 如果JSON格式错误，清洗过程会失败',
                    '6. 建议定期备份 <code>sites.json</code> 文件',
                    '7. 增量更新时，只会添加新数据，不会修改已存在的数据',
                    '8. 已锁定的URL不会被修改或删除'
                ]
            ]
        ]
    ],
    
    // 占位符管理
    'placeholders' => [
        'title' => '占位符管理使用说明',
        'sections' => [
            [
                'title' => '📋 功能概述',
                'content' => '占位符用于在URL中动态生成随机内容。支持时间、数字、字母、字符等多种类型，以及30个自定义参数。'
            ],
            [
                'title' => '⏰ 时间占位符',
                'items' => [
                    '<code>{年}</code> → 当前年份，如 <code>2025</code>',
                    '<code>{月}</code> → 当前月份，如 <code>12</code>',
                    '<code>{日}</code> → 当前日期，如 <code>30</code>',
                    '示例：<code>https://example.com/{年}/{月}/{日}/page.html</code>',
                    '结果：<code>https://example.com/2025/12/30/page.html</code>'
                ]
            ],
            [
                'title' => '🔢 随机数字',
                'items' => [
                    '<code>{数字N}</code> → N位随机数字',
                    '示例：<code>{数字8}</code> → <code>12345678</code>',
                    '示例：<code>{数字4}</code> → <code>1234</code>',
                    '💡 每次生成不同的随机数字',
                    '💡 适合生成唯一ID、订单号等'
                ]
            ],
            [
                'title' => '🔤 随机字母',
                'items' => [
                    '<code>{小写字母N}</code> → N位小写字母',
                    '示例：<code>{小写字母5}</code> → <code>abcde</code>',
                    '',
                    '<code>{大写字母N}</code> → N位大写字母',
                    '示例：<code>{大写字母5}</code> → <code>ABCDE</code>',
                    '',
                    '<code>{大小写字母N}</code> → N位大小写混合字母',
                    '示例：<code>{大小写字母5}</code> → <code>AbCdE</code>'
                ]
            ],
            [
                'title' => '🎲 随机字符',
                'items' => [
                    '<code>{小写随机字符N}</code> → N位小写字母+数字',
                    '示例：<code>{小写随机字符8}</code> → <code>a1b2c3d4</code>',
                    '',
                    '<code>{大写随机字符N}</code> → N位大写字母+数字',
                    '示例：<code>{大写随机字符8}</code> → <code>A1B2C3D4</code>',
                    '',
                    '<code>{大小写随机字符N}</code> → N位大小写字母+数字',
                    '示例：<code>{大小写随机字符8}</code> → <code>A1b2C3d4</code>'
                ]
            ],
            [
                'title' => '⚙️ 自定义参数',
                'items' => [
                    '支持30个自定义参数：<code>{自定义参数1}</code> ~ <code>{自定义参数30}</code>',
                    '',
                    '<strong>配置方法</strong>：',
                    '1. 在占位符管理页面找到对应参数',
                    '2. 输入多个值，每行一个或用逗号分隔',
                    '3. 保存后，系统会随机选择一个值',
                    '',
                    '<strong>示例</strong>：',
                    '自定义参数1配置：',
                    '<code>apple</code>',
                    '<code>banana</code>',
                    '<code>orange</code>',
                    '',
                    '使用：<code>https://example.com/{自定义参数1}.html</code>',
                    '结果：随机生成 <code>apple.html</code> 或 <code>banana.html</code> 或 <code>orange.html</code>'
                ]
            ],
            [
                'title' => '💡 使用示例',
                'items' => [
                    '<strong>示例1：动态文章URL</strong>',
                    '<code>https://example.com/article/{数字8}.html</code>',
                    '每次生成不同的文章ID',
                    '',
                    '<strong>示例2：时间目录</strong>',
                    '<code>https://example.com/{年}/{月}/{日}/news.html</code>',
                    '按日期组织内容',
                    '',
                    '<strong>示例3：随机参数</strong>',
                    '<code>https://example.com/page.html?id={数字6}&token={小写随机字符8}</code>',
                    '生成带随机参数的URL',
                    '',
                    '<strong>示例4：组合使用</strong>',
                    '<code>https://example.com/{年}{月}{日}-{小写字母5}-{数字4}.html</code>',
                    '结果：<code>20251230-abcde-1234.html</code>'
                ]
            ],
            [
                'title' => '⚠️ 注意事项',
                'items' => [
                    '1. 占位符会在每次跳转时实时替换',
                    '2. 相同占位符在同一次跳转中会生成不同的值',
                    '3. 占位符区分大小写',
                    '4. 自定义参数需要先配置才能使用',
                    '5. 如果自定义参数未配置，占位符会保持原样'
                ]
            ]
        ]
    ]
];

/**
 * 渲染帮助按钮和模态框
 * @param string $page 页面标识
 */
function renderHelpModal($page) {
    global $helpContents;
    
    if (!isset($helpContents[$page])) {
        return;
    }
    
    $help = $helpContents[$page];
    $modalId = 'helpModal_' . $page;
    ?>
    
    <!-- 帮助按钮 -->
    <button type="button" class="btn btn-info btn-sm" onclick="document.getElementById('<?php echo $modalId; ?>').style.display='flex'" style="margin-left: 10px;">
        <i class="fas fa-question-circle"></i> 查看使用说明
    </button>
    
    <!-- 帮助模态框 -->
    <div id="<?php echo $modalId; ?>" class="help-modal" style="display: none;">
        <div class="help-modal-content">
            <div class="help-modal-header">
                <h2><?php echo htmlspecialchars($help['title']); ?></h2>
                <button class="help-modal-close" onclick="document.getElementById('<?php echo $modalId; ?>').style.display='none'">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="help-modal-body">
                <?php foreach ($help['sections'] as $section): ?>
                    <div class="help-section">
                        <h3><?php echo $section['title']; ?></h3>
                        
                        <?php if (isset($section['content'])): ?>
                            <p><?php echo $section['content']; ?></p>
                        <?php endif; ?>
                        
                        <?php if (isset($section['items'])): ?>
                            <ul class="help-list">
                                <?php foreach ($section['items'] as $item): ?>
                                    <li><?php echo $item; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="help-modal-footer">
                <button class="btn btn-secondary" onclick="document.getElementById('<?php echo $modalId; ?>').style.display='none'">
                    关闭
                </button>
            </div>
        </div>
    </div>
    
    <style>
    /* 模态框样式 */
    .help-modal {
        display: none;
        position: fixed;
        z-index: 10000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.6);
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.3s;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    .help-modal-content {
        background-color: var(--bg-card);
        border-radius: 12px;
        width: 90%;
        max-width: 900px;
        max-height: 85vh;
        display: flex;
        flex-direction: column;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        animation: slideIn 0.3s;
    }
    
    @keyframes slideIn {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    .help-modal-header {
        padding: 24px 30px;
        border-bottom: 2px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        color: white;
        border-radius: 12px 12px 0 0;
    }
    
    .help-modal-header h2 {
        margin: 0;
        font-size: 24px;
        font-weight: 600;
    }
    
    .help-modal-close {
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
    }
    
    .help-modal-close:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: rotate(90deg);
    }
    
    .help-modal-body {
        padding: 30px;
        overflow-y: auto;
        flex: 1;
    }
    
    .help-section {
        margin-bottom: 30px;
    }
    
    .help-section:last-child {
        margin-bottom: 0;
    }
    
    .help-section h3 {
        font-size: 18px;
        font-weight: 600;
        color: var(--primary);
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--border);
    }
    
    .help-section p {
        color: var(--text);
        line-height: 1.8;
        margin-bottom: 15px;
    }
    
    .help-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .help-list li {
        padding: 8px 0;
        padding-left: 20px;
        position: relative;
        line-height: 1.8;
        color: var(--text);
    }
    
    .help-list li:before {
        content: '';
        position: absolute;
        left: 0;
        top: 16px;
        width: 6px;
        height: 6px;
        background: var(--primary);
        border-radius: 50%;
    }
    
    .help-list li code {
        background: var(--bg-dark);
        padding: 2px 8px;
        border-radius: 4px;
        font-family: 'Courier New', monospace;
        font-size: 13px;
        color: #e83e8c;
        border: 1px solid var(--border);
    }
    
    .help-list li strong {
        color: var(--primary);
        font-weight: 600;
    }
    
    .help-modal-footer {
        padding: 20px 30px;
        border-top: 1px solid var(--border);
        text-align: right;
        background: var(--bg-dark);
        border-radius: 0 0 12px 12px;
    }
    
    /* 滚动条样式 */
    .help-modal-body::-webkit-scrollbar {
        width: 8px;
    }
    
    .help-modal-body::-webkit-scrollbar-track {
        background: var(--bg-dark);
        border-radius: 4px;
    }
    
    .help-modal-body::-webkit-scrollbar-thumb {
        background: var(--border);
        border-radius: 4px;
    }
    
    .help-modal-body::-webkit-scrollbar-thumb:hover {
        background: var(--primary);
    }
    
    /* 响应式 */
    @media (max-width: 768px) {
        .help-modal-content {
            width: 95%;
            max-height: 90vh;
        }
        
        .help-modal-header {
            padding: 20px;
        }
        
        .help-modal-header h2 {
            font-size: 20px;
        }
        
        .help-modal-body {
            padding: 20px;
        }
        
        .help-section h3 {
            font-size: 16px;
        }
    }
    </style>
    
    <?php
}
?>

