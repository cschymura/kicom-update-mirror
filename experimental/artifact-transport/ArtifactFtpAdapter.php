<?php
declare(strict_types=1);

/**
 * Slow fallback adapter for the central artifact transport bus.
 * Credentials are supplied at runtime by a callback and are never persisted or
 * returned. FTPS is preferred. Plain FTP requires explicit source opt-in.
 */
final class KiComArtifactFtpAdapter
{
    private Closure $credentialProvider;

    /** @param callable(array<string,mixed>):array<string,mixed> $credentialProvider */
    public function __construct(callable $credentialProvider)
    {
        $this->credentialProvider=Closure::fromCallable($credentialProvider);
    }

    /**
     * Signature matches KiComArtifactTransportBus external adapters.
     * @return array<string,mixed>
     */
    public function __invoke(array $source,array $artifact,string $incomingDir): array
    {
        $mode=strtolower(trim((string)($source['mode']??'ftps')));
        if(!in_array($mode,['ftps','ftp'],true)) return ['ok'=>false,'code'=>'FTP_MODE_INVALID'];
        if($mode==='ftp'&&empty($source['allow_plaintext_ftp'])) return ['ok'=>false,'code'=>'PLAINTEXT_FTP_FORBIDDEN'];
        if(!extension_loaded('ftp')) return ['ok'=>false,'code'=>'FTP_EXTENSION_UNAVAILABLE'];

        $provider=$this->credentialProvider;
        try{$cred=$provider($source);}catch(Throwable $e){return ['ok'=>false,'code'=>'FTP_CREDENTIAL_PROVIDER_FAILED'];}
        if(!is_array($cred)) return ['ok'=>false,'code'=>'FTP_CREDENTIALS_UNAVAILABLE'];
        $host=strtolower(trim((string)($cred['host']??$source['host']??'')));
        $user=(string)($cred['username']??'');
        $password=(string)($cred['password']??'');
        $port=(int)($cred['port']??($mode==='ftps'?21:21));
        $timeout=max(5,min(120,(int)($source['timeout_seconds']??45)));
        if(!preg_match('/^[a-z0-9.-]+$/',$host)||filter_var($host,FILTER_VALIDATE_IP)||$user===''||$password===''||$port<1||$port>65535){
            return ['ok'=>false,'code'=>'FTP_CREDENTIALS_INVALID'];
        }

        $template=(string)($source['remote_path']??'');
        $remote=str_replace(['{version}','{filename}'],[(string)$artifact['version'],(string)$artifact['filename']],$template);
        if(!$this->safeRemotePath($remote)) return ['ok'=>false,'code'=>'FTP_REMOTE_PATH_INVALID'];
        if(!is_dir($incomingDir)&&!@mkdir($incomingDir,0700,true)&&!is_dir($incomingDir)) return ['ok'=>false,'code'=>'FTP_INCOMING_UNAVAILABLE'];
        $local=rtrim($incomingDir,'/').'/ftp-'.bin2hex(random_bytes(8)).'-'.(string)$artifact['filename'];

        $conn=false;
        if($mode==='ftps'){
            if(!function_exists('ftp_ssl_connect')) return ['ok'=>false,'code'=>'FTPS_UNAVAILABLE'];
            $conn=@ftp_ssl_connect($host,$port,$timeout);
        }else{
            $conn=@ftp_connect($host,$port,$timeout);
        }
        if($conn===false) return ['ok'=>false,'code'=>$mode==='ftps'?'FTPS_CONNECT_FAILED':'FTP_CONNECT_FAILED'];

        $fh=null;
        try{
            if(!@ftp_login($conn,$user,$password)) return ['ok'=>false,'code'=>'FTP_LOGIN_FAILED'];
            @ftp_pasv($conn,true);
            $fh=@fopen($local,'wb');
            if($fh===false) return ['ok'=>false,'code'=>'FTP_LOCAL_OPEN_FAILED'];
            if(!@ftp_fget($conn,$fh,$remote,FTP_BINARY)) return ['ok'=>false,'code'=>'FTP_DOWNLOAD_FAILED'];
            fclose($fh);$fh=null;@chmod($local,0600);
            return ['ok'=>true,'code'=>$mode==='ftps'?'FTPS_DOWNLOAD_OK':'FTP_DOWNLOAD_OK','path'=>$local];
        }finally{
            if(is_resource($fh)) fclose($fh);
            @ftp_close($conn);
            if(is_file($local)&&(int)@filesize($local)===0) @rename($local,$local.'.failed');
        }
    }

    private function safeRemotePath(string $path): bool
    {
        if($path===''||strlen($path)>500||str_contains($path,"\0")||str_contains($path,'\\')||str_contains($path,'..')) return false;
        return (bool)preg_match('~^/?[A-Za-z0-9_./+\-]+\.zip$~',$path);
    }
}
