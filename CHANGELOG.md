# Changelog

All notable changes to this plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
