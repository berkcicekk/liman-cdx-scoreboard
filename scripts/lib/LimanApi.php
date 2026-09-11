<?php
declare(strict_types=1);
namespace LockedShields\Deployment;

/** Genuine HTTP API client. No direct dispatch/SQL substitute for panel operations. */
final class LimanApi
{
    private array $config;
    private string $token;
    public function __construct(array $config)
    {
        $url = parse_url($config['base_url'] ?? '');
        if (!$url || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
            !in_array($url['scheme'] ?? '', ['https','http'], true) ||
            (($url['scheme'] ?? '') === 'http' && !in_array($url['host'] ?? '', ['127.0.0.1','localhost','[::1]'], true))) {
            throw new \RuntimeException('Liman API requires HTTPS (HTTP allowed only on loopback test hosts).');
        }
        $this->config=$config;
        $token=trim((string) @file_get_contents($config['token_file'] ?? ''));
        if ($token==='' || preg_match('/\s/',$token)) { throw new \RuntimeException('Liman JWT file is missing or invalid.'); }
        $this->token=$token;
    }

    public function request(string $path, array $data=[], string $method='POST'): array
    {
        if (substr($path,0,1)!=='/' || substr($path,0,2)==='//') { throw new \RuntimeException('Invalid API path.'); }
        $ch=curl_init(rtrim($this->config['base_url'],'/').$path);
        $body='';
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>60,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.$this->token],
            CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$body): int {
                if(strlen($body)+strlen($chunk)>16*1024*1024){return 0;}
                $body.=$chunk;return strlen($chunk);
            }]);
        if(isset($this->config['ca_file']))curl_setopt($ch,CURLOPT_CAINFO,$this->config['ca_file']);
        if($method!=='GET')curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
        try {
            $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            if($ok===false)throw new \RuntimeException('Liman HTTP/TLS request failed (curl '.curl_errno($ch).').');
            if($status<200 || $status>=300)throw new \RuntimeException('Liman API HTTP '.$status.' on '.$path.'. Check authentication and function permissions.');
            $decoded=json_decode($body,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($decoded))throw new \RuntimeException('Liman API returned an unexpected JSON shape.');
            return $decoded;
        } finally { curl_close($ch); }
    }

    public function findExtension(): ?array
    {
        foreach($this->request('/api/settings/extensions',[],'GET') as $row){
            if(is_array($row) && strtolower($row['name'] ?? '')==='cenk')return $row;
        }
        return null;
    }

    public function register(string $zip, string $scope, string $version): string
    {
        $extension=$this->findExtension();
        if(!$extension || $extension['version']!==$version){
            $this->request('/api/settings/extensions/upload',['extension'=>new \CURLFile($zip,'application/zip','Cenk-'.$version.'.zip')]);
            $extension=$this->findExtension();
        }
        if(!$extension)throw new \RuntimeException('Native Liman upload did not register the extension.');
        $id=\LockedShields\Policy::uuid($extension['id']);
        if(isset($this->config['extension_id']) && $id!==$this->config['extension_id'])throw new \RuntimeException('Configured extension UUID differs from the native API.');
        $assigned=$this->request('/api/servers/'.$scope.'/extensions',[],'GET');
        if(!array_filter($assigned,static function($row)use($id){return ($row['id']??'')===$id;})){
            $this->request('/api/extensions/assign',['server_id'=>$scope,'extensions[0]'=>$id]);
        }
        return $id;
    }

    public function engine(string $function,string $scope,string $extension,array $data=[]): array
    {
        $response=$this->request($this->config['engine_path'] ?? '/engine/',array_merge($data,[
            'lmntargetFunction'=>$function,'server_id'=>$scope,'extension_id'=>$extension,
            'token'=>$this->token,'lmnbaseurl'=>$this->config['base_url'],'locale'=>'tr',
        ]));
        if(($response['status']??0)!==200 || !is_array($response['message']??null)) {
            throw new \RuntimeException('Liman function '.$function.' failed with application status '.($response['status']??'missing').'.');
        }
        return $response['message'];
    }
}
