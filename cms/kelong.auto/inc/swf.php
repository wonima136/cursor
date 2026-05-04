<?php
require('func.php');
ob_clean();
$filename=get_swf();
if(is_file($filename)){
	header('Content-type: application/x-shockwave-flash');
	$shuchuneirong=file_get_contents($filename);
	echo $shuchuneirong;
	exit;
}
$url= str_replace(" ","%20", $url);
$url= str_replace($djym,top_domain($mubiao), $url);
$nr=get_content($url);
if($nr==""){
	 header("HTTP/1.1 404 Not Found");
     header("Status: 404 Not Found");
     include(__DIR__ . "/../404.html");
	 exit();
}
header('Content-type:application/x-shockwave-flash');
write($filename,$nr);
$fileres = file_get_contents($filename);
echo $fileres;
exit;
?>