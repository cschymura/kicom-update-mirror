<?php
declare(strict_types=1);

/**
 * DEV-61: separate the operator's acceptance of the KNOWN shared-host risk
 * from a claim that PHP UID/vhost isolation has actually been verified.
 *
 * Caller must load $runtime from the existing original KiCom PRIVATE host
 * config loader, never HTTP, MCP, OAuth request data, Slack or GitHub.
 * This is policy evaluation only. It does NOT activate memory, assert an
 * independent security boundary, issue tokens or skip owner/passkey checks.
 */
final class KiComEngramHostingPolicy
{
    public const SHARED_MODE='shared-host-explicit-operator-acceptance/v1';

    public static function permits(array $runtime): bool
    {
        // Preserve already-reviewed, truly isolated deployments. Contradictory
        // "isolated AND shared risk" configurations are rejected.
        if (($runtime['host_isolation_verified']??null)===true) {
            return ($runtime['operator_accepts_shared_host_risk']??false)===false
                && !isset($runtime['hosting_policy_mode']);
        }

        // An explicitly accepted hosting model is NOT an isolation attestation.
        if (($runtime['host_isolation_verified']??null)!==false
            || ($runtime['operator_accepts_shared_host_risk']??null)!==true
            || ($runtime['hosting_policy_mode']??null)!==self::SHARED_MODE
            || ($runtime['hosting_policy_source']??null)!=='protected-operator-host-config'
            || ($runtime['hosting_policy_owner']??null)!=='mirage-owner'
            || ($runtime['hosting_policy_known_limitation']??null)!=='shared-php-uid-not-verified'
            || ($runtime['runtime_source']??null)!=='server-only-reviewed')
            return false;

        $ack=$runtime['hosting_policy_acknowledged_at_utc']??null;
        if (!is_string($ack)
            || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$ack))
            return false;
        $date=DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',$ack,new DateTimeZone('UTC')
        );
        if (!$date || $date->format('Y-m-d\TH:i:s\Z')!==$ack)
            return false;
        $evidence=$runtime['host_evidence_id']??null;
        return is_string($evidence)
            && preg_match('/\A[a-f0-9]{64}\z/D',$evidence)===1;
    }

    /** Displays the actual distinction; never describes shared as isolated. */
    public static function mode(array $runtime): string
    {
        if (!self::permits($runtime)) return 'hosting_policy_unapproved';
        return ($runtime['host_isolation_verified']??null)===true
            ? 'host_isolation_verified'
            : 'shared_host_risk_explicitly_accepted_not_isolated';
    }
}
