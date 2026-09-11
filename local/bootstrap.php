<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('LS_LOCAL_HARNESS') !== '1' || getenv('DB_HOST') !== 'postgres') {
    fwrite(STDERR, "This bootstrap only runs inside the local harness.\n"); exit(1);
}
const LS_LOCAL_SERVER = 'c048067a-9b65-4b19-a908-daf53bbd9501';
const LS_LOCAL_EXTENSION = 'c048067a-9b65-4b19-a908-daf53bbd9502';
$source = '/liman/server/storage/extensions/lockedshields';
require '/liman/server/vendor/autoload.php';
$app = require '/liman/server/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    // Some Core console exception handlers return status 0; setup must fail closed.
    fwrite(STDERR, 'Local bootstrap failed ('.get_class($error).'): '.$error->getMessage()."\n");
    exit(1);
});

$ip = gethostbyname('mock-target');
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { throw new RuntimeException('mock-target DNS failed.'); }
$pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$scoreboardPassword = getenv('LS_SCOREBOARD_DB_PASSWORD');
if (!is_string($scoreboardPassword) || $scoreboardPassword === '') {
    throw new RuntimeException('LS_SCOREBOARD_DB_PASSWORD is required. Run ./local_run.sh.');
}
// Execute the requested migration from INSIDE the Liman container, against its DB.
$pdo->exec(file_get_contents($source.'/database/migrations.sql'));
if (!$pdo->query("SELECT 1 FROM pg_roles WHERE rolname='ls_scoreboard'")->fetchColumn()) {
    $pdo->exec('CREATE ROLE ls_scoreboard LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE');
}
$pdo->exec('ALTER ROLE ls_scoreboard PASSWORD '.$pdo->quote($scoreboardPassword));
$pdo->exec(file_get_contents($source.'/database/grants.sql'));

