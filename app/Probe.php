<?php
declare(strict_types=1);
namespace LockedShields;

/** All methods run in an isolated CLI child; Worker enforces the total wall deadline. */
final class Probe
{
    public function run(array $service): array
    {
        $start = hrtime(true);
        $up = false;
        $detail = 'Probe başarısız';
        // Native warnings must never leak credentials, internal paths, or corrupt JSON output.
        set_error_handler(static function (): bool { return true; });
        try {
            $options = is_array($service['options']) ? $service['options'] : json_decode($service['options'], true, 16, JSON_THROW_ON_ERROR);
            switch ($service['protocol']) {
                case 'HTTP': case 'HTTPS': $this->http($service, $options); break;
                case 'SSH': $this->ssh($service); break;
                case 'DNS': $this->dns($service, $options); break;
                case 'LDAP': $this->ldap($service, $options); break;
                case 'WINRM': $this->winrm($service, $options); break;
                case 'PING': $this->ping($service); break;
                default: throw new \RuntimeException('Desteklenmeyen protokol');
            }
            $up = true;
            $detail = 'Protokol kontrolü başarılı';
        } catch (\Throwable $e) {
            $detail = $e instanceof \RuntimeException ? mb_substr($e->getMessage(), 0, 200) : 'Probe çalıştırılamadı';
        } finally {
            restore_error_handler();
        }
        return ['is_up'=>$up, 'latency_ms'=>round((hrtime(true)-$start)/1e6, 3), 'detail'=>$detail];
    }

    private function address(array $s): string
    {
        return strpos($s['host'], ':') !== false ? '['.$s['host'].']' : $s['host'];
    }

