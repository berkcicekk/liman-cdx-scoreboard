<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require '/liman/extensions/lockedshields/app/bootstrap.php';
$pdo = LockedShields\Database::connect(LockedShields\Config::load());
$scope = 'c048067a-9b65-4b19-a908-daf53bbd9501';
$query = $pdo->prepare("SELECT s.name,l.is_up,l.detail FROM ls_services s
    JOIN ls_teams t ON t.id=s.team_id
    LEFT JOIN LATERAL (SELECT is_up,detail,checked_at FROM ls_service_logs
        WHERE service_id=s.id ORDER BY checked_at DESC LIMIT 1) l ON true
    WHERE s.server_id=? AND t.name='Blue Team 1' AND s.name IN ('HTTP','SSH')
        AND l.checked_at > clock_timestamp()-interval '20 seconds'");
$deadline = time()+45;
do {
    $query->execute([$scope]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 2 && !array_filter($rows, fn ($row) => !$row['is_up'])) {
        echo "PASS: live HTTP 80 and SSH 22 probes are UP.\n"; exit(0);
    }
    sleep(1);
} while (time() < $deadline);
fwrite(STDERR, 'Probe check failed: '.json_encode($rows)."\n");
exit(1);
