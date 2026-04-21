<?php

/**
 * @file classes/handlers/SuggestHandler.php
 *
 * Copyright (c) 2026 Ne Varietur
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Handles /suggest AJAX requests from the keyword autocomplete widget.
 *        Validates input, delegates to SparqlLookupManager, returns JSON.
 */

namespace APP\plugins\generic\nvMetadataCuration\classes\handlers;

use APP\facades\Repo;
use APP\plugins\generic\nvMetadataCuration\classes\managers\SparqlLookupManager;
use PKP\db\DAORegistry;
use PKP\handler\PKPHandler;
use PKP\security\authorization\ContextRequiredPolicy;

class SuggestHandler extends PKPHandler
{
    /** @var string[] Allowed thesaurus identifiers */
    private const ALLOWED_THESAURI = ['unesco', 'rameau'];

    /** @var string[] Allowed language codes */
    private const ALLOWED_LANGS = ['es', 'fr', 'en'];

    /** @var int Minimum query length */
    private const MIN_QUERY_LENGTH = 3;

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments): bool
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Handle the suggest request.
     *
     * @param array $args URL path arguments (unused)
     * @param \PKP\core\PKPRequest $request
     * @return \PKP\core\JSONMessage
     */
    public function suggest($args, $request)
    {
        $q = trim((string) $request->getUserVar('q'));
        $lang = trim((string) $request->getUserVar('lang'));
        $thesaurus = trim((string) $request->getUserVar('thesaurus'));

        // Defaults
        if ($lang === '' || !in_array($lang, self::ALLOWED_LANGS, true)) {
            $lang = 'es';
        }
        if ($thesaurus === '' || !in_array($thesaurus, self::ALLOWED_THESAURI, true)) {
            $thesaurus = 'unesco';
        }

        // Validate minimum query length
        if (mb_strlen($q, 'UTF-8') < self::MIN_QUERY_LENGTH) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'query' => $q,
                'thesaurus' => $thesaurus,
                'lang' => $lang,
                'fallback' => false,
                'results' => [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $manager = new SparqlLookupManager();
        $result = $manager->suggest($q, $lang, $thesaurus);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Save selected SKOS keywords to submission_settings.
     * Stores JSON array under key 'nvKeywords' (SPECS.md section 6.3).
     *
     * Expects POST with:
     *   - submissionId (int)
     *   - keywords (JSON string — array of keyword objects)
     */
    public function save($args, $request)
    {
        $submissionId = (int) $request->getUserVar('submissionId');
        $keywordsRaw = (string) $request->getUserVar('keywords');

        if ($submissionId <= 0) {
            $this->sendJsonError('Missing or invalid submissionId');
        }

        $keywords = json_decode($keywordsRaw, true);
        if (!is_array($keywords)) {
            $this->sendJsonError('Invalid keywords JSON');
        }

        // Validate each keyword entry
        $validated = [];
        foreach ($keywords as $kwd) {
            if (empty($kwd['kwd_uri']) || empty($kwd['kwd_value'])) {
                continue;
            }
            $validated[] = [
                'kwd_value' => (string) $kwd['kwd_value'],
                'kwd_uri' => (string) $kwd['kwd_uri'],
                'kwd_lang' => (string) ($kwd['kwd_lang'] ?? 'es'),
                'kwd_thesaurus' => (string) ($kwd['kwd_thesaurus'] ?? 'unesco'),
                'kwd_validated' => true,
            ];
        }

        // Store in submission_settings via DAO
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $submission = $submissionDao->getById($submissionId);

        if (!$submission) {
            $this->sendJsonError('Submission not found');
        }

        $submission->setData('nvKeywords', json_encode($validated, JSON_UNESCAPED_UNICODE));
        $submissionDao->updateObject($submission);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'ok',
            'submissionId' => $submissionId,
            'savedCount' => count($validated),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send a JSON error response and exit.
     */
    private function sendJsonError(string $message, int $httpCode = 400): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
