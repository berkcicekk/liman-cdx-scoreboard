#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require __DIR__.'/lib/LimanApi.php';
use LockedShields\{Database,Input,Policy,Scoreboard};
use LockedShields\Deployment\LimanApi;
if(PHP_SAPI!=='cli')exit(1);

function lsSmokeUuid(string $seed): string {
    $h=hash('sha256',$seed);
    return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);
}
function lsSmokeNear(float $a,float $b,string $label,float $epsilon=0.02): void {
    if(abs($a-$b)>$epsilon)throw new RuntimeException($label.' mismatch: expected '.round($b,4).', received '.$a);
}
function lsSmokeTime(string $at): float { return (float)(new DateTimeImmutable($at))->format('U.u'); }

$pdo=null;$lockScope=null;
try {
    $path=$argv[1]??'/etc/liman/lockedshields-deploy.php';
    if(!is_readable($path))throw new RuntimeException('Smoke configuration is not readable.');
    $config=require $path;$smoke=$config['smoke'];$scope=Policy::uuid($smoke['server_id']);
    $timeout=Input::integer($smoke['timeout_seconds']??60,5,300);
    $api=new LimanApi($config['api']);
    $extension=$api->findExtension();
    if(!$extension)throw new RuntimeException('Cenk is not registered in Liman; run deploy.sh first.');
    $extensionId=Policy::uuid($extension['id']);
    if(isset($config['api']['extension_id']) && $config['api']['extension_id']!==$extensionId)throw new RuntimeException('Extension UUID mismatch.');
    // Verify White Team's actual authenticated context before touching smoke fixtures.
    $api->engine('getScoreboardData',$scope,$extensionId);
    $pdo=Database::connect($config);$board=new Scoreboard($pdo);
    $lockScope='lockedshields-smoke:'.$scope;
    if(!Database::query($pdo,'SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS ok',[$lockScope])[0]['ok'])throw new RuntimeException('A smoke test is already running for this scope.');
    $pdo->beginTransaction();$teams=[];$services=[];
    foreach(['Alpha'=>'alpha','Bravo'=>'bravo'] as $label=>$key){
        $ip=Policy::target($smoke[$key.'_ip'],$config['scopes'][$scope]??[]);
        $existing=Database::query($pdo,'SELECT id,name FROM ls_teams WHERE server_id=? AND name=?',[$scope,'Team '.$label]);
        $new=!$existing;
        $team=$new?$board->createTeam($scope,['name'=>'Team '.$label,'color'=>$key==='alpha'?'#36a3ff':'#aa94ff']):$existing[0];
        $definitions=[['HTTP',80],['HTTPS',443],['SSH',22],['PING',1]];
        $existingServices=Database::query($pdo,'SELECT *,host(host) AS host FROM ls_services WHERE team_id=? AND server_id=? ORDER BY name',[$team['id'],$scope]);
        if(!$new && count($existingServices)!==4)throw new RuntimeException('Team '.$label.' already exists outside this smoke fixture. Use a dedicated scope.');
        foreach($definitions as [$protocol,$port]){
            $options=[];
            if($protocol==='HTTPS' && !empty($smoke[$key.'_https_hostname']))$options['hostname']=$smoke[$key.'_https_hostname'];
            $data=['team_id'=>$team['id'],'name'=>'[smoke] '.$protocol,'protocol'=>$protocol,'host'=>$ip,'port'=>$port,'weight'=>100,'timeout_ms'=>3000,'interval_seconds'=>10,'options'=>json_encode((object)$options)];
            $validated=Input::service($data,$config['scopes'][$scope]);
            if($new){$service=$board->createService($scope,$data,$config);}
            else {
                $matches=array_values(array_filter($existingServices,static function($s)use($data){return $s['name']===$data['name'];}));
                if(count($matches)!==1)throw new RuntimeException('Existing team is not a compatible smoke fixture.');
                $service=$matches[0];
                foreach(['host','protocol','port','weight','timeout_ms','interval_seconds'] as $field){
                    if((string)$service[$field] !== (string)$validated[$field] && (!is_numeric($service[$field]) || (float)$service[$field] !== (float)$validated[$field]))throw new RuntimeException('Existing smoke service differs: '.$field.'. Do not overwrite exercise data.');
                }
                if(json_decode($service['options'],true)!==$options || $service['ends_at']!==null)throw new RuntimeException('Existing smoke service options/lifetime differs.');
            }
            $services[]=['id'=>$service['id'],'team_id'=>$team['id'],'protocol'=>$protocol];
        }
        $teams[$key]=$team;
    }
    $pdo->commit();
    fwrite(STDERR,"Seed OK: Team Alpha, Team Bravo; HTTP:80, HTTPS:443, SSH:22, ICMP for each.\n");
    $baselines=[];
    foreach($services as $service){
        $baselines[$service['id']]=(int)Database::query($pdo,'SELECT COALESCE(max(id),0) AS id FROM ls_service_logs WHERE server_id=? AND service_id=?',[$scope,$service['id']])[0]['id'];
        // Preserve any current lease. A running daemon sees newly due work on its next pass.
        Database::query($pdo,'UPDATE ls_services SET next_probe_at=clock_timestamp() WHERE server_id=? AND id=? RETURNING id',[$scope,$service['id']]);
    }
    $args=[PHP_BINARY,__DIR__.'/probe_worker.php','--once'];
    if(function_exists('posix_geteuid') && posix_geteuid()===0)$args=array_merge(['/usr/sbin/runuser','-u','liman','--'],$args);
    $environment=array_merge(getenv(),['LS_CONFIG_PATH'=>getenv('LS_RUNTIME_CONFIG')?:'/etc/liman/locked-shields.php']);
    $process=proc_open($args,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,$environment);
    if(!is_resource($process))throw new RuntimeException('Cannot trigger worker.');
    // A held flock means the background worker is already processing due services.
    $started=microtime(true);$completed=false;
    do {
        $state=proc_get_status($process);
        if(!$state['running']){$code=$state['exitcode'];proc_close($process);$completed=true;break;}
        usleep(50000);
    }while(microtime(true)-$started<10);
    if(!$completed){proc_terminate($process,9);proc_close($process);throw new RuntimeException('Worker trigger exceeded deadline.');}
    if(!in_array($code,[0,75],true))throw new RuntimeException('Worker failed to start (exit '.$code.'). Check runtime config and lock permissions.');
    $deadline=microtime(true)+$timeout;$fresh=[];
    do {
        $fresh=[];
        foreach($services as $service){
            $rows=Database::query($pdo,'SELECT id,is_up FROM ls_service_logs WHERE server_id=? AND service_id=? AND id>? ORDER BY id DESC LIMIT 1',[$scope,$service['id'],$baselines[$service['id']]]);
            if($rows)$fresh[$service['id']]=$rows[0];
        }
        if(count($fresh)===8)break;
        usleep(250000);
    }while(microtime(true)<$deadline);
    if(count($fresh)!==8)throw new RuntimeException('Fresh probe logs missing: '.count($fresh).'/8. Check the systemd worker.');
    fwrite(STDERR,"Probe OK: 8/8 services wrote new ls_service_logs rows.\n");

    $expectedInjects=[];
    foreach(['alpha'=>['task',200],'bravo'=>['penalty',100]] as $key=>[$kind,$points]){
        $request=lsSmokeUuid('lockedshields-smoke-v1:'.$scope.':'.$key);
        $payload=['team_id'=>$teams[$key]['id'],'kind'=>$kind,'points'=>$points,'reason'=>'LockedShields smoke v1: '.$key,'request_id'=>$request];
        // This is the same panel endpoint used by Blade's White Team form.
        $inject=$api->engine('ls_add_inject',$scope,$extensionId,$payload);
        $again=$api->engine('ls_add_inject',$scope,$extensionId,$payload);
        if(($inject['id']??null)!==($again['id']??null))throw new RuntimeException('Panel API inject retry created a duplicate.');
        $rows=Database::query($pdo,'SELECT * FROM ls_injects WHERE server_id=? AND request_id=?',[$scope,$request]);
        if(count($rows)!==1 || $rows[0]['created_by']!==($inject['created_by']??null))throw new RuntimeException('Inject audit row missing or actor mismatch.');
        lsSmokeNear((float)$rows[0]['points'],$kind==='task'?$points:-$points,'Panel inject points',0.001);
        $expectedInjects[$teams[$key]['id']]=$kind==='task'?$points:-$points;
    }
    fwrite(STDERR,"Panel API OK: +200 task / -100 penalty; duplicate retries remain single entries.\n");
    $json=$api->engine('getScoreboardData',$scope,$extensionId);
    $at=lsSmokeTime($json['generated_at']);$report=[];$slaTotals=[];
    foreach($services as $fixture){
        $matches=array_values(array_filter($json['services']??[],static function($s)use($fixture){return (string)$s['id']===(string)$fixture['id'];}));
        if(count($matches)!==1)throw new RuntimeException('Service missing in getScoreboardData JSON.');
        $service=$matches[0];$begin=lsSmokeTime($service['starts_at']);
        $end=min($at,$service['ends_at']?lsSmokeTime($service['ends_at']):$at);$elapsed=max(0,$end-$begin);$up=0;$down=0;
        $logs=Database::query($pdo,'SELECT checked_at,is_up FROM ls_service_logs WHERE server_id=? AND service_id=? AND checked_at<=CAST(? AS timestamptz) ORDER BY checked_at',[$scope,$service['id'],$json['generated_at']]);
        foreach($logs as $i=>$log){
            $left=max($begin,lsSmokeTime($log['checked_at']));
            $right=min($end,lsSmokeTime($log['checked_at'])+(int)$service['interval_seconds'],isset($logs[$i+1])?lsSmokeTime($logs[$i+1]['checked_at']):$end);
            $seconds=max(0,$right-$left);
            if($log['is_up'])$up+=$seconds;else $down+=$seconds;
        }
        $percent=$elapsed>0?100*$up/$elapsed:0;
        lsSmokeNear((float)$service['sla_percent'],round($percent,2),'SLA percentage');
        lsSmokeNear((float)$service['uptime_seconds'],$up,'Uptime seconds');
        lsSmokeNear((float)$service['downtime_seconds'],$down,'Downtime seconds');
        lsSmokeNear((float)$service['unknown_seconds'],max(0,$elapsed-$up-$down),'Unknown seconds');
        $last=$logs?end($logs):null;
        $status=$at<$begin?'pending':($service['ends_at'] && $at>=$end?'ended':(!$last?'unknown':(lsSmokeTime($last['checked_at'])+$service['interval_seconds']<$at?'stale':($last['is_up']?'up':'down'))));
        if($service['status']!==$status)throw new RuntimeException('Matrix status disagrees with raw probe history.');
        $slaTotals[$service['team_id']]=($slaTotals[$service['team_id']]??0)+(float)$service['weight']*$percent/100;
        $report[]=['team_id'=>$service['team_id'],'protocol'=>$service['protocol'],'status'=>$status,'sla_percent'=>round($percent,2),'fresh_log_id'=>$fresh[$service['id']]['id']];
    }
    $teamReports=[];
    foreach($teams as $team){
        $actual=array_values(array_filter($json['teams']??[],static function($t)use($team){return (string)$t['id']===(string)$team['id'];}))[0]??null;
        if(!$actual)throw new RuntimeException('Team missing from scoreboard JSON.');
        $injectSum=(float)Database::query($pdo,'SELECT COALESCE(sum(points),0) AS points FROM ls_injects WHERE server_id=? AND team_id=? AND created_at<=CAST(? AS timestamptz)',[$scope,$team['id'],$json['generated_at']])[0]['points'];
        lsSmokeNear($injectSum,(float)$expectedInjects[$team['id']],'Isolated smoke inject total');
        $sla=round($slaTotals[$team['id']]??0,2);
        lsSmokeNear((float)$actual['sla_score'],$sla,'Weighted SLA score');
        lsSmokeNear((float)$actual['inject_score'],$injectSum,'Inject total');
        lsSmokeNear((float)$actual['score'],round($sla+$injectSum,2),'Total team score');
        $teamReports[]=['name'=>$team['name'],'sla_score'=>$sla,'inject_score'=>$injectSum,'total_score'=>$actual['score']];
    }
    $healthy=count(array_filter($report,static function($r){return $r['status']==='up';}))===8;
    $passed=$healthy || !($smoke['expect_all_up']??true);
    echo json_encode(['result'=>$passed?'PASS':'FAIL','all_targets_up'=>$healthy,'verified'=>['fresh_probe_logs'=>8,'sla_and_matrix'=>true,'panel_injects'=>true,'total_scores'=>true],
        'teams'=>$teamReports,'matrix'=>$report,'getScoreboardData'=>$json],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if(!$passed)throw new RuntimeException('Calculation checks passed, but at least one target is down/stale. See JSON report.');
}catch(Throwable $e){
    if($pdo && $pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,'SMOKE FAIL: '.($e instanceof PDOException?'Database operation failed.':($e instanceof RuntimeException?$e->getMessage():'Invalid smoke configuration or API response.'))."\n");
    exit(1);
}finally{
    if($pdo && $lockScope){Database::query($pdo,'SELECT pg_advisory_unlock(hashtextextended(?,0))',[$lockScope]);}
}
