<?php
$l_ds = $_POST['l_ds'];
$t_ds = $_POST['t_ds'];
$t_ws = $_POST['t_ws'];


$list_array1=explode("\n",$l_ds);//分割回车并转为数组
$list1=array();
foreach($list_array1 as $array){
   array_push($list1,$array);//获取到数据加到$list数组里
}

$list_array2=explode("\n",$t_ds);//分割回车并转为数组
$list2=array();
foreach($list_array2 as $array){
   array_push($list2,$array);//获取到数据加到$list数组里
}

$list_array3=explode("\n",$t_ws);//分割回车并转为数组
$list3=array();
foreach($list_array3 as $array){
   array_push($list3,$array);//获取到数据加到$list数组里
}

$ldsCount=count($list1);
$tdsCount=count($list2);
$twsCount=count($list3);


if(count($ldsCount)!=count($tdsCount)){
	exit('我们域名的数量和目标站域名数量不一致');
}

if(count($tdsCount)!=count($twsCount)){
	exit('目标站域名数量和待替换词行数不一致');
}

$arrayLds = array();
foreach ($list1 as $key => $value) {
	array_push($arrayLds,$value);
}
$l_ds=implode("\n", $arrayLds);

$fLocalDomains = fopen('./config/localDomains.txt', 'w'); //生成txt文件
fwrite($fLocalDomains, $l_ds);
fclose($fLocalDomains);



$arrTargets=array();
foreach ($list2 as $key=>$value) {
	array_push($arrTargets,$value."###".$list3[$key]);
}
$targets=implode("\n", $arrTargets);

$fTargets = fopen('./config/targets.txt', 'w'); //生成txt文件
fwrite($fTargets, $targets);
fclose($fTargets);

exit("提交成功，请查看镜像情况");
?>