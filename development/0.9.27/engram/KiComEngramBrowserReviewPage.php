<?php
declare(strict_types=1);

/**
 * DEV-only HTML decorator for the already authenticated one-record review
 * returned by KiComEngramWebAuthnApprovalController::render(). Does not issue
 * approval, authenticate users or register any production routes.
 *
 * The trusted KiCom host must supply FIXED same-origin paths, serve the JS
 * file from its own static asset path, and set HTTPS/no-store/CSP
 * (script-src 'self'; no third-party script and no cross-origin API).
 */
final class KiComEngramBrowserReviewPage
{
    public static function decorate(
        string $serverRenderedReviewHtml,
        string $trustedJsonApiPath,
        string $trustedClientScriptPath
    ): string {
        if (preg_match('#\\A/(?:[a-z0-9_-]+/)*[a-z0-9_-]+\\.php\\z#D',
            $trustedJsonApiPath)!==1
            || preg_match('#\\A/(?:[a-z0-9_-]+/)*[a-z0-9_-]+\\.js\\z#D',
                $trustedClientScriptPath)!==1) {
            throw new RuntimeException('ENGRAM_REVIEW_ASSET_PATH_INVALID');
        }
        $needle='<form method="post" autocomplete="off">';
        if (substr_count($serverRenderedReviewHtml,$needle)!==1
            || substr_count($serverRenderedReviewHtml,'</main>')!==1
            || substr_count($serverRenderedReviewHtml,'</body>')!==1) {
            throw new RuntimeException('ENGRAM_REVIEW_TEMPLATE_INVALID');
        }
        $api=htmlspecialchars($trustedJsonApiPath,ENT_QUOTES,'UTF-8');
        $script=htmlspecialchars($trustedClientScriptPath,ENT_QUOTES,'UTF-8');
        $html=str_replace(
            $needle,
            '<form method="post" autocomplete="off" data-engram-review-api="'.$api.'">',
            $serverRenderedReviewHtml
        );
        $html=str_replace(
            '</main>',
            '<p id="engram-review-status" role="status" aria-live="polite"></p>'
                .'<noscript>Für die Passkey-Freigabe ist JavaScript erforderlich.</noscript>'
                .'</main>',
            $html
        );
        return str_replace(
            '</body>',
            '<script defer src="'.$script.'"></script></body>',
            $html
        );
    }
}
