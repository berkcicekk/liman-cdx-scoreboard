<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use LockedShields\{Config,Database,Input,Policy,Probe,Scoreboard,Worker};

$checks=0;
$fixturePassword = bin2hex(random_bytes(24));
function check(bool $ok,string $name): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks++; echo "PASS $name\n"; }
function rejects(callable $fn,string $name): void { try { $fn(); } catch (Throwable $e) { check(true,$name); return; } check(false,$name); }
function near(float $actual,float $expected,string $name): void { check(abs($actual-$expected)<0.02,$name.' ('.$actual.')'); }
function command(array $args): void { $p=proc_open($args,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes); if(proc_close($p)!==0) throw new RuntimeException('Fixture command failed: '.$args[0]); }
$config=Config::load();$pdo=Database::connect($config);$board=new Scoreboard($pdo);
$scope='11111111-1111-4111-8111-111111111111';$other='22222222-2222-4222-8222-222222222222';$actor='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$pdo->exec(file_get_contents(dirname(__DIR__).'/database/migrations/001_locked_shields.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__).'/database/migrations/001_locked_shields.sql'));
check(true,'Migration is repeatable on PostgreSQL 15');
$pdo->exec('TRUNCATE ls_injects,ls_service_logs,ls_services,ls_teams RESTART IDENTITY CASCADE');
check(Policy::target('127.0.0.1',['127.0.0.0/8'])==='127.0.0.1','IPv4 CIDR');
check(Policy::target('::1',['::1/128'])==='::1','IPv6 CIDR');
rejects(fn()=>Policy::target('169.254.169.254',['127.0.0.0/8']),'Unlisted target rejected');
rejects(fn()=>Policy::target('127.0.0.1; touch /tmp/pwn',['127.0.0.0/8']),'Shell injection rejected');
rejects(fn()=>Input::service(['protocol'=>'HTTP','team_id'=>1,'name'=>'web','host'=>'127.0.0.1','options'=>'{"path":"//evil.test"}'],['127.0.0.0/8']),'Unsafe path rejected');
rejects(fn()=>Input::service(['protocol'=>'DNS','team_id'=>1,'name'=>'dns','host'=>'127.0.0.1'],['127.0.0.0/8']),'DNS query name mandatory');
rejects(fn()=>Input::integer('1.5',1,100),'Fractional IDs rejected');
$team=$board->createTeam($scope,['name'=>'Blue One']);$foreign=$board->createTeam($other,['name'=>'Other scope']);
rejects(fn()=>$board->createService($scope,['team_id'=>$foreign['id'],'name'=>'web','protocol'=>'HTTP','host'=>'127.0.0.1'],$config),'Cross-server service creation rejected');
$service=$board->createService($scope,['team_id'=>$team['id'],'name'=>'web','protocol'=>'HTTP','host'=>'127.0.0.1','weight'=>100,'interval_seconds'=>10],$config);
$pdo->exec("UPDATE ls_teams SET created_at='2026-01-01T00:00:00Z'; UPDATE ls_services SET starts_at='2026-01-01T00:00:00Z',created_at='2026-01-01T00:00:00Z'");
foreach ([['00:00:00','true'],['00:00:10','false'],['00:00:20','true']] as $i=>$sample) {
    Database::query($pdo,'INSERT INTO ls_service_logs(server_id,service_id,checked_at,is_up,latency_ms,detail,run_token) VALUES (?,?,?,?,0,?,?) RETURNING id',[$scope,$service['id'],'2026-01-01T'.$sample[0].'Z',$sample[1],'fixture','fixture'.$i]);
}
$inject=['team_id'=>$team['id'],'kind'=>'penalty','points'=>5,'reason'=>'Fixture penalty','request_id'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];
$first=$board->addInject($scope,$actor,$inject);$again=$board->addInject($scope,$actor,$inject);
check($first['id']===$again['id'],'Inject retry is idempotent');
rejects(fn()=>$board->addInject($scope,$actor,array_merge($inject,['points'=>6])),'Conflicting retry rejected');
$pdo->exec("UPDATE ls_injects SET created_at='2026-01-01T00:00:25Z'");
$points=$board->trend($scope,'2026-01-01T00:00:30Z');$last=end($points);
near((float)$last['sla_score'],66.67,'20 seconds up / 30 elapsed');
near((float)$last['score'],61.67,'Penalty added once');
$points=$board->trend($scope,'2026-01-01T00:00:20Z');$last=end($points);
near((float)$last['score'],50,'Historical score excludes future sample duration and inject');
$rows=$board->services($scope,'2026-01-01T00:00:40Z');
near((float)$rows[0]['unknown_seconds'],10,'Stale duration remains unknown');
check($rows[0]['status']==='stale','Stale status');
$points=$board->trend($scope,'2026-01-01T00:00:40Z');$last=end($points);
near((float)$last['sla_score'],50,'Worker outage never grants uptime');
$unmeasured=$board->createService($scope,['team_id'=>$team['id'],'name'=>'No data','protocol'=>'PING','host'=>'127.0.0.1','weight'=>300],$config);
$pdo->exec("UPDATE ls_services SET starts_at='2026-01-01T00:00:00Z' WHERE id=".(int)$unmeasured['id']);
$points=$board->trend($scope,'2026-01-01T00:00:40Z');$last=end($points);near((float)$last['sla_score'],50,'Unmeasured service gives zero points');
check(count($board->dashboard($other)['teams'])===1 && count($board->dashboard($other)['services'])===0,'Dashboard scope isolation');
rejects(fn()=>Database::query($pdo,'INSERT INTO ls_injects(server_id,team_id,kind,points,reason,created_by,request_id) VALUES (?,?,?,1,?,?,?) RETURNING id',[$scope,$team['id'],'penalty','invalid',$actor,'cccccccc-cccc-4ccc-8ccc-cccccccccccc']),'Database enforces penalty sign');

