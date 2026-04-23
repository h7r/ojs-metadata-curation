# Changelog

All notable changes to this plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v2.0.1 — 2026-04-23

### Fixed

- **GST-23.** `SuggestHandler::userIsSubmissionParticipant()` now peeks the
  first row of the `StageAssignmentDAO` iterator instead of calling
  `DAOResultFactory::wasEmpty()`, which throws when the factory is built
  without a `$countSql` (the OJS 3.4 code path). Unblocks `POST /save` and
  `POST /saveContributorIds`, which were returning 500 after the CSRF guard
  passed. No behavioural change in the participant check.

### Removed

- **GST-11.** The `choice` and `bypass` interaction modes and every
  dependency they carried: the `interactionMode` plugin setting, the
  `VALID_MODES` constant, the `interactionMode` select on the settings form,
  the `INTERACTION_MODE` constant and its mode-specific Enter/blur/paste
  branches in `keyword-lookup.js`, the `showBypassWarning` /
  `clearBypassWarning` / `showChoiceBlockedHint` helpers, the `.nv-mode-*`
  wrapper class and the `.nv-choice-hint` / `.nv-bypass-notice` /
  `.nv-bypass-warning` CSS rules, and 10 i18n keys per locale
  (`settings.interactionMode*`, `mode.{suggestion,choice,bypass}`,
  `widget.bypassWarning`, `js.{choiceRequired,bypassWarning,
  choicePlaceholder,bypassNotice}`) across en/es/fr_FR — po/xml parity
  preserved. The plugin now exposes a single interaction surface:
  suggestion-only.

### Changed

- Docs realigned to the shipped scope. README no longer advertises
  « Multi-thésaurus | Configurable par revue » or « 3 modes d'interaction ».
  `docs/pkp-gallery-submission.md` drops the same claims from the release
  notes, Gallery XML description and tarball filename (now
  `nvMetadataCuration-2.0.1.0.tar.gz`). `docs/ux-spec.md` replaces the
  « Trois modes d'interaction » section with a suggestion-only description
  and adds retraction notes for the multi-thesaurus UI (tracked as GST-32
  in the backlog, not relanded in v2.0.1).
- Bumped `plugin/version.xml` to `2.0.1.0`.

## [2.0.0.0] — 2026-04-23

### Removed

- Paid tier (« NV API key » freemium gating). The plugin is now free and
  unlimited. The `nvApiKey` setting, the `RateLimitManager` class, the
  `checkApiGating()` method in `SuggestHandler`, the associated i18n keys
  (`settings.apiKey*`, `widget.rateLimited`) and the `RateLimitManagerTest`
  suite have been removed. Existing installs with an orphan `nvApiKey` value
  in the `plugin_settings` table are unaffected — the value is simply no
  longer read.

### Changed

- Repositioned the plugin as « SPARQL-based » in all narrative surfaces
  (README, Gallery metadata, docstrings, i18n descriptions). SKOS remains
  the underlying W3C vocabulary used in the SPARQL queries themselves —
  this is a marketing/documentation change, not a code change to the
  queries.
- Bumped to 2.0.0.0 (MAJOR) to signal the breaking removal of the paid
  tier UI and setting.

### Preserved (pre-free-pivot tag)

The last commit of the paid-tier era is tagged `pre-free-pivot` on the
remote. See `git show pre-free-pivot` to exhume any part of the removed
code.

## [1.0.0.0] — 2026-04-21

Initial release. See the `pre-free-pivot` tag for the exact state.
