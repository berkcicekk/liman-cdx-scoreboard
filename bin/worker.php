<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$once = in_array('--once', $argv, true);
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop): void { $stop=true; });
    pcntl_signal(SIGINT, static function () use (&$stop): void { $stop=true; });
}
try {
    $config = \LockedShields\Config::load();
    $worker = new \LockedShields\Worker(\LockedShields\Database::connect($config), $config);
    do {
        $count = $worker->once();
        if ($once) { echo "Completed probes: $count\n"; break; }
        if (!$stop && $count === 0) { usleep(250000); }
    } while (!$stop);
} catch (Throwable $e) {
    // Detailed connection exceptions may contain credentials; keep service logs generic.
    fwrite(STDERR, 'LockedShields worker stopped ('.get_class($e)."). Check configuration/database.\n");
    exit(1);
}
