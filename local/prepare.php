<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('LS_LOCAL_HARNESS') !== '1') { exit(1); }

$init = file_get_contents('/tmp/init.sh');
$replacements = [
    'rm -rf /liman_files' => '# Preserve image files for container restarts.',
    'chmod -R 755 /liman/extensions' => 'chmod 755 /liman/extensions',
    'chmod -R 700 /liman/server' => "find /liman/server -path /liman/server/storage/extensions -prune -o -exec chmod 700 {} +",
    'chown -R liman:liman /liman/server' => "find /liman/server -path /liman/server/storage/extensions -prune -o -exec chown liman:liman {} +",
    'sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/g" /liman/server/.env ' => <<<'BASH'
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/g" /liman/server/.env
sed -i "s#^APP_URL=.*#APP_URL=${APP_URL}#" /liman/server/.env
BASH,
    '/usr/bin/supervisord -c /etc/supervisor/supervisor.conf' => "bash /opt/lockedshields-local/after-init.sh\nexec /usr/bin/supervisord -c /etc/supervisor/supervisor.conf",
];
foreach ($replacements as $from => $to) {
    if (!str_contains($init, $from)) { throw new RuntimeException('Official entrypoint changed: review local/prepare.php.'); }
    $init = preg_replace_callback('/^'.preg_quote($from, '/').'$/m', static fn () => $to, $init, -1, $count);
    if ($count !== 1) { throw new RuntimeException('Official entrypoint line changed: review local/prepare.php.'); }
}
file_put_contents('/tmp/lockedshields-init.sh', $init);

// HTTP outside; retain official TLS loopback API/render upstreams.
$path = '/etc/nginx/sites-available/liman.conf';
$nginx = file_get_contents($path);
$nginx = str_replace('listen 443 ssl http2;', 'listen 80;', $nginx);
$nginx = str_replace('listen 3001 ssl http2;', 'listen 3001;', $nginx);
$nginx = str_replace('proxy_pass https://api_proxy/', 'proxy_pass http://api_proxy/', $nginx);
$nginx = str_replace('proxy_set_header Host $host;', 'proxy_set_header Host $http_host;', $nginx);
file_put_contents($path, $nginx);
if (is_link('/etc/nginx/sites-enabled/default')) { unlink('/etc/nginx/sites-enabled/default'); }
