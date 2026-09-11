<?php
declare(strict_types=1);
namespace LockedShields;

final class Scoreboard
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo=$pdo; }

    public function dashboard(string $scope): array
    {
        // One consistent snapshot for ranking, grid and history while probes/injects arrive.
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $at = Database::query($this->pdo, 'SELECT now() AS at')[0]['at'];
            $teams = Database::query($this->pdo, 'SELECT id,name,color,created_at FROM ls_teams WHERE server_id=? ORDER BY id', [$scope]);
            $services = $this->services($scope, $at);
            $vulnerabilities = $this->vulnerabilities($scope);
            $vulnerabilityLogs = $this->vulnerabilityLogs($scope);
            $trend = $this->trend($scope, $at);
            $security = [];
            foreach ($vulnerabilities as $vulnerability) {
                if ($vulnerability['status'] === 'Patched') { $security[$vulnerability['team_id']] = ($security[$vulnerability['team_id']] ?? 0) + (float) $vulnerability['points']; }
            }
            $last = [];
            foreach ($trend as $point) { $last[$point['team_id']]=$point; }
            foreach ($teams as &$team) {
                $point = $last[$team['id']] ?? [];
                $team['sla_score'] = (float) ($point['sla_score'] ?? 0);
                $team['inject_score'] = (float) ($point['inject_score'] ?? 0);
                $team['security_score'] = round($security[$team['id']] ?? 0, 2);
                $team['score'] = round($team['sla_score']+$team['inject_score']+$team['security_score'], 2);
            }
            unset($team);
            usort($teams, static function (array $a, array $b): int {
                return ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']);
            });
            $injects = Database::query($this->pdo, 'SELECT i.id,i.team_id,t.name AS team_name,i.kind,i.points,i.reason,i.created_by,i.created_at FROM ls_injects i JOIN ls_teams t ON t.id=i.team_id WHERE i.server_id=? ORDER BY i.created_at DESC,i.id DESC LIMIT 100', [$scope]);
            $this->pdo->commit();
            return ['generated_at'=>$at,'teams'=>$teams,'services'=>$services,'vulnerabilities'=>$vulnerabilities,'vulnerability_logs'=>$vulnerabilityLogs,'trend'=>$trend,'injects'=>$injects];
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function vulnerabilities(string $scope): array
    {
        return Database::query($this->pdo, 'SELECT v.id,v.team_id,v.name,v.check_type,v.command,v.points,v.status,v.last_checked_at,
            latest.exit_code,latest.duration_ms,latest.output AS last_output
            FROM ls_vulnerabilities v JOIN ls_teams t ON t.id=v.team_id
            LEFT JOIN LATERAL (SELECT exit_code,duration_ms,output FROM ls_vulnerability_logs WHERE vulnerability_id=v.id ORDER BY checked_at DESC,id DESC LIMIT 1) latest ON true
            WHERE t.server_id=? ORDER BY v.team_id,v.name', [$scope]);
    }

    public function vulnerabilityLogs(string $scope): array
    {
        return Database::query($this->pdo, 'SELECT l.id,l.vulnerability_id,v.name AS vulnerability_name,t.name AS team_name,l.checked_at,l.is_patched,l.exit_code,l.duration_ms,l.output
            FROM ls_vulnerability_logs l JOIN ls_vulnerabilities v ON v.id=l.vulnerability_id JOIN ls_teams t ON t.id=v.team_id
            WHERE t.server_id=? ORDER BY l.checked_at DESC,l.id DESC LIMIT 100', [$scope]);
    }

    public function services(string $scope, string $at): array
    {
        $sql = <<<'SQL'
WITH cfg AS (SELECT CAST(? AS uuid) AS scope, CAST(? AS timestamptz) AS at),
spans AS (
 SELECT l.service_id,l.is_up,l.checked_at,
 LEAST(lead(l.checked_at) OVER (PARTITION BY l.service_id ORDER BY l.checked_at),
       l.checked_at+s.interval_seconds*interval '1 second',cfg.at,COALESCE(s.ends_at,cfg.at)) AS until_at
 FROM ls_service_logs l JOIN ls_services s ON s.id=l.service_id CROSS JOIN cfg
 WHERE l.server_id=cfg.scope AND l.checked_at<=cfg.at
), totals AS (
 SELECT service_id,
 COALESCE(sum(GREATEST(0,extract(epoch FROM until_at-checked_at))) FILTER (WHERE is_up),0) AS uptime_seconds,
 COALESCE(sum(GREATEST(0,extract(epoch FROM until_at-checked_at))) FILTER (WHERE NOT is_up),0) AS downtime_seconds
 FROM spans GROUP BY service_id
)
SELECT s.id,s.team_id,s.name,s.protocol,host(s.host) AS host,s.port,s.weight,s.timeout_ms,s.interval_seconds,
 s.starts_at,s.ends_at,s.options,
 COALESCE(t.uptime_seconds,0) AS uptime_seconds,COALESCE(t.downtime_seconds,0) AS downtime_seconds,
 GREATEST(0,extract(epoch FROM LEAST(cfg.at,COALESCE(s.ends_at,cfg.at))-s.starts_at)) AS elapsed_seconds,
 latest.checked_at,latest.latency_ms,latest.detail,
 CASE WHEN cfg.at<s.starts_at THEN 'pending'
      WHEN s.ends_at IS NOT NULL AND cfg.at>=s.ends_at THEN 'ended'
      WHEN latest.checked_at IS NULL THEN 'unknown'
      WHEN latest.checked_at+s.interval_seconds*interval '1 second'<cfg.at THEN 'stale'
      WHEN latest.is_up THEN 'up' ELSE 'down' END AS status
FROM ls_services s CROSS JOIN cfg LEFT JOIN totals t ON t.service_id=s.id
LEFT JOIN LATERAL (SELECT checked_at,is_up,latency_ms,detail FROM ls_service_logs
 WHERE service_id=s.id AND server_id=cfg.scope AND checked_at<=cfg.at ORDER BY checked_at DESC LIMIT 1) latest ON true
WHERE s.server_id=cfg.scope ORDER BY s.team_id,s.name
SQL;
        $rows = Database::query($this->pdo, $sql, [$scope,$at]);
        foreach ($rows as &$row) {
            $elapsed = (float) $row['elapsed_seconds'];
            $row['unknown_seconds'] = max(0,$elapsed-(float)$row['uptime_seconds']-(float)$row['downtime_seconds']);
            $row['sla_percent'] = $elapsed>0 ? round(100*(float)$row['uptime_seconds']/$elapsed,2) : 0;
        }
        return $rows;
    }

    public function trend(string $scope, string $at): array
    {
        // 61 points covering the last hour; each point is cumulative since service start.
        // Future samples/injects never influence a historical point.
        $sql = <<<'SQL'
WITH cfg AS (SELECT CAST(? AS uuid) AS scope,CAST(? AS timestamptz) AS at),
points AS (SELECT generate_series(cfg.at-interval '1 hour',cfg.at,interval '1 minute') AS at FROM cfg),
spans AS MATERIALIZED (
 SELECT l.service_id,l.is_up,l.checked_at,
 LEAST(lead(l.checked_at) OVER (PARTITION BY l.service_id ORDER BY l.checked_at),
       l.checked_at+s.interval_seconds*interval '1 second',COALESCE(s.ends_at,cfg.at),cfg.at) AS until_at
 FROM ls_service_logs l JOIN ls_services s ON s.id=l.service_id CROSS JOIN cfg
 WHERE l.server_id=cfg.scope AND l.checked_at<=cfg.at
), service_scores AS (
 SELECT p.at,s.team_id,s.id,s.weight,
 GREATEST(0,extract(epoch FROM LEAST(p.at,COALESCE(s.ends_at,p.at))-s.starts_at)) AS elapsed,
 COALESCE(sum(GREATEST(0,extract(epoch FROM LEAST(sp.until_at,p.at)-GREATEST(sp.checked_at,s.starts_at)))) FILTER (WHERE sp.is_up),0) AS up
 FROM points p CROSS JOIN cfg JOIN ls_services s ON s.server_id=cfg.scope AND s.starts_at<=p.at
 LEFT JOIN spans sp ON sp.service_id=s.id AND sp.checked_at<=p.at
 GROUP BY p.at,s.team_id,s.id
), sla AS (
 SELECT at,team_id,COALESCE(sum(weight*up/NULLIF(elapsed,0)),0) AS score
 FROM service_scores GROUP BY at,team_id
), security AS (
 SELECT p.at,v.team_id,COALESCE(sum(v.points) FILTER (WHERE latest.is_patched),0) AS score
 FROM points p CROSS JOIN cfg JOIN ls_teams t ON t.server_id=cfg.scope
 JOIN ls_vulnerabilities v ON v.team_id=t.id AND v.created_at<=p.at
 LEFT JOIN LATERAL (SELECT is_patched FROM ls_vulnerability_logs WHERE vulnerability_id=v.id AND checked_at<=p.at ORDER BY checked_at DESC,id DESC LIMIT 1) latest ON true
 GROUP BY p.at,v.team_id
)
SELECT p.at,t.id AS team_id,round(COALESCE(sla.score,0),2) AS sla_score,
 round(COALESCE(inj.score,0),2) AS inject_score,round(COALESCE(security.score,0),2) AS security_score,
 round(COALESCE(sla.score,0)+COALESCE(inj.score,0)+COALESCE(security.score,0),2) AS score
FROM points p CROSS JOIN cfg JOIN ls_teams t ON t.server_id=cfg.scope AND t.created_at<=p.at
LEFT JOIN sla ON sla.team_id=t.id AND sla.at=p.at
LEFT JOIN LATERAL (SELECT sum(points) AS score FROM ls_injects
 WHERE team_id=t.id AND server_id=cfg.scope AND created_at<=p.at) inj ON true
LEFT JOIN security ON security.team_id=t.id AND security.at=p.at
ORDER BY p.at,t.id
SQL;
        return Database::query($this->pdo, $sql, [$scope,$at]);
    }

    public function createTeam(string $scope, array $data): array
    {
        $name = Input::text($data['name'] ?? '',80);
        $color = $data['color'] ?? '#36a3ff';
        if (!is_string($color) || !preg_match('/^#[0-9a-f]{6}$/iD',$color)) { throw new \InvalidArgumentException('Geçersiz takım rengi.'); }
        return Database::query($this->pdo,'INSERT INTO ls_teams(server_id,name,color) VALUES (?,?,?) RETURNING id,name,color',[$scope,$name,$color])[0];
    }

    public function createService(string $scope, array $data, array $config): array
    {
        $s = Input::service($data,$config['scopes'][$scope] ?? []);
        $this->team($scope,$s['team_id']);
        $keys = array_keys($s);
        return Database::query($this->pdo,'INSERT INTO ls_services(server_id,'.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys)+1,'?')).') RETURNING id,name',array_merge([$scope],array_values($s)))[0];
    }

    public function addInject(string $scope, string $actor, array $data): array
    {
        $team = Input::integer($data['team_id'] ?? '',1,PHP_INT_MAX);
        $this->team($scope,$team);
        $kind = $data['kind'] ?? '';
        if (!in_array($kind,['task','penalty'],true)) { throw new \InvalidArgumentException('Geçersiz inject türü.'); }
        $amount = round(Input::number($data['points'] ?? '',0.01,100000),2);
        $points = $kind === 'penalty' ? -$amount : $amount;
        $reason = Input::text($data['reason'] ?? '',500);
        $request = Policy::uuid((string)($data['request_id'] ?? ''));
        $rows = Database::query($this->pdo,'INSERT INTO ls_injects(server_id,team_id,kind,points,reason,created_by,request_id) VALUES (?,?,?,?,?,?,?) ON CONFLICT (server_id,request_id) DO NOTHING RETURNING *',[$scope,$team,$kind,$points,$reason,Policy::uuid($actor),$request]);
        if ($rows) { return $rows[0]; }
        $existing = Database::query($this->pdo,'SELECT * FROM ls_injects WHERE server_id=? AND request_id=?',[$scope,$request])[0];
        if ((int)$existing['team_id'] !== $team || (float)$existing['points'] !== $points || $existing['kind'] !== $kind || $existing['reason'] !== $reason || $existing['created_by'] !== $actor) {
            throw new \DomainException('Bu işlem kimliği farklı bir inject için kullanılmış.',409);
        }
        return $existing;
    }

    private function team(string $scope, int $team): void
    {
        if (!Database::query($this->pdo,'SELECT id FROM ls_teams WHERE server_id=? AND id=?',[$scope,$team])) {
            throw new \DomainException('Takım bu Liman sunucusunda bulunamadı.',404);
        }
    }
}
