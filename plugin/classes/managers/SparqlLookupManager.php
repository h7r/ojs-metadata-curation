<?php

/**
 * @file classes/managers/SparqlLookupManager.php
 *
 * Copyright (c) 2026 Ne Varietur
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief SPARQL lookup against controlled-vocabulary thesauri (UNESCO, Rameau/BnF, Eurovoc).
 */

namespace APP\plugins\generic\nvMetadataCuration\classes\managers;

class SparqlLookupManager
{
    /** @var int Default HTTP timeout in seconds */
    private const TIMEOUT_SECONDS = 2;

    /** @var int Higher timeout for BnF/Rameau — Virtuoso endpoint is slower */
    private const TIMEOUT_SECONDS_BNF = 8;

    /** @var int Minimum prefix length before querying */
    private const MIN_PREFIX_LENGTH = 3;

    /** @var int Max prefix length for sanitisation */
    private const MAX_PREFIX_LENGTH = 50;

    /** @var int SPARQL LIMIT clause */
    private const RESULT_LIMIT = 5;

    /**
     * SPARQL endpoint URLs per thesaurus.
     * @var array<string, string>
     */
    private const ENDPOINTS = [
        'unesco' => 'https://vocabularies.unesco.org/sparql',
        'rameau' => 'https://data.bnf.fr/sparql',
        'eurovoc' => 'https://publications.europa.eu/webapi/rdf/sparql',
    ];

    /**
     * Lookup thesaurus concepts matching a prefix.
     *
     * @param string $prefix  User input (>= 3 chars, will be sanitised)
     * @param string $lang    Language code ('es' | 'fr')
     * @param string $thesaurus  Thesaurus id ('unesco' | 'rameau' | 'eurovoc')
     * @return array Structured response with candidate concepts.
     */
    public function suggest(string $prefix, string $lang, string $thesaurus): array
    {
        $response = [
            'query' => $prefix,
            'thesaurus' => $thesaurus,
            'lang' => $lang,
            'fallback' => false,
            'results' => [],
        ];

        $sanitized = $this->sanitizePrefix($prefix);
        if (mb_strlen($sanitized, 'UTF-8') < self::MIN_PREFIX_LENGTH) {
            return $response;
        }

        if (!isset(self::ENDPOINTS[$thesaurus])) {
            $response['fallback'] = true;
            $response['fallback_reason'] = 'unknown_thesaurus';
            return $response;
        }

        $sparql = $this->buildQuery($sanitized, $lang, $thesaurus);
        $endpoint = self::ENDPOINTS[$thesaurus];

        $raw = $this->executeQuery($endpoint, $sparql);
        if ($raw === null) {
            $response['fallback'] = true;
            $response['fallback_reason'] = 'timeout';
            return $response;
        }

        $response['results'] = $this->parseResults($raw, $lang, $thesaurus);
        return $response;
    }

    /**
     * Sanitise user prefix before SPARQL injection.
     * SPARQL has no positional parameters -- manual sanitisation is required.
     */
    private function sanitizePrefix(string $prefix): string
    {
        $prefix = mb_strtolower(trim($prefix), 'UTF-8');
        $prefix = preg_replace('/[<>"{}|\\\\^`\[\]]/', '', $prefix);
        $prefix = mb_substr($prefix, 0, self::MAX_PREFIX_LENGTH, 'UTF-8');
        return $prefix;
    }

    /**
     * Build the SPARQL query for the given thesaurus.
     */
    private function buildQuery(string $sanitizedPrefix, string $lang, string $thesaurus): string
    {
        // Escape single quotes for SPARQL string literal
        $escaped = str_replace("'", "\\'", $sanitizedPrefix);

        if ($thesaurus === 'unesco') {
            return $this->buildUnescoQuery($escaped, $lang);
        }
        if ($thesaurus === 'eurovoc') {
            return $this->buildEurovocQuery($escaped, $lang);
        }

        return $this->buildRameauQuery($escaped);
    }

