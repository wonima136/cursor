<?php
// 蜘蛛文件夹自动创建函数
function createSpiderDirs() {
    $base_path = str_replace('\\', '/', __DIR__) . '/tongji/';
    $spider_dirs = array(
        $base_path . 'hour/',
        $base_path . 'Sogou/',
        $base_path . 'Baiduspider/',
        $base_path . '360Spider/',
        $base_path . 'Googlebot/',
        $base_path . 'Yisouspider/',
        $base_path . 'Bytespider/'
    );
    
    foreach($spider_dirs as $dir) {
        if(!is_dir($dir)) {
            mkdir($dir, 0777, true);
            chmod($dir, 0777);
        }
    }
}
?>