// Run real network calls against local HTTP/TLS/SSH/DNS/LDAP/WS-Man/ICMP fixtures.
command(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout','/tmp/ls-fixture.key','-out','/tmp/ls-fixture.crt','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1']);
copy('/tmp/ls-fixture.crt','/usr/local/share/ca-certificates/ls-fixture.crt');command(['update-ca-certificates']);
$fixtures=proc_open(['python3','/app/tests/fixtures.py'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/tmp/ls-fixtures.log','a']],$fixturePipes);
check(trim(fgets($fixturePipes[1]))==='READY','Protocol fixtures ready');
command(['/usr/sbin/slapd','-h','ldap://127.0.0.1:1389/','-u','openldap','-g','openldap']);
usleep(200000);
$probe=new Probe();
$base=['server_id'=>$scope,'host'=>'127.0.0.1','timeout_ms'=>1000,'options'=>[]];
foreach ([['HTTP',18080,[]],['HTTPS',18443,['hostname'=>'localhost']],['SSH',12222,[]],['DNS',15353,['dns_name'=>'example.test']],['DNS',15353,['dns_name'=>'example.test','dns_type'=>'AAAA']],['LDAP',1389,[]],['WINRM',18080,[]],['PING',1,[]]] as [$protocol,$port,$options]) {
    $result=$probe->run(array_merge($base,['protocol'=>$protocol,'port'=>$port,'options'=>$options]));
    check($result['is_up'],$protocol.' positive probe: '.$result['detail']);
}
foreach ([['HTTP',18080,['path'=>'/down']],['HTTP',18080,['contains'=>'missing']],['HTTPS',18443,['hostname'=>'wrong.test']],['SSH',12224,[]],['WINRM',18080,['path'=>'/auth-only']],['DNS',15354,['dns_name'=>'example.test']]] as [$protocol,$port,$options]) {
    $result=$probe->run(array_merge($base,['protocol'=>$protocol,'port'=>$port,'options'=>$options]));
    check(!$result['is_up'],$protocol.' negative probe');
}
$began=microtime(true);$result=$probe->run(array_merge($base,['protocol'=>'HTTP','port'=>18080,'timeout_ms'=>150,'options'=>['path'=>'/slow']]));
check(!$result['is_up'] && microtime(true)-$began<0.8,'HTTP total timeout enforced');
$result=$probe->run(array_merge($base,['protocol'=>'HTTPS','port'=>18443,'credential'=>['auth'=>'basic','username'=>'fixture','password'=>$fixturePassword]]));
check($result['is_up'],'TLS request accepts configured Basic credentials');
$result=$probe->run(array_merge($base,['protocol'=>'HTTP','port'=>18080,'credential'=>['auth'=>'basic','username'=>'fixture','password'=>$fixturePassword]]));
check(!$result['is_up'],'Basic credentials rejected on cleartext HTTP');
$result=$probe->run(array_merge($base,['protocol'=>'LDAP','port'=>1389,'credential'=>['username'=>'fixture','password'=>$fixturePassword]]));
check(!$result['is_up'],'LDAP credentials require TLS');

$pdo->exec('TRUNCATE ls_injects,ls_service_logs,ls_services,ls_teams RESTART IDENTITY CASCADE');
$team=$board->createTeam($scope,['name'=>'Worker tests']);
$s=$board->createService($scope,['team_id'=>$team['id'],'name'=>'Slow SSH','protocol'=>'SSH','host'=>'127.0.0.1','port'=>12223,'timeout_ms'=>150],$config);
$s2=$board->createService($scope,['team_id'=>$team['id'],'name'=>'Healthy web','protocol'=>'HTTP','host'=>'127.0.0.1','port'=>18080],$config);
$workers=[];
for($i=0;$i<2;$i++) { $workers[]=proc_open([PHP_BINARY,'/app/bin/worker.php','--once'],[0=>['file','/dev/null','r'],1=>['file','/tmp/ls-worker-test.log','a'],2=>['file','/tmp/ls-worker-test.log','a']],$p); }
foreach($workers as $worker){ check(proc_close($worker)===0,'Concurrent worker completed'); }
$logs=Database::query($pdo,'SELECT * FROM ls_service_logs ORDER BY service_id');
check(count($logs)===2,'Concurrent workers produce exactly one log per due service');
check(!$logs[0]['is_up'] && (float)$logs[0]['latency_ms']<800,'Child deadline bounds slow SSH');
check($logs[1]['is_up'],'Worker positive measurement');
check((new Worker($pdo,$config))->once()===0,'No duplicate run before interval');
$stale=Database::query($pdo,'SELECT * FROM ls_services WHERE id=?',[$s['id']])[0];$stale['lease_token']='obsolete';
(new Worker($pdo,$config))->record($stale,['is_up'=>true,'latency_ms'=>0,'detail'=>'obsolete']);
check(count(Database::query($pdo,'SELECT id FROM ls_service_logs'))===2,'Obsolete lease cannot write result');
$patchedVulnerability=Database::query($pdo,"INSERT INTO ls_vulnerabilities(team_id,name,check_type,command,points) VALUES (?,'Patched fixture','SSH_AUDIT','patched-fixture',10) RETURNING id",[$team['id']])[0];
$openVulnerability=Database::query($pdo,"INSERT INTO ls_vulnerabilities(team_id,name,check_type,command,points) VALUES (?,'Open fixture','SSH_AUDIT','vulnerable-fixture',10) RETURNING id",[$team['id']])[0];
Database::query($pdo,"INSERT INTO ls_vulnerabilities(team_id,name,check_type,command,points) VALUES (?,'Redaction fixture','SSH_AUDIT','redaction-fixture',10)",[$team['id']]);
check((new Worker($pdo,$config))->once()===3,'Vulnerability worker checks due findings');
$vulnerabilityLogs=Database::query($pdo,'SELECT vulnerability_id,is_patched,exit_code,duration_ms,output FROM ls_vulnerability_logs ORDER BY vulnerability_id');
check(count($vulnerabilityLogs)===3 && $vulnerabilityLogs[0]['is_patched'] && !$vulnerabilityLogs[1]['is_patched'],'Vulnerability history records patched and open outcomes');
check((int)$vulnerabilityLogs[0]['exit_code']===0 && (int)$vulnerabilityLogs[1]['exit_code']!==0 && (float)$vulnerabilityLogs[0]['duration_ms']>=0,'Vulnerability history records exit code and duration');
check($vulnerabilityLogs[2]['output']==='token=[REDACTED]','Vulnerability history redacts command evidence');
check(count($board->vulnerabilityLogs($scope))===3,'Dashboard exposes scoped vulnerability history');
$currentTrend=$board->trend($scope,Database::query($pdo,'SELECT clock_timestamp() AS at')[0]['at']);
$teamTrend=array_values(array_filter($currentTrend,fn($point)=>(int)$point['team_id']===(int)$team['id']));$lastTrend=end($teamTrend);
near((float)$lastTrend['security_score'],10,'Patched vulnerability contributes to historical security score');
near((float)$lastTrend['score'],(float)$lastTrend['sla_score']+(float)$lastTrend['inject_score']+(float)$lastTrend['security_score'],'Trend total includes security score');

// Actual Liman helper contracts (can, user, server, request, respond) at entry points.
$permissions=[];
function can($name){ global $permissions; return in_array($name,$permissions,true); }
function user(){ return (object)['id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']; }
function server(){ return (object)['id'=>'11111111-1111-4111-8111-111111111111']; }
function request($key=null){return ['server_id'=>'22222222-2222-4222-8222-222222222222','name'=>'test'];}
function respond($message,$code=200){return json_encode(['message'=>$message,'status'=>(int)$code]);}
require dirname(__DIR__).'/views/functions.php';
check(json_decode(ls_add_inject(),true)['status']===403,'Unauthorized White Team endpoint denied');
$permissions=['ls_dashboard'];
$response=json_decode(ls_dashboard(),true);check($response['status']===200 && $response['message']['teams'][0]['name']==='Worker tests','Liman server context wins over request server_id');

// Real Blade compiler from the Liman sandbox image.
require '/liman_files/sandbox/php/vendor/autoload.php';
function API($name){return '/engine/'.$name;} function publicPath($name){return '/public/'.$name;}
if (!is_dir('/tmp/ls-blade-cache')) { mkdir('/tmp/ls-blade-cache',0700); }
$blade=new \eftec\bladeone\BladeOne('/app/views','/tmp/ls-blade-cache');
$html=$blade->run('index',['manageTeams'=>true,'manageServices'=>true,'manageInjects'=>true]);
check(strpos($html,'data-dashboard="/engine/ls_dashboard"')!==false && strpos($html,'@if')===false,'Liman Blade template compiles with native helpers');
$html=$blade->run('index',['manageTeams'=>false,'manageServices'=>false,'manageInjects'=>false]);
check(strpos($html,'data-action=')===false,'Viewer sees no White Team forms');

// Run the image's real encrypted PHP Sandbox dispatcher, not just our helper doubles.
$key=bin2hex(random_bytes(24));file_put_contents('/tmp/ls-sandbox-key',$key);
$context=['extension'=>['id'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd'],'server'=>['id'=>$scope],
    'user'=>['id'=>$actor,'status'=>0],'permissions'=>['index','ls_dashboard'],'settings'=>[],
    'requestData'=>['server_id'=>$other],'functionsPath'=>'/app/views/functions.php',
    'publicPath'=>'/public/','navigationRoute'=>'/test/','locale'=>'tr','ajax'=>true,'token'=>bin2hex(random_bytes(24))];
foreach(['ls_dashboard','ls_add_inject','index'] as $function){
    $context['function']=$function;
    $encrypted=\mervick\aesEverywhere\AES256::encrypt(json_encode($context),$key);
    $p=proc_open([PHP_BINARY,'/liman_files/sandbox/php/index.php','/tmp/ls-sandbox-key',$encrypted],
        [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    if($function==='index'){check($exit===0 && strpos($output,'id="ls-scoreboard"')!==false,'Actual encrypted Liman sandbox renders dashboard');}
    else {$response=json_decode($output,true);check($exit===0 && ($response['status']??0)===($function==='ls_dashboard'?200:403),'Actual encrypted sandbox: '.$function);}
}

// Verify deployed SQL grants, including the worker's column-level UPDATE permissions.
$pdo->exec("CREATE ROLE ls_scoreboard; CREATE TABLE core_secret_fixture(secret text)");
$pdo->exec(file_get_contents('/app/database/grants.sql'));
$pdo->exec('SET ROLE ls_scoreboard');
rejects(fn()=>Database::query($pdo,'SELECT * FROM core_secret_fixture'),'Scoreboard role cannot read core tables');
rejects(fn()=>$pdo->exec("UPDATE ls_service_logs SET detail='changed'"),'Probe history is append-only for runtime role');
rejects(fn()=>$pdo->exec('DELETE FROM ls_injects'),'Inject history is append-only for runtime role');
$restrictedTeam=$board->createTeam($scope,['name'=>'Restricted role']);
$board->createService($scope,['team_id'=>$restrictedTeam['id'],'name'=>'Ping','protocol'=>'PING','host'=>'127.0.0.1'],$config);
check((new Worker($pdo,$config))->once()===1,'Worker works under restricted production grants');
$pdo->exec('RESET ROLE');
proc_terminate($fixtures);fclose($fixturePipes[1]);proc_close($fixtures);
echo "\n$checks checks passed.\n";
