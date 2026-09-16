<?php
declare(strict_types=1);

/**
 * Bootstrap-only uploader for a KiCom child cell.
 *
 * Credentials are method arguments only. This class never serializes, logs or
 * returns host credentials. FTPS is preferred; plaintext FTP requires explicit
 * opt-in by the caller.
 */
final class KiComExpansionFtpDeployer
{
    /**
     * @return array<string,mixed>
     */
    public function deploy(
        string $localCellDir,
        string $host,
        int $port,
        string $username,
        string $password,
        string $remoteWebRoot = '/',
        bool $allowPlainFtp = false
    ): array {
        $localCellDir=rtrim($localCellDir,'/');
        if (!is_dir($localCellDir)) return ['ok'=>false,'code'=>'EXPANSION_LOCAL_PACKAGE_MISSING'];
        if (!function_exists('ftp_connect')) return ['ok'=>false,'code'=>'FTP_EXTENSION_MISSING'];
        $host=trim($host);
        if ($host==='' || preg_match('/\s/',$host)) return ['ok'=>false,'code'=>'FTP_HOST_INVALID'];
        $port=$port>0&&$port<=65535?$port:21;
        $root=self::normalizeRoot($remoteWebRoot);
        if ($root===null) return ['ok'=>false,'code'=>'FTP_REMOTE_ROOT_INVALID'];

        $conn=false;
        $transport='ftps';
        if (function_exists('ftp_ssl_connect')) {
            $conn=@ftp_ssl_connect($host,$port,20);
        }
        if ($conn===false && $allowPlainFtp) {
            $transport='ftp';
            $conn=@ftp_connect($host,$port,20);
        }
        if ($conn===false) return ['ok'=>false,'code'=>'FTP_CONNECT_FAILED','ftps_required'=>!$allowPlainFtp];

        try {
            if (!@ftp_login($conn,$username,$password)) return ['ok'=>false,'code'=>'FTP_LOGIN_FAILED'];
            @ftp_pasv($conn,true);

            $target=self::join($root,'kicom');
            if (@ftp_chdir($conn,$target)) {
                @ftp_cdup($conn);
                return ['ok'=>false,'code'=>'EXPANSION_TARGET_EXISTS','remote_directory'=>$target];
            }

            $stage=self::join($root,'.kicom-stage-'.bin2hex(random_bytes(6)));
            $mk=$this->mkdirRecursive($conn,$stage);
            if (!$mk) return ['ok'=>false,'code'=>'FTP_STAGE_CREATE_FAILED'];

            $inventory=$this->inventory($localCellDir);
            if (empty($inventory['ok'])) return $inventory;
            $uploaded=0;
            foreach ($inventory['files'] as $row) {
                $rel=(string)$row['path'];
                $remote=self::join($stage,$rel);
                $parent=dirname($remote);
                if (!$this->mkdirRecursive($conn,$parent)) {
                    return ['ok'=>false,'code'=>'FTP_DIRECTORY_CREATE_FAILED','path'=>$rel,'uploaded'=>$uploaded];
                }
                $fp=@fopen($localCellDir.'/'.$rel,'rb');
                if ($fp===false) return ['ok'=>false,'code'=>'FTP_LOCAL_READ_FAILED','path'=>$rel,'uploaded'=>$uploaded];
                try {
                    if (!@ftp_fput($conn,$remote,$fp,FTP_BINARY)) {
                        return ['ok'=>false,'code'=>'FTP_UPLOAD_FAILED','path'=>$rel,'uploaded'=>$uploaded];
                    }
                } finally {
                    fclose($fp);
                }
                $uploaded++;
            }

            if (!@ftp_rename($conn,$stage,$target)) {
                return ['ok'=>false,'code'=>'FTP_ACTIVATE_RENAME_FAILED','uploaded'=>$uploaded,'stage_directory'=>$stage];
            }

            return [
                'ok'=>true,
                'code'=>'EXPANSION_DEPLOYED',
                'transport'=>$transport,
                'remote_directory'=>$target,
                'files'=>$uploaded,
                'tree_sha256'=>$inventory['tree_sha256'],
            ];
        } finally {
            if (is_object($conn) || is_resource($conn)) @ftp_close($conn);
            // $username/$password remain transient stack values and are never persisted.
        }
    }

    /** @return array{ok:bool,code:string,files?:array<int,array<string,mixed>>,tree_sha256?:string} */
    private function inventory(string $root): array
    {
        $files=[];
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) continue;
            $full=$file->getPathname();
            $rel=ltrim(str_replace('\\','/',substr($full,strlen($root))),'/');
            if (!preg_match('/^[A-Za-z0-9_.\/-]{1,240}$/',$rel) || str_contains('/'.$rel.'/','/../')) {
                return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_PATH_INVALID'];
            }
            $size=$file->getSize();
            if ($size<0 || $size>8*1024*1024) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_FILE_TOO_LARGE'];
            $files[]=['path'=>$rel,'bytes'=>$size,'sha256'=>hash_file('sha256',$full)?:''];
        }
        usort($files,static fn(array $a,array $b): int=>strcmp((string)$a['path'],(string)$b['path']));
        $tree='';
        foreach ($files as $row) $tree.=$row['sha256'].'  '.$row['path']."\n";
        return ['ok'=>true,'code'=>'EXPANSION_PACKAGE_READY','files'=>$files,'tree_sha256'=>hash('sha256',$tree)];
    }

    /** @param mixed $conn */
    private function mkdirRecursive($conn,string $path): bool
    {
        $path=self::normalizeRoot($path);
        if ($path===null) return false;
        if ($path==='/') return true;
        $current='';
        foreach (explode('/',trim($path,'/')) as $part) {
            $current.='/'.$part;
            if (@ftp_chdir($conn,$current)) { @ftp_chdir($conn,'/'); continue; }
            if (@ftp_mkdir($conn,$current)===false && !@ftp_chdir($conn,$current)) return false;
            @ftp_chdir($conn,'/');
        }
        return true;
    }

    private static function normalizeRoot(string $path): ?string
    {
        $path=trim(str_replace('\\','/',$path));
        if ($path==='') $path='/';
        if ($path[0]!=='/') $path='/'.$path;
        $out=[];
        foreach (explode('/',$path) as $part) {
            if ($part===''||$part==='.') continue;
            if ($part==='..'||str_contains($part,"\0")||!preg_match('/^[A-Za-z0-9_.-]+$/',$part)) return null;
            $out[]=$part;
        }
        return '/'.implode('/',$out);
    }

    private static function join(string $base,string $child): string
    {
        return rtrim($base,'/').'/'.ltrim($child,'/');
    }
}
