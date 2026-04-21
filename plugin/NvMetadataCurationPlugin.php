<?php

/**
 * @file NvMetadataCurationPlugin.php
 *
 * Copyright (c) 2026 Ne Varietur
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class NvMetadataCurationPlugin
 *
 * @brief Controlled vocabulary lookup for OJS metadata fields
 *        using SKOS/SPARQL thesauri (UNESCO, Rameau).
 *
 *        Phase 2: autocomplete widget on submission form,
 *        SPARQL proxy endpoint, submission_settings storage.
 */

namespace APP\plugins\generic\nvMetadataCuration;

use APP\core\Application;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class NvMetadataCurationPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled()) {
            // Inject JS autocomplete widget into the submission metadata form
            Hook::add('TemplateManager::display', [$this, 'injectKeywordLookup']);

            // Register page handler for /suggest endpoint
            Hook::add('LoadHandler', [$this, 'callbackLoadHandler']);
        }

        return $success;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.nvMetadataCuration.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.nvMetadataCuration.description');
    }

    /**
     * @copydoc Plugin::getInstallSitePluginSettingsFile()
     */
    public function getInstallSitePluginSettingsFile(): ?string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * Hook callback: inject the keyword-lookup JS and suggest URL
     * into the submission metadata form template.
     *
     * Targets TemplateManager::display (OJS 3.4.x).
     * SPECS.md section 6.1.
     */
    public function injectKeywordLookup(string $hookName, array $args): bool
    {
        $templateMgr = $args[0];
        $template = $args[1] ?? '';

        // Only inject on the submission metadata form, not all submission/* templates
        $metadataTemplates = [
            'submission/form/metadata.tpl',
            'submission/submit/step3.tpl',       // OJS 3.4.x step 3 (metadata)
            'submission/submissionMetadataForm',  // Vue component wrapper
        ];
        $isMetadataForm = false;
        foreach ($metadataTemplates as $target) {
            if (strpos($template, $target) !== false) {
                $isMetadataForm = true;
                break;
            }
        }
        if (!$isMetadataForm) {
            return false;
        }

        $request = Application::get()->getRequest();

        // Build endpoint URLs
        $dispatcher = $request->getDispatcher();
        $suggestUrl = $dispatcher->url(
            $request,
            Application::ROUTE_PAGE,
            null,
            'nv-metadata-suggest',
            'suggest'
        );
        $saveUrl = $dispatcher->url(
            $request,
            Application::ROUTE_PAGE,
            null,
            'nv-metadata-suggest',
            'save'
        );

        $thesaurus = $this->getSetting($request->getContext()?->getId(), 'thesaurus') ?: 'unesco';

        $templateMgr->addJavaScript(
            'nvKeywordLookupConfig',
            'window.nvMetadataCuration = ' . json_encode([
                'suggestUrl' => $suggestUrl,
                'saveUrl' => $saveUrl,
                'thesaurus' => $thesaurus,
                'minChars' => 3,
            ], JSON_UNESCAPED_UNICODE) . ';',
            [
                'inline' => true,
                'contexts' => 'backend',
                'priority' => STYLE_SEQUENCE_CORE,
            ]
        );

        $templateMgr->addJavaScript(
            'nvKeywordLookup',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/keyword-lookup.js',
            [
                'inline' => false,
                'contexts' => 'backend',
                'priority' => STYLE_SEQUENCE_LAST,
            ]
        );

        $templateMgr->addStyleSheet(
            'nvKeywordLookup',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/css/keyword-lookup.css',
            [
                'contexts' => 'backend',
                'priority' => STYLE_SEQUENCE_LAST,
            ]
        );

        return false; // Don't interrupt the hook chain
    }

    /**
     * Hook callback: intercept page requests for 'nv-metadata-suggest'
     * and route them to our SuggestHandler.
     */
    public function callbackLoadHandler(string $hookName, array $args): bool
    {
        $page = $args[0] ?? '';

        if ($page === 'nv-metadata-suggest') {
            define('HANDLER_CLASS', 'APP\plugins\generic\nvMetadataCuration\classes\handlers\SuggestHandler');
            return true;
        }

        return false;
    }
}
