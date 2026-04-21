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
use APP\plugins\generic\nvMetadataCuration\classes\managers\OrcidRorManager;
use APP\plugins\generic\nvMetadataCuration\classes\managers\SparqlLookupManager;
use PKP\db\DAORegistry;
use PKP\handler\PKPHandler;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Role;

class SuggestHandler extends PKPHandler
{
    /** @var string[] Allowed thesaurus identifiers */
    private const ALLOWED_THESAURI = ['unesco', 'rameau', 'eurovoc'];

    /** @var string[] Allowed language codes */
    private const ALLOWED_LANGS = ['es', 'fr', 'en'];

    /** @var int Minimum query length */
    private const MIN_QUERY_LENGTH = 3;

    /** @var int Free tier daily request limit per journal */
    private const FREE_TIER_DAILY_LIMIT = 50;

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments): bool
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Check API key and rate limits (freemium model).
     * Returns true if request is allowed, sends 429 JSON and exits if not.
     */
    private function checkApiGating($request): void
    {
        $context = $request->getContext();
        if (!$context) {
            return;
        }

        $plugin = \APP\plugins\generic\nvMetadataCuration\NvMetadataCurationPlugin::getPlugin();
        if (!$plugin) {
            return;
        }

        $contextId = $context->getId();
        $apiKey = $plugin->getSetting($contextId, 'nvApiKey');

        // With a valid API key, no rate limit
        if (!empty($apiKey) && mb_strlen($apiKey) >= 16) {
            return;
        }

        // Free tier: enforce daily limit per context via file-based counter
        $countFile = sys_get_temp_dir() . '/nv_ratelimit_' . $contextId . '_' . date('Ymd');
        $currentCount = 0;
        if (file_exists($countFile)) {
            $currentCount = (int) file_get_contents($countFile);
        }

        if ($currentCount >= self::FREE_TIER_DAILY_LIMIT) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'Daily request limit reached. Add an NV API key in plugin settings for unlimited access.',
                'limit' => self::FREE_TIER_DAILY_LIMIT,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        @file_put_contents($countFile, (string) ($currentCount + 1));
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
        $this->checkApiGating($request);

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
     * Authorize access to a submission: user must be authenticated,
     * submission must exist in the current context, and user must be
     * a participant or journal manager.
     *
     * @return array{user: \PKP\user\User, submission: \APP\submission\Submission, submissionDao: \PKP\db\DAO}
     */
    private function authorizeSubmissionAccess($request, int $submissionId): array
    {
        if ($submissionId <= 0) {
            $this->sendJsonError('Missing or invalid submissionId');
        }

        $user = $request->getUser();
        if (!$user) {
            $this->sendJsonError('Authentication required', 401);
        }

        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $submission = $submissionDao->getById($submissionId);

        if (!$submission) {
            $this->sendJsonError('Submission not found', 404);
        }

        $context = $request->getContext();
        if (!$context || $submission->getData('contextId') !== $context->getId()) {
            $this->sendJsonError('Submission not in current context', 403);
        }

        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
        $assignments = $stageAssignmentDao->getBySubmissionAndStageId(
            $submissionId,
            null,
            null,
            $user->getId()
        );

        $isParticipant = !$assignments->wasEmpty();

        if (!$isParticipant) {
            $userRoles = $user->getRoles($context->getId());
            $isManager = false;
            foreach ($userRoles as $role) {
                if ($role->getId() === Role::ROLE_ID_MANAGER) {
                    $isManager = true;
                    break;
                }
            }
            if (!$isManager) {
                $this->sendJsonError('Not authorized to modify this submission', 403);
            }
        }

        return ['user' => $user, 'submission' => $submission, 'submissionDao' => $submissionDao];
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

        ['submission' => $submission, 'submissionDao' => $submissionDao]
            = $this->authorizeSubmissionAccess($request, $submissionId);

        $keywords = json_decode($keywordsRaw, true);
        if (!is_array($keywords)) {
            $this->sendJsonError('Invalid keywords JSON');
        }

        // Validate each keyword entry (bypass keywords have empty kwd_uri)
        $validated = [];
        foreach ($keywords as $kwd) {
            if (empty($kwd['kwd_value'])) {
                continue;
            }
            $validated[] = [
                'kwd_value' => (string) $kwd['kwd_value'],
                'kwd_uri' => (string) ($kwd['kwd_uri'] ?? ''),
                'kwd_lang' => (string) ($kwd['kwd_lang'] ?? 'es'),
                'kwd_thesaurus' => (string) ($kwd['kwd_thesaurus'] ?? ''),
                'kwd_validated' => !empty($kwd['kwd_uri']),
            ];
        }

        $submission->setData('nvKeywords', json_encode($validated, JSON_UNESCAPED_UNICODE));
        $submissionDao->updateObject($submission);

        // Sync validated keywords into OJS native Publication::keywords
        // so they appear in OAI-PMH, Crossref, and the public article view.
        $this->syncKeywordsToPublication($submission, $validated);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'ok',
            'submissionId' => $submissionId,
            'savedCount' => count($validated),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * ORCID lookup endpoint.
     * Expects POST with: q (author name query)
     */
    public function orcid($args, $request)
    {
        $this->checkApiGating($request);

        $q = trim((string) $request->getUserVar('q'));
        if (mb_strlen($q, 'UTF-8') < 2) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['query' => $q, 'results' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $manager = new OrcidRorManager();
        $result = $manager->searchOrcid($q);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * ROR lookup endpoint.
     * Expects POST with: q (institution name query)
     */
    public function ror($args, $request)
    {
        $this->checkApiGating($request);

        $q = trim((string) $request->getUserVar('q'));
        if (mb_strlen($q, 'UTF-8') < 2) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['query' => $q, 'results' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $manager = new OrcidRorManager();
        $result = $manager->searchRor($q);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Server-side ORCID/ROR validation endpoint (C1b compliance).
     * Validates identifier formats and persists human-confirmed validation state.
     *
     * Expects POST with:
     *   - submissionId (int)
     *   - contributors (JSON string — array of contributor objects)
     *
     * Each contributor object: { authorId, orcid?, rorId?, orcidDisplayName?, rorDisplayName? }
     */
    public function saveContributorIds($args, $request)
    {
        $submissionId = (int) $request->getUserVar('submissionId');
        $contributorsRaw = (string) $request->getUserVar('contributors');

        ['user' => $user, 'submission' => $submission, 'submissionDao' => $submissionDao]
            = $this->authorizeSubmissionAccess($request, $submissionId);

        $contributors = json_decode($contributorsRaw, true);
        if (!is_array($contributors)) {
            $this->sendJsonError('Invalid contributors JSON');
        }

        $errors = [];
        $validated = [];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $userId = $user->getId();

        foreach ($contributors as $contrib) {
            $authorId = (string) ($contrib['authorId'] ?? '');
            if ($authorId === '') {
                continue;
            }

            $entry = [];
            $entryErrors = [];

            // Validate ORCID if provided
            $orcid = trim((string) ($contrib['orcid'] ?? ''));
            if ($orcid !== '') {
                $bareOrcid = OrcidRorManager::extractOrcid($orcid);
                if ($bareOrcid === null || !OrcidRorManager::isValidOrcidFormat($bareOrcid)) {
                    $entryErrors['orcid'] = 'Invalid ORCID format or checksum';
                } else {
                    $entry['orcid'] = $bareOrcid;
                    $entry['orcidDisplayName'] = (string) ($contrib['orcidDisplayName'] ?? '');
                    $entry['orcidValidated'] = true;
                    $entry['orcidValidatedAt'] = $now;
                    $entry['orcidValidatedBy'] = $userId;
                }
            }

            // Validate ROR if provided
            $rorId = trim((string) ($contrib['rorId'] ?? ''));
            if ($rorId !== '') {
                if (!OrcidRorManager::isValidRorFormat($rorId)) {
                    $entryErrors['rorId'] = 'Invalid ROR identifier format';
                } else {
                    $entry['rorId'] = $rorId;
                    $entry['rorDisplayName'] = (string) ($contrib['rorDisplayName'] ?? '');
                    $entry['rorValidated'] = true;
                    $entry['rorValidatedAt'] = $now;
                    $entry['rorValidatedBy'] = $userId;
                }
            }

            if (!empty($entryErrors)) {
                $errors[$authorId] = $entryErrors;
            }

            if (!empty($entry)) {
                $validated[$authorId] = $entry;
            }
        }

        if (!empty($errors)) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'Validation failed',
                'details' => $errors,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Merge with existing validation data (preserves previously validated contributors)
        $existingJson = $submission->getData('nvContributorValidation');
        $existing = $existingJson ? json_decode($existingJson, true) : [];
        if (!is_array($existing)) {
            $existing = [];
        }

        foreach ($validated as $authorId => $entry) {
            $existing[$authorId] = array_merge($existing[$authorId] ?? [], $entry);
        }

        $submission->setData('nvContributorValidation', json_encode($existing, JSON_UNESCAPED_UNICODE));
        $submissionDao->updateObject($submission);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'ok',
            'submissionId' => $submissionId,
            'validatedCount' => count($validated),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Write validated NV keywords into the OJS native keywords field
     * on the current publication. Defensive: logs errors but never
     * breaks the save flow if the Repo call fails.
     */
    private function syncKeywordsToPublication($submission, array $validated): void
    {
        if (empty($validated)) {
            return;
        }

        try {
            $publication = $submission->getCurrentPublication();
            if (!$publication) {
                return;
            }

            $existingKeywords = $publication->getData('keywords') ?? [];

            // Group NV keywords by OJS locale
            $nvByLocale = [];
            foreach ($validated as $kwd) {
                $locale = $this->mapLangToLocale($kwd['kwd_lang']);
                $nvByLocale[$locale][] = $kwd['kwd_value'];
            }

            // Merge: NV keywords replace per-locale but preserve other locales
            foreach ($nvByLocale as $locale => $labels) {
                $existingKeywords[$locale] = array_values(array_unique($labels));
            }

            Repo::publication()->edit($publication, ['keywords' => $existingKeywords]);
        } catch (\Throwable $e) {
            error_log('[nvMetadataCuration] Failed to sync keywords to publication: ' . $e->getMessage());
        }
    }

    /**
     * Map a short language code to an OJS locale string.
     */
    private function mapLangToLocale(string $lang): string
    {
        $map = [
            'es' => 'es',
            'fr' => 'fr_FR',
            'en' => 'en',
        ];
        return $map[$lang] ?? $lang;
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
