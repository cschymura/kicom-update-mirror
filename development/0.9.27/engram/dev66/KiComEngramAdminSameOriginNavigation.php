<?php
declare(strict_types=1);

/**
 * A narrow compatibility guard for the original admin's HTML form navigation.
 * Safari may send literal Origin: null for same-origin document POSTs.
 * This fallback is NEVER used for API, OAuth or WebAuthn JSON requests.
 * The caller must still check the original admin session, exact route,
 * HTTPS, exact form fields, CSRF and the action-specific confirmation.
 */
final class KiComEngramAdminSameOriginNavigation
{
    public static function permits(array $server): bool
    {
        if (($server['HTTPS']??null)!=='on'
            || ($server['HTTP_HOST']??null)!=='kicom.rurtalbahn.info'
            || ($server['REQUEST_METHOD']??null)!=='POST')return false;
        $origin=$server['HTTP_ORIGIN']??null;
        if ($origin==='https://kicom.rurtalbahn.info')return true;
        // Any explicit different origin is rejected. For absent/null Origin,
        // allow ONLY an unambiguously same-origin browser form navigation.
        if ($origin!==null && $origin!=='' && $origin!=='null')return false;
        return ($server['HTTP_SEC_FETCH_SITE']??null)==='same-origin'
            && ($server['HTTP_SEC_FETCH_MODE']??null)==='navigate'
            && ($server['HTTP_SEC_FETCH_DEST']??null)==='document';
    }
}