    private function curl(array $s, array $o, ?string $body = null): array
    {
        $tls = $s['protocol'] === 'HTTPS' || ($o['tls'] ?? false);
        $host = $o['hostname'] ?? $this->address($s);
        $url = ($tls ? 'https' : 'http').'://'.$host.':'.$s['port'].($o['path'] ?? '/');
        $curl = curl_init($url);
        $response = '';
        $headers = [];
        $limit = 1048576;
        curl_setopt_array($curl, [
            CURLOPT_CONNECTTIMEOUT_MS => (int) $s['timeout_ms'],
            CURLOPT_TIMEOUT_MS => (int) $s['timeout_ms'],
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_USERAGENT => 'Liman-LockedShields/1.0',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$response, $limit): int {
                if (strlen($response) + strlen($chunk) > $limit) { return 0; }
                $response .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (stripos($line, 'WWW-Authenticate:') === 0) { $headers[] = trim(substr($line, 17)); }
                return strlen($line);
            },
        ]);
        if (isset($o['hostname'])) {
            curl_setopt($curl, CURLOPT_RESOLVE, [$host.':'.$s['port'].':'.$this->address($s)]);
        }
        if (isset($s['credential'])) {
            $credential = $s['credential'];
            $mode = $credential['auth'] ?? 'ntlm';
            $modes = ['ntlm'=>CURLAUTH_NTLM,'negotiate'=>CURLAUTH_NEGOTIATE,'basic'=>CURLAUTH_BASIC];
            if (!isset($modes[$mode]) || ($mode === 'basic' && !$tls)) {
                curl_close($curl);
                throw new \RuntimeException('HTTP kimlik doğrulama ayarı geçersiz; Basic için TLS gereklidir');
            }
            curl_setopt_array($curl,[CURLOPT_HTTPAUTH=>$modes[$mode],CURLOPT_USERNAME=>$credential['username'],CURLOPT_PASSWORD=>$credential['password']]);
        }
        if ($body !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/soap+xml;charset=UTF-8']]);
        }
        try {
            if (curl_exec($curl) === false) {
                throw new \RuntimeException(curl_errno($curl) === CURLE_OPERATION_TIMEDOUT ? 'HTTP zaman aşımı' : 'HTTP/TLS bağlantısı veya yanıtı başarısız');
            }
            return [(int) curl_getinfo($curl, CURLINFO_HTTP_CODE), $response, $headers];
        } finally { curl_close($curl); }
    }

    private function http(array $s, array $o): void
    {
        [$status, $body] = $this->curl($s, $o);
        if (isset($o['expected_status']) ? $status !== $o['expected_status'] : ($status < 200 || $status >= 400)) {
            throw new \RuntimeException('Beklenmeyen HTTP durumu: '.$status);
        }
        if (isset($o['contains']) && strpos($body, $o['contains']) === false) { throw new \RuntimeException('Beklenen HTTP içeriği bulunamadı'); }
    }

    private function socket(array $s, string $transport = 'tcp')
    {
        $socket = stream_socket_client($transport.'://'.$this->address($s).':'.$s['port'], $errno, $error, $s['timeout_ms']/1000);
        if ($socket === false) { throw new \RuntimeException('Bağlantı kurulamadı'); }
        stream_set_timeout($socket, intdiv((int) $s['timeout_ms'], 1000), ((int) $s['timeout_ms'] % 1000)*1000);
        return $socket;
    }

    private function ssh(array $s): void
    {
        $socket = $this->socket($s);
        try {
            for ($i=0; $i<12; $i++) {
                $line = fgets($socket, 256);
                if ($line === false) { break; }
                if (preg_match('/^SSH-(2\.0|1\.99)-[^\r\n]+\r?\n$/D', $line)) { return; }
            }
            throw new \RuntimeException('SSH protokol bannerı alınamadı');
        } finally { fclose($socket); }
    }

    private function dns(array $s, array $o): void
    {
        $id = random_int(1, 65535);
        $qtype = ($o['dns_type'] ?? 'A') === 'AAAA' ? 28 : 1;
        $name = '';
        foreach (explode('.', rtrim($o['dns_name'], '.')) as $label) { $name .= chr(strlen($label)).$label; }
        $question = $name."\0".pack('nn', $qtype, 1);
        $packet = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0).$question;
        $socket = $this->socket($s, 'udp');
        try {
            if (fwrite($socket, $packet) !== strlen($packet)) { throw new \RuntimeException('DNS sorgusu gönderilemedi'); }
            $reply = fread($socket, 65535);
            if ($reply === false || strlen($reply) < 12) { throw new \RuntimeException('DNS yanıtı alınamadı'); }
            $header = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($reply, 0, 12));
            if ($header['id'] !== $id || ($header['flags'] & 0x8000) === 0 || ($header['flags'] & 0x020f) !== 0 || $header['questions'] !== 1 || $header['answers'] < 1) {
                throw new \RuntimeException('DNS hatalı, kesilmiş veya boş yanıt');
            }
            $offset = 12;
            $replyName = $this->dnsName($reply, $offset);
            if (strtolower($replyName) !== strtolower(rtrim($o['dns_name'], '.')) || substr($reply, $offset, 4) !== pack('nn', $qtype, 1)) {
                throw new \RuntimeException('DNS soru eşleşmedi');
            }
            $offset += 4;
            for ($i=0; $i<$header['answers']; $i++) {
                $this->dnsName($reply, $offset);
                if ($offset+10 > strlen($reply)) { break; }
                $rr = unpack('ntype/nclass/Nttl/nlength', substr($reply, $offset, 10));
                $offset += 10;
                if ($offset+$rr['length'] > strlen($reply)) { break; }
                if ($rr['type'] === $qtype && $rr['class'] === 1 && $rr['length'] === ($qtype === 1 ? 4 : 16)) { return; }
                $offset += $rr['length'];
            }
            throw new \RuntimeException('DNS beklenen adres kaydını içermiyor');
        } finally { fclose($socket); }
    }

    private function dnsName(string $packet, int &$offset): string
    {
        $position = $offset; $jumped = false; $labels = [];
        for ($steps=0; $steps<128; $steps++) {
            if ($position >= strlen($packet)) { break; }
            $length = ord($packet[$position++]);
            if (($length & 0xc0) === 0xc0) {
                if ($position >= strlen($packet)) { break; }
                $pointer = (($length & 0x3f) << 8) | ord($packet[$position++]);
                if (!$jumped) { $offset = $position; $jumped = true; }
                $position = $pointer;
            } elseif ($length === 0) {
                if (!$jumped) { $offset = $position; }
                return implode('.', $labels);
            } elseif ($length <= 63 && $position+$length <= strlen($packet)) {
                $labels[] = substr($packet, $position, $length); $position += $length;
            } else { break; }
        }
        throw new \RuntimeException('DNS paketi bozuk');
    }

    private function ldap(array $s, array $o): void
    {
        $link = ldap_connect((($o['tls'] ?? false) ? 'ldaps' : 'ldap').'://'.$this->address($s).':'.$s['port']);
        if ($link === false) { throw new \RuntimeException('LDAP bağlantısı oluşturulamadı'); }
        try {
            ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($link, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, max(1, (int) ceil($s['timeout_ms']/1000)));
            if (isset($s['credential']) && !($o['tls'] ?? false)) { throw new \RuntimeException('LDAP kimlik doğrulaması için TLS gereklidir'); }
            $bound = isset($s['credential']) ? ldap_bind($link,$s['credential']['username'],$s['credential']['password']) : ldap_bind($link);
            if (!$bound) { throw new \RuntimeException('LDAP bind başarısız'); }
            $result = ldap_read($link, $o['ldap_base'] ?? '', '(objectClass=*)', ['objectClass'], 0, 1, max(1, (int) ceil($s['timeout_ms']/1000)));
            if ($result === false || ldap_count_entries($link, $result) < 1) { throw new \RuntimeException('LDAP RootDSE/base okuması başarısız'); }
        } finally { ldap_unbind($link); }
    }

    private function winrm(array $s, array $o): void
    {
        $o['path'] = $o['path'] ?? '/wsman';
        $soap = '<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:w="http://schemas.dmtf.org/wbem/wsman/identity/1/wsmanidentity.xsd"><s:Header/><s:Body><w:Identify/></s:Body></s:Envelope>';
        [$status, $body, $auth] = $this->curl($s, $o, $soap);
        // A challenge alone is not proof of WS-Man; require an actual IdentifyResponse.
        if ($status !== 200) { throw new \RuntimeException('WS-Man Identify başarısız: HTTP '.$status); }
        $xml = new \DOMDocument();
        if (!$xml->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || $xml->doctype !== null) {
            throw new \RuntimeException('WS-Man XML yanıtı geçersiz');
        }
        if ($xml->getElementsByTagNameNS('http://schemas.dmtf.org/wbem/wsman/identity/1/wsmanidentity.xsd', 'IdentifyResponse')->length < 1) {
            throw new \RuntimeException('WS-Man IdentifyResponse bulunamadı');
        }
    }

    private function ping(array $s): void
    {
        // No shell interpolation; parent Worker kills the process group on deadline.
        $process = proc_open(['/bin/ping', '-n', '-c', '1', '-W', (string) max(1, ceil($s['timeout_ms']/1000)), $s['host']],
            [0=>['file','/dev/null','r'], 1=>['file','/dev/null','w'], 2=>['file','/dev/null','w']], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) { throw new \RuntimeException('ICMP echo yanıtı alınamadı'); }
    }
}
