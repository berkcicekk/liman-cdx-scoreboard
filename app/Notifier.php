<?php
declare(strict_types=1);
namespace LockedShields;

final class Notifier
{
    public static function vulnerabilityTransition(array $config, array $event): void
    {
        $targets = $config['notifications'][$event['server_id']]['webhooks'] ?? [];
        if (!is_array($targets)) { return; }
        $payload = json_encode(['event'=>'vulnerability.status_changed','occurred_at'=>$event['checked_at'],'server_id'=>$event['server_id'],'team_id'=>$event['team_id'],'vulnerability'=>$event['name'],'previous_status'=>$event['previous_status'],'status'=>$event['status'],'exit_code'=>$event['exit_code'],'duration_ms'=>$event['duration_ms']], JSON_THROW_ON_ERROR);
        foreach ($targets as $url) {
            $parts = is_string($url) ? parse_url($url) : false;
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user'], $parts['pass'])) { continue; }
            $handle = curl_init($url);
            if ($handle === false) { continue; }
            curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>1000,CURLOPT_TIMEOUT_MS=>2000,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
            curl_exec($handle);
            curl_close($handle);
        }
    }
}
