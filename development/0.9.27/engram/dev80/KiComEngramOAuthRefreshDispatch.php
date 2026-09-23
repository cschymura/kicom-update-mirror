<?php
declare(strict_types=1);
require_once __DIR__.'/../dev79/KiComEngramOAuthGrantForm.php';

/**
 * DEV-80: Dispatch validated OAuth token grants AFTER the original native
 * KiComEngramOAuthHttp::token HTTPS/host/method/content-type/origin gate.
 *
 * Server trustedClient comes only from original approved runtime configuration.
 * Never derive any of these bindings from POST params or request headers.
 *
 * Does NOT mint initial refresh during the authorization_code transaction;
 * that must be integrated atomically before publishing refresh grant discovery.
 */
final class KiComEngramOAuthRefreshDispatch
{
    public static function afterOriginalHttpGate(
        PDO $privateOAuthDb, string $rawForm, array $trustedClient, int $now
    ):array {
        self::trusted($trustedClient);
        $grant=KiComEngramOAuthGrantForm::parse($rawForm,$trustedClient);
        if($grant['kind']==='authorization_code'){
            return KiComEngramOAuthTransactions::exchange(
                $privateOAuthDb,$grant['arguments'],$trustedClient,$now
            );
        }
        if($grant['kind']!=='refresh_token'){
            throw new RuntimeException('UNSUPPORTED_OAUTH_GRANT');
        }
        return KiComEngramOAuthContinuity::rotate(
            $privateOAuthDb,$grant['arguments']['refresh_token'],[
                'client_id'=>$trustedClient['client_id'],
                'connector_id'=>$trustedClient['connector_id'],
                'host_evidence_id'=>$trustedClient['host_evidence_id'],
            ],$now
        );
    }
    private static function trusted(array $c):void {
        foreach(['client_id','connector_id','host_evidence_id','redirect_uri'] as $key){
            if(!isset($c[$key])||!is_string($c[$key])||$c[$key]===''){
                throw new RuntimeException('MISSING_TRUSTED_CLIENT_BINDING');
            }
        }
    }
}