$user = App\Models\User::where('email', 'admin@lockedshields.local')->first();
$password = null;
if (!$user) {
    $password = bin2hex(random_bytes(12));
    $user = App\Models\User::withoutEvents(fn () => App\Models\User::create([
        'id' => 'c048067a-9b65-4b19-a908-daf53bbd9504',
        'name' => 'Local Admin', 'email' => 'admin@lockedshields.local', 'username' => 'localadmin',
        'password' => Illuminate\Support\Facades\Hash::make($password),
        'status' => 1, 'forceChange' => false, 'auth_type' => 'local', 'locale' => 'tr',
    ]));
}
if ($password === null && !is_file('/liman/.lockedshields-local-access.txt')) {
    $password = bin2hex(random_bytes(12));
    $user->password = Illuminate\Support\Facades\Hash::make($password);
    App\Models\User::withoutEvents(fn () => $user->save());
}
if ($password !== null) {
    $url = 'http://localhost:8000/servers/'.LS_LOCAL_SERVER.'/extensions/'.LS_LOCAL_EXTENSION;
    file_put_contents('/liman/.lockedshields-local-access.txt',
        "\nLiman: http://localhost:8000\nEmail: admin@lockedshields.local\nPassword: $password\nScoreboard: $url\n");
    chmod('/liman/.lockedshields-local-access.txt', 0600);
}
$server = App\Models\Server::find(LS_LOCAL_SERVER) ?? new App\Models\Server();
$server->id = LS_LOCAL_SERVER;
$server->fill([
    'name' => 'Local Blue Team', 'ip_address' => $ip, 'type' => 'none',
    // Keep the scoreboard accessible while mock-target is stopped for DOWN testing.
    'control_port' => -1, 'key_port' => 22, 'os' => 'linux', 'user_id' => $user->id, 'shared_key' => 0,
]);
App\Models\Server::withoutEvents(fn () => $server->save());
$manifest = json_decode(file_get_contents($source.'/db.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest['display_name'] = json_encode($manifest['display_name'], JSON_UNESCAPED_UNICODE);
// Extension::setNameAttribute deliberately prevents renames; update the local
// registry first so a renamed manifest remains testable across harness runs.
Illuminate\Support\Facades\DB::table('extensions')->where('id', LS_LOCAL_EXTENSION)->update(['name' => $manifest['name']]);
$extension = App\Models\Extension::find(LS_LOCAL_EXTENSION) ?? new App\Models\Extension();
$extension->id = LS_LOCAL_EXTENSION;
$extension->fill($manifest);
$extension->save();
Illuminate\Support\Facades\DB::table('server_extensions')->updateOrInsert(
    ['server_id' => LS_LOCAL_SERVER, 'extension_id' => LS_LOCAL_EXTENSION],
    ['id' => 'c048067a-9b65-4b19-a908-daf53bbd9503']
);

// Official CONTAINER_MODE runs PHP Sandbox as extuser (member of liman group).
if (!posix_getpwnam('extuser')) { throw new RuntimeException('Official extuser account missing.'); }
$keyPath = '/liman/keys/'.LS_LOCAL_EXTENSION;
if (!is_file($keyPath)) { file_put_contents($keyPath, bin2hex(random_bytes(16))); }
chown($keyPath, 'liman'); chgrp($keyPath, 'liman'); chmod($keyPath, 0640);

$config = [
    'database' => ['dsn' => 'pgsql:host=postgres;port=5432;dbname=liman;connect_timeout=5',
        'username' => 'ls_scoreboard', 'password' => $scoreboardPassword],
    'scopes' => [LS_LOCAL_SERVER => [$ip.'/32']],
    'parallel_probes' => 2, 'vulnerability_interval_seconds' => 10,
    'vulnerability_checks' => [LS_LOCAL_SERVER => [
        'mock-health' => ['check_type'=>'SSH_AUDIT','argv'=>['/usr/bin/curl','--fail','--silent','--max-time','2','http://mock-target/health'],'timeout_ms'=>2500],
    ]], 'credentials' => [],
];
$configPath = '/etc/liman/locked-shields.php';
file_put_contents($configPath.'.tmp', "<?php\nreturn ".var_export($config, true).";\n");
chown($configPath.'.tmp', 'root'); chgrp($configPath.'.tmp', 'liman'); chmod($configPath.'.tmp', 0640);
rename($configPath.'.tmp', $configPath);

$pdo->beginTransaction();
try {
    $query = $pdo->prepare("INSERT INTO ls_teams(server_id,name,color) VALUES (?,'Blue Team 1','#36a3ff') ON CONFLICT (server_id,name) DO NOTHING");
    $query->execute([LS_LOCAL_SERVER]);
    $query = $pdo->prepare("SELECT id FROM ls_teams WHERE server_id=? AND name='Blue Team 1'");
    $query->execute([LS_LOCAL_SERVER]);
    $team = $query->fetchColumn();
    $query = $pdo->prepare("INSERT INTO ls_services(server_id,team_id,name,protocol,host,port,weight,timeout_ms,interval_seconds,options)
        VALUES (?,?,?,?,?::inet,?,100,2000,10,?::jsonb)
        ON CONFLICT (team_id,name) DO UPDATE SET host=EXCLUDED.host");
    $query->execute([LS_LOCAL_SERVER, $team, 'HTTP', 'HTTP', $ip, 80,
        '{"path":"/health","expected_status":200,"contains":"OK","hostname":"mock-target"}']);
    $query->execute([LS_LOCAL_SERVER, $team, 'SSH', 'SSH', $ip, 22, '{}']);
    $query = $pdo->prepare("INSERT INTO ls_vulnerabilities(team_id,name,check_type,command,points)
        VALUES (?,'DirtyPipe Kernel Patch','SSH_AUDIT','mock-health',25)
        ON CONFLICT (team_id,name) DO NOTHING");
    $query->execute([$team]);
    $pdo->commit();
} catch (Throwable $error) { $pdo->rollBack(); throw $error; }
Illuminate\Support\Facades\Cache::flush();

file_put_contents('/liman/.lockedshields-local-ready', "1\n");
echo "Migration + Liman registration + Blue Team HTTP/SSH seed ready ($ip).\n";
