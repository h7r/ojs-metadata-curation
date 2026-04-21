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
 *        using SKOS/SPARQL thesauri (UNESCO, ISOC, Rameau).
 */

namespace APP\plugins\generic\nvMetadataCuration;

use PKP\plugins\GenericPlugin;

class NvMetadataCurationPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled()) {
            // Phase 2 : hook registrations for SPARQL lookup
            // and submission form augmentation will go here.
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
}
