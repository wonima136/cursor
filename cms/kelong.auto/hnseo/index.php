<?php
        header('HTTP/1.1 200 OK');
		header("status: 200 OK");
		header('content-type:text/html;charset=utf-8');
		$canshu='/tongji/'.$_SERVER['QUERY_STRING'].'/';
		$hour_path=str_replace('\\','/',__DIR__).'/tongji/hour/';
		$cache_path = str_replace('\\','/',__DIR__).$canshu;
        $cache_path1 = str_replace('\\','/',__DIR__).'/tongji/Sogou/';
		$cache_path2 = str_replace('\\','/',__DIR__).'/tongji/Baiduspider/';
		$cache_path3 = str_replace('\\','/',__DIR__).'/tongji/360Spider/';
		$cache_path4 = str_replace('\\','/',__DIR__).'/tongji/Googlebot/';
		$cache_path5 = str_replace('\\','/',__DIR__).'/tongji/Yisouspider/';
		$cache_path6 = str_replace('\\','/',__DIR__).'/tongji/Bytespider/';
        $url = $_SERVER['REQUEST_URI'];
        $url= str_replace("/","",$url);
        $key= $_SERVER["HTTP_USER_AGENT"];
        $Sogouspider =preg_match('/Sogou/', $key, $Sogouspider);
        //新程序判断是百度pc蜘蛛还是百度移动蜘蛛
        $useragent = addslashes(strtolower($_SERVER['HTTP_USER_AGENT']));
        if (strpos($useragent,'baiduspider') !== false){
            $bot = 'Baiduspider';
            }else{
                $bot = 'NO Spider';
                
            }
        $baiduspider =preg_match('/Baiduspider/', $bot, $baiduspider);
        $Googlebot =preg_match('/Googlebot/', $key, $Googlebot);
        $bingbot =preg_match('/bingbot/', $key, $bingbot);
        $MJ12bot =preg_match('/MJ12bot/', $key, $MJ12bot);
		$liulingSpider=preg_match('/360Spider/',$key, $liulingSpider);
		$Yisouspider=preg_match('/YisouSpider/',$key, $Yisouspider);
		$Bytespider=preg_match('/Bytespider/', $key, $Bytespider);
		$ip = $_SERVER["REMOTE_ADDR"];
		$urls=$_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
		$zhizhutime=date('Y-m-d H:i:s' );
		$zhizhutimes=date('Ymd');
		$hour=date('H');
		$sogou='搜狗';
		$gouzi="$zhizhutime--$ip--$sogou--$urls\n";
		$useragent = addslashes(strtolower($_SERVER['HTTP_USER_AGENT']));
		if(strpos($useragent,"android") || strpos($useragent,"iphone") || strpos($useragent,"mobile")){
        $baidu='百度移动';
    }else{
        $baidu='百度PC';
    }
		$baidus="$zhizhutime--$ip--$baidu--$urls\n";
		$google='谷歌';
		$googles="$zhizhutime--$ip--$google--$urls\n";
		$liuling='360';
		$liulingSpiders="$zhizhutime--$ip--$liuling--$urls\n";
		$yisou='神马';
		$Yisouspiders="$zhizhutime--$ip--$yisou--$urls\n";
		$toutiao='今日头条';
		$Bytespiders="$zhizhutime--$ip--$toutiao--$urls\n";
		$dir = $hour_path.$zhizhutimes;
        is_dir($dir)?:mkdir($dir,0777,true);
		// 日志记录已禁用，数据存储到SQLite
		// if($Sogouspider)
		// {
		// file_put_contents($cache_path1."$zhizhutimes.log",$gouzi,FILE_APPEND);
		// file_put_contents($hour_path.$zhizhutimes.'/'.$hour.".log",'1',FILE_APPEND);
		// } 
		// if($baiduspider)
		// {
		// file_put_contents($cache_path2."$zhizhutimes.log",$baidus,FILE_APPEND);
		// file_put_contents($hour_path.$zhizhutimes.'/'.$hour.".log",'1',FILE_APPEND);
		// }
		// if($Googlebot)
		// {
		// file_put_contents($cache_path4."$zhizhutimes.log",$googles,FILE_APPEND);
		// file_put_contents($hour_path.$zhizhutimes.'/'.$hour.".log",'1',FILE_APPEND);
		// } 
		// if($liulingSpider)
		// {
		// file_put_contents($cache_path3."$zhizhutimes.log",$liulingSpiders,FILE_APPEND);
		// file_put_contents($hour_path.$zhizhutimes.'/'.$hour.".log",'1',FILE_APPEND);
		// }
		// if($Yisouspider)
		// {
		// file_put_contents($cache_path5."$zhizhutimes.log",$Yisouspiders,FILE_APPEND);
		// file_put_contents($hour_path.$zhizhutimes.'/'.$hour.".log",'1',FILE_APPEND);
		// }
		// if($Bytespider)
		// {
		// file_put_contents($cache_path6."$zhizhutimes.log",$Bytespiders,FILE_APPEND);
		// file_put_contents($hour_path.$zhizhutimes.'/'.$hour.".log",'1',FILE_APPEND);
		// }
?>