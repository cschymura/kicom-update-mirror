<?php
declare(strict_types=1);

/**
 * Narrow adapter from the transport bus to the established KiCom self-update
 * receiver. It does not duplicate package validation, risk classification,
 * snapshots, install, health checks or rollback.
 */
final class KiComArtifactRuntimeBridge
{
    /** @return array<string,mixed> */
    public static function status(): array
    {
        return [
            'ok'=>function_exists('kicomReceiveSelfUpdatePackage'),
            'code'=>function_exists('kicomReceiveSelfUpdatePackage')?'ARTIFACT_KICOM_BRIDGE_READY':'ARTIFACT_KICOM_RECEIVER_MISSING',
            'receiver'=>'existing-kicom-self-update',
            'duplicates_installer'=>false,
        ];
    }

    /**
     * Return the callback expected by KiComArtifactTransportBus.
     * @return Closure(string,string,string,bool):array<string,mixed>
     */
    public static function receiver(): Closure
    {
        return static function(string $path,string $originalName,string $source,bool $allowAuto): array {
            if(!function_exists('kicomReceiveSelfUpdatePackage')) return ['ok'=>false,'code'=>'ARTIFACT_KICOM_RECEIVER_MISSING'];
            if(!is_file($path)||!is_readable($path)) return ['ok'=>false,'code'=>'ARTIFACT_KICOM_INPUT_MISSING'];
            if(!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+\-]{0,159}\.zip$/i',$originalName)) return ['ok'=>false,'code'=>'ARTIFACT_KICOM_NAME_INVALID'];
            $source='artifact-transport:'.substr(preg_replace('/[^A-Za-z0-9_.:\-]/','_',trim($source))??'unknown',0,120);
            try{
                $result=kicomReceiveSelfUpdatePackage($path,$originalName,$source,$allowAuto);
            }catch(Throwable $e){
                return ['ok'=>false,'code'=>'ARTIFACT_KICOM_RECEIVER_EXCEPTION'];
            }
            return is_array($result)?$result:['ok'=>false,'code'=>'ARTIFACT_KICOM_RECEIVER_INVALID'];
        };
    }
}
