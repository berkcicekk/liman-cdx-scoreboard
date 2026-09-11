#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
// Keep this inode and handle for the entire lifetime. Never unlink a flock file.
$path = getenv('LS_WORKER_LOCK') ?: '/var/lib/lockedshields-probe/worker.lock';
$lock = @fopen($path, 'c');
if ($lock === false) { fwrite(STDERR, "Worker lock cannot be opened: check runtime directory permissions.\n"); exit(1); }
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "A probe worker already holds the lock.\n");
    fclose($lock);
    exit(75);
}
register_shutdown_function(static function () use ($lock): void {
    flock($lock, LOCK_UN);
    fclose($lock);
});
ftruncate($lock, 0);
fwrite($lock, (string) getmypid());
fflush($lock);
require dirname(__DIR__).'/bin/worker.php';
