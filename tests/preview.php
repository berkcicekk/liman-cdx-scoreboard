<?php
// Isolated test UI, excluded from extension ZIP. Run only on the loopback test port.
declare(strict_types=1);
require '/liman_files/sandbox/php/vendor/autoload.php';
$path = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (strpos($path,'/public/')===0) {
    $file=realpath('/app'.$path);
    if (!$file || strpos($file,'/app/public/')!==0) { http_response_code(404); exit; }
    header('Content-Type: '.(substr($file,-4)==='.css'?'text/css':'application/javascript'));
    readfile($file);exit;
}
if ($path==='/liman-request.js') {
    // Extract the genuine helper from the Liman image. Keep this test tied to its contract.
    $js=file_get_contents('/liman_files/server/resources/assets/js/liman.js');
    $start=strpos($js,'function request(');$end=strpos($js,'function handlerCleanup(',$start);
    header('Content-Type: application/javascript');echo substr($js,$start,$end-$start);exit;
}
function API($name){return '/engine/'.$name;}
function publicPath($name){return '/public/'.$name;}
if(!is_dir('/tmp/ls-preview-blade'))mkdir('/tmp/ls-preview-blade',0700);
$blade=new \eftec\bladeone\BladeOne('/app/views','/tmp/ls-preview-blade');
?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="test-csrf"><meta name="server_id" content="11111111-1111-4111-8111-111111111111"><meta name="extension_id" content="dddddddd-dddd-4ddd-8ddd-dddddddddddd"><title>Cenk Scoreboard test preview</title><style>body{margin:0;background:#101c2c;font-family:Arial,sans-serif}</style></head><body>
<script>
// The Liman shell supplies these globals. They are test doubles only in this preview.
var csrf='test-csrf',customRequestData=[],modalData=[],Swal={fire:function(){},close:function(){}},__=s=>s;
window.$=selector=>({attr:name=>document.querySelector(selector).getAttribute(name)});
function showSwal(){} function message(){}
</script><script src="/liman-request.js"></script>
<?= $blade->run('index',['manageTeams'=>!isset($_GET['viewer']),'manageServices'=>!isset($_GET['viewer']),'manageInjects'=>!isset($_GET['viewer'])]) ?>
</body></html>
