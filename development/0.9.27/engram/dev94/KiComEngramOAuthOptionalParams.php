<?php
declare(strict_types=1);

/**
 * DEV-78: Normalize ONLY the non-security ui_locales display hint at KiCom's
 * authenticated first-party OAuth authorization GET boundary.
 *
 * Call after the original admin-session checks, immediately before the
 * original KiComEngramOAuthTransactions::begin() strict-key validation.
 * NEVER use for token requests or callback/redirect construction.
 * The original validator must still reject any other unknown parameter.
 */
final class KiComEngramOAuthOptionalParams
{
    public static function authorizationQuery(array $query): array
    {
        if (array_key_exists('ui_locales', $query)) {
            $value = $query['ui_locales'];
            if (!is_string($value) || strlen($value) > 128 ||
                !preg_match('/\A[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*(?: [A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*)*\z/D', $value)) {
                throw new RuntimeException('UNSUPPORTED_OAUTH_UI_LOCALES');
            }
            unset($query['ui_locales']);
        }
        return $query;
    }
}
