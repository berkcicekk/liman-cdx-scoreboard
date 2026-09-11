<?php
// Test transport fixture. Native registry endpoints are simulated; /engine/ runs
// the image's actual encrypted Liman PHP Sandbox as liman (container-mode account).
require '/liman_files/sandbox/php/vendor/autoload.php';
header('Content-Type: application/json');
$jwt = getenv('LS_FIXTURE_JWT');
$engineKey = getenv('LS_FIXTURE_ENGINE_KEY');
if (!is_string($jwt) || $jwt === '' || !is_string($engineKey) || $engineKey === '') { http_response_code(500); echo '{}'; exit; }
if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer '.$jwt){http_response_code(401);echo '{}';exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$id='dddddddd-dddd-4ddd-8ddd-dddddddddddd';
if($path==='/api/settings/extensions'){
    echo is_file('/tmp/ls-registry.json')?file_get_contents('/tmp/ls-registry.json'):'[]';exit;
}
if($path==='/api/settings/extensions/upload'){
    $zip=new ZipArchive();$zip->open($_FILES['extension']['tmp_name']);
    @mkdir('/liman/extensions/lockedshields',0755,true);$zip->extractTo('/liman/extensions/lockedshields');$zip->close();
    file_put_contents('/tmp/ls-registry.json',json_encode([['id'=>$id,'name'=>'lockedshields','version'=>'1.0.0']]));
    echo '{"message":"uploaded"}';exit;
}
if(preg_match('~/api/servers/[^/]+/extensions$~',$path)){
    echo is_file('/tmp/ls-assigned')?json_encode([['id'=>$id]]):'[]';exit;
}
if($path==='/api/extensions/assign'){
    if(($_POST['extensions'][0]??'')!==$id){http_response_code(422);echo '{}';exit;}
    file_put_contents('/tmp/ls-assigned','yes');echo '{}';exit;
}
if($path==='/engine/'){
    if(($_POST['token']??'')!==$jwt){http_response_code(401);echo '{}';exit;}
    $context=['extension'=>['id'=>$id],'server'=>['id'=>$_POST['server_id']],
        'user'=>['id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','status'=>0],
        'permissions'=>['index','ls_dashboard','ls_add_inject'],'settings'=>[],
        'requestData'=>$_POST,'functionsPath'=>'/liman/extensions/lockedshields/views/functions.php',
        'publicPath'=>'/public/','navigationRoute'=>'/test/','locale'=>'tr','ajax'=>true,
        'token'=>$jwt,'function'=>$_POST['lmntargetFunction']];
    $encrypted=\mervick\aesEverywhere\AES256::encrypt(json_encode($context),$engineKey);
    $p=proc_open(['/usr/sbin/runuser','-u','liman','--',PHP_BINARY,'/liman_files/sandbox/php/index.php','/tmp/ls-engine.key',$encrypted],
        [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/tmp/ls-engine-errors.log','a']],$pipes,null,array_merge(getenv(),['LS_CONFIG_PATH'=>'/etc/liman/locked-shields.php']));
    echo stream_get_contents($pipes[1]);fclose($pipes[1]);proc_close($p);exit;
}
http_response_code(404);echo '{}';
