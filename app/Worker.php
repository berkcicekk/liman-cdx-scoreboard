<?php
declare(strict_types=1);
namespace LockedShields;

final class Worker
{
    private \PDO $pdo;
    private array $config;
    public function __construct(\PDO $pdo, array $config) { $this->pdo=$pdo; $this->config=$config; }

    public function once(): int
    {
        $capacity = max(1, min(32, (int) ($this->config['parallel_probes'] ?? 8)));
        $running = [];
        // Claim only work that can start immediately; expired leases are reclaimable after a crash.
        foreach (array_keys($this->config['scopes']) as $scope) {
            if (count($running) >= $capacity) { break; }
            $this->pdo->beginTransaction();
            try {
                $rows = Database::query($this->pdo, 'SELECT *, host(host) AS host FROM ls_services WHERE server_id = ? AND starts_at <= clock_timestamp() AND (ends_at IS NULL OR ends_at > clock_timestamp()) AND next_probe_at <= clock_timestamp() AND (lease_until IS NULL OR lease_until < clock_timestamp()) ORDER BY next_probe_at, id LIMIT '.($capacity-count($running)).' FOR UPDATE SKIP LOCKED', [$scope]);
                foreach ($rows as &$row) {
                    $row['lease_token'] = bin2hex(random_bytes(16));
                    Database::query($this->pdo, "UPDATE ls_services SET lease_token=?, lease_until=clock_timestamp()+interval '20 seconds' WHERE id=? RETURNING id", [$row['lease_token'], $row['id']]);
                }
                unset($row);
                $this->pdo->commit();
            } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
            foreach ($rows as $row) { $running[] = $this->start($row); }
        }
        $count = count($running);
        while ($running) {
            foreach ($running as $key => &$job) {
                $job['output'] .= stream_get_contents($job['pipes'][1]);
                $status = proc_get_status($job['process']);
                $elapsed = (hrtime(true)-$job['start'])/1e6;
                $expired = $elapsed > $job['service']['timeout_ms'] + 150;
                if ($status['running'] && !$expired) { continue; }
                if ($status['running']) {
                    if (function_exists('posix_kill')) { posix_kill(-$status['pid'], SIGKILL); }
                    proc_terminate($job['process'], 9);
                }
                $job['output'] .= stream_get_contents($job['pipes'][1]);
                fclose($job['pipes'][1]);
                proc_close($job['process']);
                $result = json_decode($job['output'], true);
                if ($expired || !is_array($result) || !isset($result['is_up'], $result['latency_ms'], $result['detail'])) {
                    $result = ['is_up'=>false, 'latency_ms'=>$elapsed, 'detail'=>$expired ? 'Probe toplam zaman aşımı' : 'Probe işlemi başarısız'];
                }
                $this->record($job['service'], $result);
                unset($running[$key]);
            }
            unset($job);
            if ($running) { usleep(10000); }
        }
        $count += $this->runVulnerabilities();
        return $count;
    }

    private function start(array $service): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/probe.php'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Probe alt işlemi başlatılamadı.'); }
        $start = hrtime(true);
        fwrite($pipes[0], json_encode($service, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        return ['process'=>$process,'pipes'=>$pipes,'service'=>$service,'start'=>$start,'output'=>''];
    }

    public function record(array $service, array $result): void
    {
        $this->pdo->beginTransaction();
        try {
            $owned = Database::query($this->pdo, 'SELECT id FROM ls_services WHERE id=? AND lease_token=? AND lease_until > clock_timestamp() FOR UPDATE', [$service['id'], $service['lease_token']]);
            if ($owned) {
                $stamp = Database::query($this->pdo, 'SELECT clock_timestamp() AS at')[0]['at'];
                Database::query($this->pdo, 'INSERT INTO ls_service_logs (server_id,service_id,checked_at,is_up,latency_ms,detail,run_token) VALUES (?,?,?,?,?,?,?) ON CONFLICT (run_token) DO NOTHING RETURNING id',
                    [$service['server_id'],$service['id'],$stamp,$result['is_up'] ? 'true' : 'false',round((float)$result['latency_ms'],3),mb_substr($result['detail'],0,200),$service['lease_token']]);
                Database::query($this->pdo, "UPDATE ls_services SET next_probe_at=CAST(? AS timestamptz)+interval_seconds*interval '1 second',lease_token=NULL,lease_until=NULL WHERE id=? RETURNING id", [$stamp,$service['id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    /** Runs only administrator-configured argv arrays; DB command is an allowlist key. */
    private function runVulnerabilities(): int
    {
        $interval = max(10, min(300, (int) ($this->config['vulnerability_interval_seconds'] ?? 10)));
        $checks = $this->config['vulnerability_checks'] ?? [];
        $count = 0;
        foreach (array_keys($this->config['scopes']) as $scope) {
            if (!is_array($checks[$scope] ?? null)) { continue; }
            $this->pdo->beginTransaction();
            try {
                $rows = Database::query($this->pdo, "SELECT v.*,t.server_id FROM ls_vulnerabilities v JOIN ls_teams t ON t.id=v.team_id
                    WHERE t.server_id=? AND (v.last_checked_at IS NULL OR v.last_checked_at < clock_timestamp()-?*interval '1 second')
                    AND (v.lease_until IS NULL OR v.lease_until < clock_timestamp()) ORDER BY v.id FOR UPDATE OF v SKIP LOCKED", [$scope,$interval]);
                foreach ($rows as &$row) {
                    $row['lease_token'] = bin2hex(random_bytes(16));
                    Database::query($this->pdo, "UPDATE ls_vulnerabilities SET lease_token=?,lease_until=clock_timestamp()+interval '20 seconds' WHERE id=?", [$row['lease_token'],$row['id']]);
                }
                unset($row); $this->pdo->commit();
            } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
            foreach ($rows as $row) {
                $definition = $checks[$scope][$row['command']] ?? null;
                $patched = is_array($definition) && ($definition['check_type'] ?? '') === $row['check_type'] && $this->executeVulnerability($definition);
                $this->recordVulnerability($row, $patched);
                $count++;
            }
        }
        return $count;
    }

    private function executeVulnerability(array $definition): bool
    {
        $argv = $definition['argv'] ?? null;
        $timeout = (int) ($definition['timeout_ms'] ?? 3000);
        if (!is_array($argv) || !$argv || $timeout < 100 || $timeout > 5000 || array_filter($argv, static fn($v) => !is_string($v) || $v === '')) { return false; }
        $process = proc_open(array_values($argv), [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
        if (!is_resource($process)) { return false; }
        $started = hrtime(true); $exit = 1;
        do {
            $state = proc_get_status($process);
            if (!$state['running']) { $exit = (int) $state['exitcode']; break; }
            if ((hrtime(true)-$started)/1e6 > $timeout) { proc_terminate($process, 9); break; }
            usleep(10000);
        } while (true);
        $closed = proc_close($process);
        return $exit === 0 || ($exit < 0 && $closed === 0);
    }

    private function recordVulnerability(array $check, bool $patched): void
    {
        $this->pdo->beginTransaction();
        try {
            $owned = Database::query($this->pdo, 'SELECT id FROM ls_vulnerabilities WHERE id=? AND lease_token=? AND lease_until>clock_timestamp() FOR UPDATE', [$check['id'],$check['lease_token']]);
            if ($owned) { Database::query($this->pdo, "UPDATE ls_vulnerabilities SET status=?,last_checked_at=clock_timestamp(),lease_token=NULL,lease_until=NULL WHERE id=?", [$patched ? 'Patched' : 'Vulnerable',$check['id']]); }
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }
}
