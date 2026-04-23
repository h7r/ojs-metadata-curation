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
 *        using SPARQL thesauri (UNESCO, Rameau, Eurovoc).
 *
 *        Phase 3: multi-thesaurus, settings form, backoffice audit,
 *        ORCID/ROR lookup, full i18n.
 */

namespace APP\plugins\generic\nvMetadataCuration;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\nvMetadataCuration\classes\forms\SettingsForm;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use Illuminate\Support\Facades\DB;

class NvMetadataCurationPlugin extends GenericPlugin
{
    /** @var ?self Singleton for template access from handlers */
    private static ?self $instance = null;

    /** @var bool Guard against duplicate asset injection across template renders */
    private bool $assetsInjected = false;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled()) {
            self::$instance = $this;

            // OJS 3.4 submission wizard: inject directly into the Vue.js SPA section
            Hook::add('Template::SubmissionWizard::Section', [$this, 'injectSubmissionWizardAssets']);

            // Fallback for non-wizard backend pages (audit, metadata editing)
            Hook::add('TemplateManager::display', [$this, 'injectKeywordLookup']);

            // Register page handler for /suggest and /audit endpoints
            Hook::add('LoadHandler', [$this, 'callbackLoadHandler']);

            // Merge NV keywords into Publication::keywords on every publication save,
            // so keywords survive OJS form overwrites.
            Hook::add('Publication::edit', [$this, 'syncNvKeywordsAfterEdit']);
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
     * Build the JS config and asset URLs used by both injection hooks.
     *
     * @return array{config: string, jsUrls: string[], cssUrl: string}
     */
    private function getAssetPayload(): array
    {
        $request = Application::get()->getRequest();
        $dispatcher = $request->getDispatcher();
        $contextId = $request->getContext()?->getId();

        $suggestUrl = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'nv-metadata-suggest', 'suggest');
        $saveUrl = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'nv-metadata-suggest', 'save');
        $orcidUrl = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'nv-metadata-suggest', 'orcid');
        $rorUrl = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'nv-metadata-suggest', 'ror');
        $saveContributorIdsUrl = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'nv-metadata-suggest', 'saveContributorIds');

        $thesaurus = $this->getSetting($contextId, 'thesaurus') ?: 'unesco';
        $interactionMode = $this->getSetting($contextId, 'interactionMode') ?: 'suggestion';

        $thesauriRaw = $this->getSetting($contextId, 'thesauri');
        $thesauri = $thesauriRaw ? json_decode($thesauriRaw, true) : [$thesaurus];
        if (!is_array($thesauri) || empty($thesauri)) {
            $thesauri = [$thesaurus];
        }

        // Per-session CSRF token. Exposed to JS so save/saveContributorIds
        // XHRs can send it in X-Csrf-Token — validated server-side by CsrfGuard.
        $session = $request->getSession();
        $csrfToken = $session && method_exists($session, 'getCSRFToken')
            ? (string) $session->getCSRFToken()
            : '';

        $configJson = json_encode([
            'suggestUrl' => $suggestUrl,
            'saveUrl' => $saveUrl,
            'orcidUrl' => $orcidUrl,
            'rorUrl' => $rorUrl,
            'saveContributorIdsUrl' => $saveContributorIdsUrl,
            'csrfToken' => $csrfToken,
            'thesaurus' => $thesaurus,
            'thesauri' => $thesauri,
            'interactionMode' => $interactionMode,
            'minChars' => 3,
            'i18n' => [
                'errorHttp'              => __('plugins.generic.nvMetadataCuration.js.errorHttp'),
                'errorNetwork'           => __('plugins.generic.nvMetadataCuration.js.errorNetwork'),
                'errorTimeout'           => __('plugins.generic.nvMetadataCuration.js.errorTimeout'),
                'choiceRequired'         => __('plugins.generic.nvMetadataCuration.js.choiceRequired'),
                'bypassWarning'          => __('plugins.generic.nvMetadataCuration.js.bypassWarning'),
                'removeKeyword'          => __('plugins.generic.nvMetadataCuration.js.removeKeyword'),
                'saveSuccess'            => __('plugins.generic.nvMetadataCuration.js.saveSuccess'),
                'saveFailed'             => __('plugins.generic.nvMetadataCuration.js.saveFailed'),
                'choicePlaceholder'      => __('plugins.generic.nvMetadataCuration.js.choicePlaceholder'),
                'bypassNotice'           => __('plugins.generic.nvMetadataCuration.js.bypassNotice'),
                'orcidResults'           => __('plugins.generic.nvMetadataCuration.js.orcidResults'),
                'rorResults'             => __('plugins.generic.nvMetadataCuration.js.rorResults'),
                'validationRequired'     => __('plugins.generic.nvMetadataCuration.js.validationRequired'),
                'validationRequiredTitle' => __('plugins.generic.nvMetadataCuration.js.validationRequiredTitle'),
                'confirm'                => __('plugins.generic.nvMetadataCuration.js.confirm'),
                'confirmAriaLabel'       => __('plugins.generic.nvMetadataCuration.js.confirmAriaLabel'),
                'removeType'             => __('plugins.generic.nvMetadataCuration.js.removeType'),
            ],
        ], JSON_UNESCAPED_UNICODE);

        $baseUrl = $request->getBaseUrl() . '/' . $this->getPluginPath();

        return [
            'config' => $configJson,
            'jsUrls' => [
                $baseUrl . '/js/keyword-lookup.js',
                $baseUrl . '/js/orcid-ror-lookup.js',
            ],
            'cssUrl' => $baseUrl . '/css/keyword-lookup.css',
        ];
    }

    /**
     * Hook callback: inject assets into the OJS 3.4 submission wizard.
     *
     * The Template::SubmissionWizard::Section hook passes
     * [$step, $templateMgr, &$output].  We append raw HTML (script/style
     * tags) directly into $output since the Vue.js SPA does not honour
     * TemplateManager::addJavaScript.
     */
    public function injectSubmissionWizardAssets(string $hookName, array $args): bool
    {
        if ($this->assetsInjected) {
            return false;
        }
        $this->assetsInjected = true;

        $output =& $args[2];
        $payload = $this->getAssetPayload();

        $html = '<script>window.nvMetadataCuration = ' . $payload['config'] . ';</script>';
        $html .= '<link rel="stylesheet" href="' . htmlspecialchars($payload['cssUrl'], ENT_QUOTES, 'UTF-8') . '">';
        foreach ($payload['jsUrls'] as $jsUrl) {
            $html .= '<script src="' . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8') . '"></script>';
        }

        $output .= $html;

        return false;
    }

    /**
     * Hook callback: inject assets into non-wizard backend pages
     * (metadata editing, audit, etc.) via TemplateManager.
     *
     * Skipped when assets were already injected by the wizard hook.
     */
    public function injectKeywordLookup(string $hookName, array $args): bool
    {
        if ($this->assetsInjected) {
            return false;
        }
        $this->assetsInjected = true;

        $templateMgr = $args[0];
        $payload = $this->getAssetPayload();

        $request = Application::get()->getRequest();

        $templateMgr->addJavaScript(
            'nvKeywordLookupConfig',
            'window.nvMetadataCuration = ' . $payload['config'] . ';',
            [
                'inline' => true,
                'contexts' => 'backend',
                'priority' => STYLE_SEQUENCE_CORE,
            ]
        );

        foreach ($payload['jsUrls'] as $i => $jsUrl) {
            $templateMgr->addJavaScript(
                'nvKeywordLookup' . $i,
                $jsUrl,
                [
                    'inline' => false,
                    'contexts' => 'backend',
                    'priority' => STYLE_SEQUENCE_LAST,
                ]
            );
        }

        $templateMgr->addStyleSheet(
            'nvKeywordLookup',
            $payload['cssUrl'],
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

    /**
     * Hook callback: merge NV keywords into Publication::keywords after any
     * publication edit. Ensures keywords selected via the NV widget survive
     * OJS form saves that overwrite the keywords field.
     */
    public function syncNvKeywordsAfterEdit(string $hookName, array $args): bool
    {
        static $syncing = false;
        if ($syncing) {
            return false;
        }

        $publication = $args[0] ?? null;
        if (!$publication || !method_exists($publication, 'getData')) {
            return false;
        }

        $submissionId = $publication->getData('submissionId');
        if (!$submissionId) {
            return false;
        }

        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            return false;
        }

        $nvKeywordsJson = $submission->getData('nvKeywords');
        if (empty($nvKeywordsJson)) {
            return false;
        }

        $nvKeywords = json_decode($nvKeywordsJson, true);
        if (!is_array($nvKeywords) || empty($nvKeywords)) {
            return false;
        }

        $langMap = ['es' => 'es', 'fr' => 'fr_FR', 'en' => 'en'];

        $nvByLocale = [];
        foreach ($nvKeywords as $kwd) {
            if (empty($kwd['kwd_value'])) {
                continue;
            }
            $lang = $kwd['kwd_lang'] ?? 'es';
            $locale = $langMap[$lang] ?? $lang;
            $nvByLocale[$locale][] = $kwd['kwd_value'];
        }

        $existingKeywords = $publication->getData('keywords') ?? [];
        $changed = false;

        foreach ($nvByLocale as $locale => $labels) {
            $existing = $existingKeywords[$locale] ?? [];
            $merged = array_values(array_unique(array_merge($existing, $labels)));
            if ($merged !== $existing) {
                $existingKeywords[$locale] = $merged;
                $changed = true;
            }
        }

        if ($changed) {
            $syncing = true;
            DB::beginTransaction();
            try {
                // Acquire row-level lock to prevent concurrent lost-update race
                DB::select(
                    'SELECT 1 FROM publications WHERE publication_id = ? FOR UPDATE',
                    [$publication->getId()]
                );
                // Re-read keywords after lock to guarantee freshness
                $freshPub = Repo::publication()->get($publication->getId());
                $freshKeywords = $freshPub->getData('keywords') ?? [];
                foreach ($nvByLocale as $locale => $labels) {
                    $existing = $freshKeywords[$locale] ?? [];
                    $freshKeywords[$locale] = array_values(array_unique(array_merge($existing, $labels)));
                }
                Repo::publication()->edit($freshPub, ['keywords' => $freshKeywords]);
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                error_log('[nvMetadataCuration] syncNvKeywordsAfterEdit failed: ' . $e->getMessage());
            }
            $syncing = false;
        }

        return false;
    }
}

// PKP_STRICT_MODE compatibility: OJS 3.4 VersionDAO expects the short
// class name when resolving product_class_name from the DB.
if (!class_exists('NvMetadataCurationPlugin')) {
    class_alias('\APP\plugins\generic\nvMetadataCuration\NvMetadataCurationPlugin', 'NvMetadataCurationPlugin');
}
