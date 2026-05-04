<?php
/**
 * 域名后缀列表配置
 * 包含中国省份域名和主流域名后缀
 */

// 双后缀域名列表（需要优先匹配）
$double_suffixes = [
    // 中国省份域名
    '.com.cn', '.net.cn', '.org.cn', '.gov.cn', '.edu.cn',
    '.bj.cn',  // 北京
    '.ac.cn',
    '.sh.cn',  // 上海
    '.tj.cn',  // 天津
    '.cq.cn',  // 重庆
    '.he.cn',  // 河北
    '.sx.cn',  // 山西
    '.nm.cn',  // 内蒙古
    '.ln.cn',  // 辽宁
    '.jl.cn',  // 吉林
    '.hl.cn',  // 黑龙江
    '.js.cn',  // 江苏
    '.zj.cn',  // 浙江
    '.ah.cn',  // 安徽
    '.fj.cn',  // 福建
    '.jx.cn',  // 江西
    '.sd.cn',  // 山东
    '.ha.cn',  // 河南
    '.hb.cn',  // 湖北
    '.hn.cn',  // 湖南
    '.gd.cn',  // 广东
    '.gx.cn',  // 广西
    '.hi.cn',  // 海南
    '.sc.cn',  // 四川
    '.gz.cn',  // 贵州
    '.yn.cn',  // 云南
    '.xz.cn',  // 西藏
    '.sn.cn',  // 陕西
    '.gs.cn',  // 甘肃
    '.qh.cn',  // 青海
    '.nx.cn',  // 宁夏
    '.xj.cn',  // 新疆
    '.tw.cn',  // 台湾
    '.hk.cn',  // 香港
    '.mo.cn',  // 澳门
    // 其他常见双后缀
    '.co.uk', '.co.jp', '.co.kr', '.com.hk', '.com.tw',
    '.net.au', '.com.au', '.org.au',
];

// 单后缀域名列表
$single_suffixes = [
    '.com', '.net', '.org', '.cn', '.edu', '.gov', '.mil',
    '.biz', '.info', '.name', '.mobi', '.pro', '.travel',
    '.asia', '.tel', '.int', '.aero', '.cat', '.coop',
    '.jobs', '.museum', '.post', '.xxx',
    // 新顶级域名
    '.top', '.xyz', '.vip', '.club', '.shop', '.wang',
    '.site', '.online', '.tech', '.store', '.fun', '.live',
    '.pub', '.red', '.kim', '.ltd', '.group', '.center',
    '.team', '.today', '.world', '.city', '.company',
    // 国家域名
    '.us', '.uk', '.de', '.fr', '.jp', '.kr', '.au',
    '.ca', '.ru', '.nl', '.it', '.es', '.br', '.in',
    '.mx', '.se', '.no', '.fi', '.dk', '.pl', '.ch',
];

return [
    'double_suffixes' => $double_suffixes,
    'single_suffixes' => $single_suffixes,
];

