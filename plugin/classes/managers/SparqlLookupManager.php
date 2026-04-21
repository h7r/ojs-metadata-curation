<?php

/**
 * @file classes/managers/SparqlLookupManager.php
 *
 * Copyright (c) 2026 Ne Varietur
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief SPARQL lookup against SKOS thesauri (UNESCO Thesaurus, Rameau/BnF).
 *        Implements the suggest interface defined in SPECS.md sections 3-5.
 */

namespace APP\plugins\generic\nvMetadataCuration\classes\managers;

class SparqlLookupManager
{
    /** @var int HTTP timeout in seconds (SPECS.md section 5.3) */
    private const TIMEOUT_SECONDS = 2;

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
    ];

    /**
     * Lookup SKOS concepts matching a prefix.
     *
     * @param string $prefix  User input (>= 3 chars, will be sanitised)
     * @param string $lang    Language code ('es' | 'fr')
     * @param string $thesaurus  Thesaurus id ('unesco' | 'rameau')
     * @return array Structured response per SPECS.md section 4.2
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
     * Sanitise user prefix before SPARQL injection (SPECS.md section 5.2).
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

        return $this->buildRameauQuery($escaped);
    }

    /**
     * UNESCO Thesaurus query (SPECS.md section 3.1).
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
     * Rameau/BnF query (SPECS.md section 3.2).
     * French only. Requires GRAPH and inScheme filters to isolate Rameau
     * from the 650M+ triple BnF LOD graph.
     */
    private function buildRameauQuery(string $prefix): string
    {
        return <<<SPARQL
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

SELECT DISTINCT ?concept ?label ?altLabel ?broader ?broaderLabel ?scopeNote
WHERE {
  GRAPH <http://data.bnf.fr/> {
    ?concept a skos:Concept ;
             skos:inScheme <http://rameau.bnf.fr/> ;
             skos:prefLabel ?label .

    OPTIONAL { ?concept skos:altLabel ?altLabel . FILTER(lang(?altLabel) = "fr") }
    OPTIONAL {
      ?concept skos:broader ?broader .
      ?broader skos:prefLabel ?broaderLabel .
      FILTER(lang(?broaderLabel) = "fr")
    }
    OPTIONAL {
      ?concept skos:scopeNote ?scopeNote .
      FILTER(lang(?scopeNote) = "fr")
    }

    FILTER(lang(?label) = "fr")
    FILTER(strstarts(lcase(str(?label)), "{$prefix}"))
  }
}
ORDER BY ?label
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

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'header' => "Accept: application/sparql-results+json\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            error_log('[nvMetadataCuration] SPARQL request failed: ' . $endpoint);
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
     * Parse SPARQL JSON results into the normalised response format (SPECS.md section 4.2).
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
                    'scope_note' => $row['scopeNote']['value'] ?? null,
                ];
            }
        }

        return $results;
    }
}