    /**
     * UNESCO Thesaurus query.
     * Bilingual: primary label in $lang, translation in EN.
     */
    private function buildUnescoQuery(string $prefix, string $lang): string
    {
        return <<<SPARQL
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>

SELECT DISTINCT ?concept ?labelPrimary ?labelEN ?notation ?broader ?broaderLabel ?scopeNote
WHERE {
  ?concept a skos:Concept ;
           skos:prefLabel ?labelPrimary ;
           skos:prefLabel ?labelEN .

  OPTIONAL {
    ?concept skos:notation ?notation .
  }
  OPTIONAL {
    ?concept skos:broader ?broader .
    ?broader skos:prefLabel ?broaderLabel .
    FILTER(lang(?broaderLabel) = "{$lang}")
  }
  OPTIONAL {
    ?concept skos:scopeNote ?scopeNote .
    FILTER(lang(?scopeNote) = "{$lang}")
  }

  FILTER(lang(?labelPrimary) = "{$lang}")
  FILTER(lang(?labelEN) = "en")
  FILTER(strstarts(lcase(str(?labelPrimary)), "{$prefix}"))
}
ORDER BY ?labelPrimary
LIMIT 5
SPARQL;
    }

    /**
     * Rameau/BnF query.
     * French only. Uses bif:contains (Virtuoso full-text index) because
     * strstarts() does not work on lang-tagged literals in BnF's Virtuoso.
     * Correct scheme URI: http://data.bnf.fr/vocabulary/rameau
     * scopeNote OPTIONAL dropped — causes timeout on this endpoint.
     */
    private function buildRameauQuery(string $prefix): string
    {
        return <<<SPARQL
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
PREFIX bif: <bif:>

SELECT DISTINCT ?concept ?label ?altLabel ?broader ?broaderLabel
WHERE {
  ?concept a skos:Concept ;
           skos:inScheme <http://data.bnf.fr/vocabulary/rameau> ;
           skos:prefLabel ?label .
  ?label bif:contains "\"{$prefix}*\"" .
  FILTER(lang(?label) = "fr")

  OPTIONAL { ?concept skos:altLabel ?altLabel . FILTER(lang(?altLabel) = "fr") }
  OPTIONAL {
    ?concept skos:broader ?broader .
    ?broader skos:prefLabel ?broaderLabel .
    FILTER(lang(?broaderLabel) = "fr")
  }
}
ORDER BY ?label
LIMIT 5
SPARQL;
    }

    /**
     * Eurovoc query (EU Publications Office).
     * Multilingual: primary label in $lang, English translation.
     */
    private function buildEurovocQuery(string $prefix, string $lang): string
    {
        return <<<SPARQL
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
PREFIX dc: <http://purl.org/dc/elements/1.1/>
PREFIX ev: <http://eurovoc.europa.eu/>

SELECT DISTINCT ?concept ?labelPrimary ?labelEN ?broader ?broaderLabel ?scopeNote
WHERE {
  ?concept a skos:Concept ;
           skos:inScheme <http://eurovoc.europa.eu/100141> ;
           skos:prefLabel ?labelPrimary .

  OPTIONAL {
    ?concept skos:prefLabel ?labelEN .
    FILTER(lang(?labelEN) = "en")
  }
  OPTIONAL {
    ?concept skos:broader ?broader .
    ?broader skos:prefLabel ?broaderLabel .
    FILTER(lang(?broaderLabel) = "{$lang}")
  }
  OPTIONAL {
    ?concept skos:scopeNote ?scopeNote .
    FILTER(lang(?scopeNote) = "{$lang}")
  }

  FILTER(lang(?labelPrimary) = "{$lang}")
  FILTER(strstarts(lcase(str(?labelPrimary)), "{$prefix}"))
}
ORDER BY ?labelPrimary
LIMIT 5
SPARQL;
    }

