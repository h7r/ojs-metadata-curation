<?php

/**
 * @file classes/handlers/AuditHandler.php
 *
 * Copyright (c) 2026 Ne Varietur
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Backoffice audit view — metadata conformity per submission.
 *        Shows editors which submissions have curated keywords vs raw input.
 */

namespace APP\plugins\generic\nvMetadataCuration\classes\handlers;

use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\handler\PKPHandler;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\authorization\PolicySet;
use PKP\security\authorization\RoleBasedHandlerOperationPolicy;
use PKP\security\Role;

class AuditHandler extends PKPHandler
{
    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments): bool
    {
        $this->addPolicy(new ContextRequiredPolicy($request));

        $rolePolicy = new PolicySet(PolicySet::COMBINING_PERMIT_OVERRIDES);
        $rolePolicy->addPolicy(new RoleBasedHandlerOperationPolicy(
            $request,
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            ['index']
        ));
        $this->addPolicy($rolePolicy);

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Display the audit dashboard.
     */
    public function index($args, $request)
    {
        $context = $request->getContext();
        $contextId = $context->getId();

        // Fetch active submissions for this journal
        $collector = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByStatus([STATUS_QUEUED, STATUS_PUBLISHED])
            ->limit(100);

        $submissions = Repo::submission()->getMany($collector);

        $auditData = [];
        foreach ($submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            if (!$publication) {
                continue;
            }

            $title = $publication->getLocalizedTitle() ?: ('Submission #' . $submission->getId());

            // Check keyword curation status
            $nvKeywordsRaw = $submission->getData('nvKeywords');
            $nvKeywords = $nvKeywordsRaw ? json_decode($nvKeywordsRaw, true) : [];
            $hasNvKeywords = is_array($nvKeywords) && count($nvKeywords) > 0;

            // Check native OJS keywords
            $keywords = $publication->getData('keywords');
            $hasOjsKeywords = !empty($keywords);

            // Check ORCID presence on contributors
            $contributors = $publication->getData('authors') ?? [];
            $orcidCount = 0;
            $contributorCount = 0;
            if (is_iterable($contributors)) {
                foreach ($contributors as $author) {
                    $contributorCount++;
                    $orcid = is_object($author) ? $author->getData('orcid') : ($author['orcid'] ?? '');
                    if (!empty($orcid)) {
                        $orcidCount++;
                    }
                }
            }

            // Check affiliations
            $affiliationCount = 0;
            if (is_iterable($contributors)) {
                foreach ($contributors as $author) {
                    $aff = is_object($author) ? $author->getLocalizedData('affiliation') : ($author['affiliation'] ?? '');
                    if (!empty($aff)) {
                        $affiliationCount++;
                    }
                }
            }

            $auditData[] = [
                'submissionId' => $submission->getId(),
                'title' => $title,
                'status' => $submission->getData('status'),
                'fields' => [
                    'kwd_curated' => [
                        'status' => $hasNvKeywords ? 'complete' : ($hasOjsKeywords ? 'partial' : 'missing'),
                        'count' => $hasNvKeywords ? count($nvKeywords) : 0,
                    ],
                    'kwd_ojs' => [
                        'status' => $hasOjsKeywords ? 'complete' : 'missing',
                    ],
                    'orcid' => [
                        'status' => ($contributorCount > 0 && $orcidCount === $contributorCount) ? 'complete'
                            : ($orcidCount > 0 ? 'partial' : 'missing'),
                        'ratio' => $contributorCount > 0 ? "{$orcidCount}/{$contributorCount}" : '0/0',
                    ],
                    'affiliation' => [
                        'status' => ($contributorCount > 0 && $affiliationCount === $contributorCount) ? 'complete'
                            : ($affiliationCount > 0 ? 'partial' : 'missing'),
                        'ratio' => $contributorCount > 0 ? "{$affiliationCount}/{$contributorCount}" : '0/0',
                    ],
                ],
            ];
        }

        $plugin = \APP\plugins\generic\nvMetadataCuration\NvMetadataCurationPlugin::getPlugin();

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'auditData' => $auditData,
            'pageTitle' => 'plugins.generic.nvMetadataCuration.audit.title',
        ]);

        $templateMgr->addStyleSheet(
            'nvAuditCss',
            $request->getBaseUrl() . '/' . $plugin->getPluginPath() . '/css/audit.css',
            ['contexts' => 'backend', 'priority' => STYLE_SEQUENCE_LAST]
        );

        return $templateMgr->display($plugin->getTemplateResource('audit.tpl'));
    }
}
