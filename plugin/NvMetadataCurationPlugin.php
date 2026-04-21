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
 *        using SKOS/SPARQL thesauri (UNESCO, Rameau, Eurovoc).
 *
 *        Phase 3: multi-thesaurus, settings form, backoffice audit,
 *        ORCID/ROR lookup, API key freemium gating, full i18n.
 */

namespace APP\plugins\generic\nvMetadataCuration;

use APP\core\Application;
use APP\plugins\generic\nvMetadataCuration\classes\forms\SettingsForm;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class NvMetadataCurationPlugin extends GenericPlugin
{
    /** @var ?self Singleton for template access from handlers */
    private static ?self $instance = null;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled()) {
            self::$instance = $this;

            // Inject JS autocomplete widget into the submission metadata form
            Hook::add('TemplateManager::display', [$this, 'injectKeywordLookup']);

            // Register page handler for /suggest and /audit endpoints
            Hook::add('LoadHandler', [$this, 'callbackLoadHandler']);
        }

        return $success;
    }

    public static function getPlugin(): ?self
    {
        return self::$instance;
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
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);

        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, [
                    'verb' => 'settings',
                    'plugin' => $this->getName(),
                    'category' => 'generic',
                ]),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        $verb = $request->getUserVar('verb');

        if ($verb === 'settings') {
            $context = $request->getContext();
            $form = new SettingsForm($this, $context->getId());

            if ($request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true);
                }
            } else {
                $form->initData();
            }
            return new JSONMessage(true, $form->fetch($request));
        }

        return parent::manage($args, $request);
    }

    /**
     * Hook callback: inject the keyword-lookup JS, ORCID/ROR widget,
     * and config into the submission metadata form template.
     */
    public function injectKeywordLookup(string $hookName, array $args): bool
    {
        $templateMgr = $args[0];
        $template = $args[1] ?? '';

        $metadataTemplates = [
            'submission/form/metadata.tpl',
            'submission/submit/step3.tpl',
            'submission/submissionMetadataForm',
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
        $dispatcher = $request->getDispatcher();
        $contextId = $request->getContext()?->getId();

        $suggestUrl = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'nv-metadata-suggest', 'suggest');
        $saveUrl = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'nv-metadata-suggest', 'save');
        $orcidUrl = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'nv-metadata-suggest', 'orcid');
        $rorUrl = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'nv-metadata-suggest', 'ror');

        $thesaurus = $this->getSetting($contextId, 'thesaurus') ?: 'unesco';
        $interactionMode = $this->getSetting($contextId, 'interactionMode') ?: 'suggestion';

        // Multi-thesaurus: load full list
        $thesauriRaw = $this->getSetting($contextId, 'thesauri');
        $thesauri = $thesauriRaw ? json_decode($thesauriRaw, true) : [$thesaurus];
        if (!is_array($thesauri) || empty($thesauri)) {
            $thesauri = [$thesaurus];
        }

        $templateMgr->addJavaScript(
            'nvKeywordLookupConfig',
            'window.nvMetadataCuration = ' . json_encode([
                'suggestUrl' => $suggestUrl,
                'saveUrl' => $saveUrl,
                'orcidUrl' => $orcidUrl,
                'rorUrl' => $rorUrl,
                'thesaurus' => $thesaurus,
                'thesauri' => $thesauri,
                'interactionMode' => $interactionMode,
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

        $templateMgr->addJavaScript(
            'nvOrcidRorLookup',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/orcid-ror-lookup.js',
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

        return false;
    }

    /**
     * Hook callback: intercept page requests for plugin endpoints.
     */
    public function callbackLoadHandler(string $hookName, array $args): bool
    {
        $page = $args[0] ?? '';

        if ($page === 'nv-metadata-suggest') {
            define('HANDLER_CLASS', 'APP\plugins\generic\nvMetadataCuration\classes\handlers\SuggestHandler');
            return true;
        }

        if ($page === 'nv-metadata-audit') {
            define('HANDLER_CLASS', 'APP\plugins\generic\nvMetadataCuration\classes\handlers\AuditHandler');
            return true;
        }

        return false;
    }
}
