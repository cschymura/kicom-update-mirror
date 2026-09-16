<?php
declare(strict_types=1);

/** Bounded diagnostics for the DEV router; no arbitrary path parameter exists. */
final class KiComDevDiagnostics
{
    /** @return array<string,Closure> */
    public static function handlers(): array
    {
        return ['DEV_LOG_READ'=>Closure::fromCallable([self::class,'read'])];
    }

    public static function read(array $payload,array $auth): array
    {
        $stream=strtolower(trim((string)($payload['stream']??'dev_audit')));
        $limit=max(1,min(200,(int)($payload['limit']??50)));

        if ($stream==='living_experience') {
            if (!function_exists('kicomLivingExperience')) return ['ok'=>false,'code'=>'DEV_LOG_STREAM_UNAVAILABLE'];
            $row=kicomLivingExperience();
            return ['ok'=>true,'code'=>'DEV_LOG_LIVING_EXPERIENCE','stream'=>$stream,'data'=>$row];
        }
        if ($stream!=='dev_audit') return ['ok'=>false,'code'=>'DEV_LOG_STREAM_FORBIDDEN'];
        if (!function_exists('kicomVarDir')) return ['ok'=>false,'code'=>'DEV_LOG_STREAM_UNAVAILABLE'];

        $file=rtrim((string)kicomVarDir(),'/').'/dev_zone/sessions/audit.jsonl';
        if (!is_file($file)) return ['ok'=>true,'code'=>'DEV_LOG_AUDIT','stream'=>$stream,'entries'=>[]];
        $raw=@file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw)) return ['ok'=>false,'code'=>'DEV_LOG_READ_FAILED'];
        $entries=[];
        foreach (array_slice($raw,-$limit) as $line) {
            $j=json_decode((string)$line,true);
            if (is_array($j)) $entries[]=$j;
        }
        return ['ok'=>true,'code'=>'DEV_LOG_AUDIT','stream'=>$stream,'entries'=>$entries];
    }
}
