<?php

/**
 * @file classes/managers/OrcidRorManager.php
 *
 * Copyright (c) 2026 Ne Varietur
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief ORCID and ROR lookup for contributor metadata.
 *        Uses the public ORCID API and the ROR REST API.
 *        C1b: lookup only — all results require human validation.
 */

namespace APP\plugins\generic\nvMetadataCuration\classes\managers;

class OrcidRorManager
{
    private const ORCID_API = 'https://pub.orcid.org/v3.0';
    private const ROR_API = 'https://api.ror.org/v2/organizations';
    private const TIMEOUT_SECONDS = 3;
    private const RESULT_LIMIT = 5;

    /**
     * Search ORCID by author name.
     *
     * @return array{query: string, results: array, error: ?string}
     */
    public function searchOrcid(string $query): array
    {
        $response = ['query' => $query, 'results' => [], 'error' => null];
        $query = trim($query);
        if (mb_strlen($query, 'UTF-8') < 2) {
            return $response;
        }

        $url = self::ORCID_API . '/expanded-search/?' . http_build_query([
            'q' => $query,
            'rows' => self::RESULT_LIMIT,
        ]);

        $body = $this->httpGet($url, ['Accept: application/json']);
        if ($body === null) {
            $response['error'] = 'timeout';
            return $response;
        }

        $data = json_decode($body, true);
        $results = $data['expanded-result'] ?? [];

        foreach ($results as $item) {
            $orcidId = $item['orcid-id'] ?? '';
            $givenNames = $item['given-names'] ?? '';
            $familyName = $item['family-names'] ?? '';
            $institutions = $item['institution-name'] ?? [];

            $response['results'][] = [
                'orcid' => $orcidId,
                'orcid_uri' => 'https://orcid.org/' . $orcidId,
                'given_names' => $givenNames,
                'family_name' => $familyName,
                'display_name' => trim("{$givenNames} {$familyName}"),
                'institutions' => is_array($institutions) ? array_slice($institutions, 0, 3) : [],
                'validated' => false,
            ];
        }

        return $response;
    }

    /**
     * Search ROR for institutional affiliations.
     *
     * @return array{query: string, results: array, error: ?string}
     */
    public function searchRor(string $query): array
    {
        $response = ['query' => $query, 'results' => [], 'error' => null];
        $query = trim($query);
        if (mb_strlen($query, 'UTF-8') < 2) {
            return $response;
        }

        $url = self::ROR_API . '?' . http_build_query([
            'query' => $query,
        ]);

        $body = $this->httpGet($url, ['Accept: application/json']);
        if ($body === null) {
            $response['error'] = 'timeout';
            return $response;
        }

        $data = json_decode($body, true);
        $items = $data['items'] ?? [];

        foreach (array_slice($items, 0, self::RESULT_LIMIT) as $org) {
            $names = $org['names'] ?? [];
            $displayName = '';
            $country = $org['locations'][0]['geonames_details']['country_name'] ?? '';

            foreach ($names as $n) {
                if (in_array('ror_display', $n['types'] ?? [], true)) {
                    $displayName = $n['value'];
                    break;
                }
            }
            if (!$displayName && !empty($names)) {
                $displayName = $names[0]['value'] ?? '';
            }

            $response['results'][] = [
                'ror_id' => $org['id'] ?? '',
                'name' => $displayName,
                'country' => $country,
                'types' => $org['types'] ?? [],
                'validated' => false,
            ];
        }

        return $response;
    }

    /**
     * Validate ORCID format with ISO 7064 Mod 11-2 checksum.
     *
     * @param string $orcid bare ORCID (e.g. "0000-0002-1825-0097")
     */
    public static function isValidOrcidFormat(string $orcid): bool
    {
        if (!preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $orcid)) {
            return false;
        }

        $digits = str_replace('-', '', $orcid);
        $total = 0;
        for ($i = 0; $i < 15; $i++) {
            $total = ($total + (int) $digits[$i]) * 2;
        }
        $remainder = $total % 11;
        $checkDigit = (12 - $remainder) % 11;
        $expected = $checkDigit === 10 ? 'X' : (string) $checkDigit;

        return $digits[15] === $expected;
    }

    /**
     * Validate ROR identifier format.
     *
     * @param string $rorId full ROR URL (e.g. "https://ror.org/03yrm5c26")
     */
    public static function isValidRorFormat(string $rorId): bool
    {
        return (bool) preg_match('#^https://ror\.org/0[a-z0-9]{6}[0-9]{2}$#', $rorId);
    }

    /**
     * Extract bare ORCID from a URI or bare string.
     * Returns null if input is not recognizable.
     */
    public static function extractOrcid(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/', $input, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * HTTP GET with timeout.
     */
    private function httpGet(string $url, array $headers = []): ?string
    {
        $headerStr = implode("\r\n", $headers) . "\r\n";
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'header' => $headerStr,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            error_log('[nvMetadataCuration] HTTP GET failed: ' . $url);
            return null;
        }

        return $body;
    }
}
