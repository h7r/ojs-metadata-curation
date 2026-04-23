<?php

/**
 * @file classes/forms/SettingsForm.php
 *
 * Copyright (c) 2026 Ne Varietur
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Plugin settings form — thesaurus selection.
 */

namespace APP\plugins\generic\nvMetadataCuration\classes\forms;

use APP\plugins\generic\nvMetadataCuration\NvMetadataCurationPlugin;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class SettingsForm extends Form
{
    private NvMetadataCurationPlugin $plugin;
    private int $contextId;

    private const VALID_THESAURI = ['unesco', 'rameau', 'eurovoc'];

    public function __construct(NvMetadataCurationPlugin $plugin, int $contextId)
    {
        $this->plugin = $plugin;
        $this->contextId = $contextId;

        parent::__construct($plugin->getTemplateResource('settings.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        $thesauri = $this->plugin->getSetting($this->contextId, 'thesauri');
        if (!$thesauri) {
            $thesauri = ['unesco'];
        } elseif (is_string($thesauri)) {
            $thesauri = json_decode($thesauri, true) ?: ['unesco'];
        }

        $this->setData('thesauri', $thesauri);
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(['thesauri']);
    }

    /**
     * @copydoc Form::fetch()
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);

        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'validThesauri' => self::VALID_THESAURI,
        ]);
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $thesauri = $this->getData('thesauri');
        if (!is_array($thesauri)) {
            $thesauri = [$thesauri];
        }
        $thesauri = array_intersect($thesauri, self::VALID_THESAURI);
        if (empty($thesauri)) {
            $thesauri = ['unesco'];
        }

        $this->plugin->updateSetting($this->contextId, 'thesauri', json_encode(array_values($thesauri)));
        $this->plugin->updateSetting($this->contextId, 'thesaurus', $thesauri[0]);

        parent::execute(...$functionArgs);
    }
}
