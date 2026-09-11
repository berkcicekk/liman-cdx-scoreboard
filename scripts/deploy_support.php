<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require __DIR__.'/lib/LimanApi.php';
use LockedShields\{Database,Policy};
use LockedShields\Deployment\LimanApi;
if(PHP_SAPI!=='cli')exit(1);
try {
    $mode=$argv[1]??'';
    if($mode==='extract'){
        $zip=new ZipArchive();
        if($zip->open($argv[2])!==true)throw new RuntimeException('Cannot read ZIP package.');
        $destination=$argv[3];$total=0;$seen=[];
        for($i=0;$i<$zip->numFiles;$i++){
            $stat=$zip->statIndex($i);$name=$stat['name'];
            $zip->getExternalAttributesIndex($i,$os,$attributes);
            if(!preg_match('~^[A-Za-z0-9_./-]+$~D',$name) || $name[0]==='/' || in_array('..',explode('/',$name),true) ||
                (($attributes>>16)&0170000)===0120000 || isset($seen[$name]))throw new RuntimeException('Unsafe or duplicate ZIP entry.');
            $seen[$name]=true;$total+=$stat['size'];
            if($total>50*1024*1024)throw new RuntimeException('ZIP exceeds 50 MiB uncompressed limit.');
        }
        foreach(['db.json','database/migrations.sql','scripts/probe_worker.php','scripts/smoke_test.php','deploy/lockedshields-probe.service','views/functions.php'] as $file){
            if(!isset($seen[$file]))throw new RuntimeException('Package lacks '.$file.'. Rebuild with scripts/build.ps1.');
        }
        $manifest=json_decode($zip->getFromName('db.json'),true,512,JSON_THROW_ON_ERROR);
        if(($manifest['name']??'')!=='Cenk' || ($manifest['version']??'')!=='1.0.0')throw new RuntimeException('Expected Cenk 1.0.0.');
        if(!$zip->extractTo($destination))throw new RuntimeException('ZIP extraction failed.');
        $zip->close();exit(0);
    }
    $configPath=$argv[2]??'';
    if(!is_file($configPath) || !is_readable($configPath))throw new RuntimeException('Deployment configuration is not readable.');
    $c=require $configPath;
    if(!is_array($c) || ($c['database']['username']??'')!=='liman')throw new RuntimeException('Runtime PostgreSQL username must be liman.');
    if(($c['database']['dsn']??'')!==($c['admin_database']['dsn']??''))throw new RuntimeException('Admin and runtime DSNs must be identical (same Liman database).');
    foreach($c['scopes']??[] as $scope=>$networks){Policy::uuid($scope);if(!$networks)throw new RuntimeException('Empty exercise network list.');}
    $scope=Policy::uuid($c['smoke']['server_id']??'');
    Policy::target($c['smoke']['alpha_ip']??'', $c['scopes'][$scope]??[]);
    Policy::target($c['smoke']['bravo_ip']??'', $c['scopes'][$scope]??[]);
    if($mode==='preflight'){
        foreach(['pdo_pgsql','curl','zip','ldap','dom','mbstring','pcntl','posix'] as $ext){if(!extension_loaded($ext))throw new RuntimeException('Missing PHP extension: '.$ext);}
        if(!function_exists('proc_open'))throw new RuntimeException('proc_open is disabled.');
        if((fileperms($configPath)&0077)!==0)throw new RuntimeException('Deployment config must be mode 0600 (contains migration credentials).');
        $admin=Database::connect(['database'=>$c['admin_database']]);
        $admin->query('SELECT 1');
        (new LimanApi($c['api']))->request('/api/settings/extensions',[],'GET');
        echo "Preflight OK: PostgreSQL and authenticated Liman admin API reachable.\n";
    }elseif($mode==='register'){
        echo (new LimanApi($c['api']))->register($argv[3],$scope,'1.0.0');
    }elseif($mode==='migrate'){
        $admin=Database::connect(['database'=>$c['admin_database']]);
        $role=Database::query($admin,"SELECT rolname FROM pg_roles WHERE rolname='liman'");
        if(!$role){
            if(empty($c['database']['password']))throw new RuntimeException('New liman database role needs a password.');
            $admin->exec('CREATE ROLE liman LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '.$admin->quote($c['database']['password']));
        }
        $databaseName=$admin->query('SELECT current_database()')->fetchColumn();
        $admin->exec('GRANT CONNECT ON DATABASE "'.str_replace('"','""',$databaseName).'" TO liman');
        // PDO supports this actual SQL entrypoint; no psql-only include directives.
        $admin->exec(file_get_contents($argv[3].'/database/migrations.sql'));
        $admin->exec(file_get_contents($argv[3].'/database/grants_liman.sql'));
        $runtime=Database::connect($c);
        foreach(['ls_teams','ls_services','ls_service_logs','ls_injects'] as $table){
            // Check each privilege separately; PostgreSQL comma lists mean ANY, not ALL.
            foreach(['SELECT','INSERT','UPDATE','DELETE','TRUNCATE','REFERENCES','TRIGGER'] as $priv){
                if(!Database::query($runtime,'SELECT has_table_privilege(current_user, ?, ?) AS ok',['public.'.$table,$priv])[0]['ok'])throw new RuntimeException('Missing table grant.');
            }
            foreach(['USAGE','SELECT','UPDATE'] as $priv){
                if(!Database::query($runtime,'SELECT has_sequence_privilege(current_user, ?, ?) AS ok',['public.'.$table.'_id_seq',$priv])[0]['ok'])throw new RuntimeException('Missing sequence grant.');
            }
        }
        echo "Migration and full liman grants verified.\n";
    }elseif($mode==='runtime'){
        $runtime=array_intersect_key($c,array_flip(['database','scopes','parallel_probes','credentials']));
        $target=$argv[3];
        if(file_put_contents($target,"<?php\nreturn ".var_export($runtime,true).";\n")===false)throw new RuntimeException('Cannot write runtime config.');
        chmod($target,0640);
    }elseif($mode==='check-runtime'){
        Database::connect($c)->query('SELECT count(*) FROM ls_services');
        echo "Runtime database connection OK.\n";
    }else{throw new RuntimeException('Unknown deployment helper action.');}
}catch(Throwable $e){
    // Driver exceptions can contain DSNs or passwords: only emit safe application errors.
    fwrite(STDERR,($e instanceof PDOException ? 'Deployment database operation failed; verify credentials/grants.' : ($e instanceof RuntimeException?$e->getMessage():'Deployment configuration or API response invalid.'))."\n");
    exit(1);
}
