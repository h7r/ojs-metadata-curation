<?php

/**
 * @file classes/security/CsrfGuard.php
 *
 * @brief Per-request CSRF + Origin/Referer guard for raw AJAX handlers
 *        that are NOT \PKP\form\Form subclasses (so FormValidatorCSRF
 *        does not apply). Used by SuggestHandler::save and
 *        SuggestHandler::saveContributorIds, which persist metadata that
 *        ships out through OAI-PMH / Crossref.
 *
 *        Read-only endpoints (suggest/orcid/ror) stay exempt: they already
 *        enforce auth + per-context rate limits and only query remote
 *        SPARQL / registry APIs.
 */

namespace APP\plugins\generic\nvMetadataCuration\classes\security;

class CsrfGuard
{
    /**
     * Check session CSRF token and Origin/Referer against the OJS base URL.
     * Sends a 403 JSON error and exits when either check fails.
     *
     * @param \PKP\core\PKPRequest $request
     */
    public static function assertValid($request): void
    {
        // 1. Origin / Referer must match the OJS base URL (defense in depth).
        //    A fabricated X-Csrf-Token from a cross-origin page still fails here.
        $baseUrl = (string) $request->getBaseUrl();
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $source = $origin !== '' ? $origin : $referer;

        if ($source === '' || !self::originMatchesBase($source, $baseUrl)) {
            self::reject('Origin not allowed');
        }

        // 2. Per-session CSRF token, constant-time compared.
        $session = $request->getSession();
        $expected = $session && method_exists($session, 'getCSRFToken')
            ? (string) $session->getCSRFToken()
            : '';

        $submitted = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($submitted === '') {
            $submitted = (string) $request->getUserVar('csrfToken');
        }

        if ($expected === '' || $submitted === '' || !hash_equals($expected, $submitted)) {
            self::reject('Invalid CSRF token');
        }
    }

    /**
     * Pure helper: does $sourceUrl (Origin header value or Referer URL)
     * point at the same scheme + host + port as $baseUrl?
     *
     * Returns false for malformed inputs, missing scheme, missing host,
     * or any component mismatch. Case-insensitive on scheme and host.
     */
    public static function originMatchesBase(string $sourceUrl, string $baseUrl): bool
    {
        $sourceUrl = trim($sourceUrl);
        $baseUrl = trim($baseUrl);
        if ($sourceUrl === '' || $baseUrl === '') {
            return false;
        }

        $src = parse_url($sourceUrl);
        $base = parse_url($baseUrl);
        if (!is_array($src) || !is_array($base)) {
            return false;
        }
        if (empty($src['scheme']) || empty($src['host'])) {
            return false;
        }
        if (empty($base['scheme']) || empty($base['host'])) {
            return false;
        }

        if (strtolower($src['scheme']) !== strtolower($base['scheme'])) {
            return false;
        }
        if (strtolower($src['host']) !== strtolower($base['host'])) {
            return false;
        }
        if (($src['port'] ?? null) !== ($base['port'] ?? null)) {
            return false;
        }

        return true;
    }

    private static function reject(string $message): void
    {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