    /**
     * Execute SPARQL query via HTTP GET and return decoded JSON, or null on failure.
     */
    private function executeQuery(string $endpoint, string $sparql): ?array
    {
        $url = $endpoint . '?' . http_build_query([
            'query' => $sparql,
            'format' => 'application/sparql-results+json',
        ]);

        // BnF Virtuoso endpoint is slower — use dedicated timeout
        $timeout = ($endpoint === self::ENDPOINTS['rameau'])
            ? self::TIMEOUT_SECONDS_BNF
            : self::TIMEOUT_SECONDS;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => "Accept: application/sparql-results+json\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            error_log('[nvMetadataCuration] SPARQL request failed: ' . $endpoint);
            return null;
        }

        if (!self::isHttpSuccess($http_response_header ?? [])) {
            $lastStatus = '';
            foreach (($http_response_header ?? []) as $h) {
                if (stripos($h, 'HTTP/') === 0) { $lastStatus = $h; }
            }
            error_log('[nvMetadataCuration] SPARQL HTTP error: ' . ($lastStatus ?: 'unknown') . ' — ' . $endpoint);
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['results']['bindings'])) {
            error_log('[nvMetadataCuration] SPARQL response malformed: ' . $endpoint);
            return null;
        }

        return $decoded;
    }

    /**
     * Parse SPARQL JSON results into the normalised response format.
     *
     * @return array<int, array>
     */
    private function parseResults(array $raw, string $lang, string $thesaurus): array
    {
        $bindings = $raw['results']['bindings'] ?? [];
        $results = [];

        foreach ($bindings as $row) {
            if ($thesaurus === 'unesco') {
                $results[] = [
                    'uri' => $row['concept']['value'] ?? '',
                    'label_primary' => $row['labelPrimary']['value'] ?? '',
                    'label_translation' => $row['labelEN']['value'] ?? '',
                    'lang_primary' => $lang,
                    'lang_translation' => 'en',
                    'notation' => $row['notation']['value'] ?? null,
                    'broader_uri' => $row['broader']['value'] ?? null,
                    'broader_label' => $row['broaderLabel']['value'] ?? null,
                    'scope_note' => $row['scopeNote']['value'] ?? null,
                ];
            } elseif ($thesaurus === 'eurovoc') {
                $results[] = [
                    'uri' => $row['concept']['value'] ?? '',
                    'label_primary' => $row['labelPrimary']['value'] ?? '',
                    'label_translation' => $row['labelEN']['value'] ?? '',
                    'lang_primary' => $lang,
                    'lang_translation' => 'en',
                    'notation' => null,
                    'broader_uri' => $row['broader']['value'] ?? null,
                    'broader_label' => $row['broaderLabel']['value'] ?? null,
                    'scope_note' => $row['scopeNote']['value'] ?? null,
                ];
            } else {
                // Rameau: French only, no translation label available
                $results[] = [
                    'uri' => $row['concept']['value'] ?? '',
                    'label_primary' => $row['label']['value'] ?? '',
                    'label_translation' => $row['altLabel']['value'] ?? '',
                    'lang_primary' => 'fr',
                    'lang_translation' => 'fr',
                    'notation' => null,
                    'broader_uri' => $row['broader']['value'] ?? null,
                    'broader_label' => $row['broaderLabel']['value'] ?? null,
                    'scope_note' => null, // scopeNote dropped from Rameau query (timeout)
                ];
            }
        }

        return $results;
    }

    /**
     * Check whether the HTTP response status line indicates a 2xx success.
     *
     * When file_get_contents follows redirects, $http_response_header
     * accumulates all status lines (e.g. 301 then 200). We check the
     * last HTTP/ status line — the final response.
     */
    private static function isHttpSuccess(array $headers): bool
    {
        $statusLine = '';
        foreach ($headers as $h) {
            if (stripos($h, 'HTTP/') === 0) {
                $statusLine = $h;
            }
        }
        if ($statusLine === '') {
            return false;
        }
        return (bool) preg_match('/\bHTTP\/[\d.]+ 2\d{2}\b/', $statusLine);
    }
}
