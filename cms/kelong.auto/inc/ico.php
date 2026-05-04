<?php
require('func.php');
ob_clean();
$cachefile=get_ico();
if(is_file($cachefile)){
	header('Content-type: image/jpeg');
	$shuchuneirong=file_get_contents($cachefile);
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
header('Content-type: image/jpeg');
write($cachefile,$nr);
$fileres = file_get_contents($cachefile);
echo $fileres;
exit;
?>