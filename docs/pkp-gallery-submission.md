# PKP Plugin Gallery — Dossier de soumission

**Plugin :** NV Metadata Curation
**Version :** 2.0.4.0
**Date :** 2026-04-26
**Catégorie :** generic
**Licence :** GPL v3

---

## 1. Tarball de release

Fichier : `nvMetadataCuration-2.0.4.0.tar.gz`
MD5 : `{RELEASE_MD5}` (à recalculer lors du build post-merge)

Reconstruit avec :
```bash
tar czf nvMetadataCuration-2.0.4.0.tar.gz \
  --transform='s|^plugin|nvMetadataCuration|' \
  --exclude='plugin/vendor' \
  --exclude='plugin/.phpunit.cache' \
  --exclude='plugin/composer.json' \
  --exclude='plugin/composer.lock' \
  --exclude='plugin/phpunit.xml' \
  plugin/
```

> **Note** : les fichiers `*.po` sont **conservés** dans le tarball (OJS 3.4 les consomme au runtime pour i18n EN/ES/FR). Une exclusion `--exclude='*.po'` casserait l'interface trilingue dans la gallery — DOA.

---

## 2. Étapes de publication

### 2a. Créer un dépôt GitHub public (ou utiliser l'existant)

Le tarball doit être hébergé sur une URL publique stable. GitHub Releases est recommandé.

```bash
# Ajouter le remote GitHub (si pas encore fait)
git remote add origin https://github.com/h7r/ojs-metadata-curation.git

# Tag de release (4 chiffres, cohérent avec version.xml)
git tag -a v2.0.4.0 -m "v2.0.4.0 — first public release for PKP Gallery (GST-41/42/44/45)"
git push origin v2.0.4.0
```

### 2b. Créer la GitHub Release

```bash
gh release create v2.0.4.0 \
  nvMetadataCuration-2.0.4.0.tar.gz \
  --title "v2.0.4.0 — first public release for PKP Gallery" \
  --notes "First public release for PKP Plugin Gallery — Sprint 3 consolidated fixes.

Changes:
- Fix (GST-41): audit page 500 — AuditHandler uses _isBackendPage + setupTemplate, audit.tpl extends layouts/backend.tpl
- Docs (GST-41): drop legacy PLAN.md / docs/SPECS.md and stale README bullet
- Chore (GST-42): sweep dead SPECS.md refs + fix audit pageTitle i18n
- Fix (GST-44): getSubmissionId() covers editorial workflow URL (JS)
- Fix (GST-45): register nvKeywords + nvContributorValidation on Submission schema — prevents silent drop in Repo::submission()->edit()
- Chore (GST-46): maintainer identity set to Wilfried Fouillaret <wilfriedfouillaret@gmail.com> in composer.json, locale.po and gallery dossier; per-file copyright headers removed (LICENSE GPL v3 governs).

Features (unchanged):
- SPARQL autocomplete (UNESCO, Rameau, Eurovoc)
- ORCID/ROR contributor lookup with human validation
- Backoffice metadata audit dashboard
- Trilingual: EN, ES, FR
- Free and unlimited access (pivot v2.0.0.0)"
```

### 2c. Soumettre la PR sur pkp/plugin-gallery

1. Forker `https://github.com/pkp/plugin-gallery`
2. Éditer `plugins.xml` — ajouter le bloc XML ci-dessous
3. Ouvrir une Pull Request

---

## 3. Entrée XML pour `plugins.xml`

> **IMPORTANT :** Remplacer `{RELEASE_URL}` par l'URL finale du tarball sur GitHub Releases.
> Recalculer le MD5 si le tarball est regénéré.

```xml
<plugin category="generic" product="nvMetadataCuration">
	<name locale="en">NV Metadata Curation</name>
	<name locale="es">NV Curación de Metadatos</name>
	<name locale="fr_FR">NV Curation des Métadonnées</name>
	<homepage>https://github.com/h7r/ojs-metadata-curation</homepage>
	<summary locale="en">Sovereignty-friendly metadata curation for OJS — keyword autocomplete from open thesauri (UNESCO, Rameau, Eurovoc), human-validated ORCID/ROR contributor lookup, backoffice audit dashboard. Free and unlimited.</summary>
	<summary locale="es">Curación soberana de metadatos para OJS — autocompletado de palabras clave desde tesauros abiertos (UNESCO, Rameau, Eurovoc), búsqueda ORCID/ROR validada por humanos, panel de auditoría en el backoffice. Gratis e ilimitado.</summary>
	<summary locale="fr_FR">Curation souveraine des métadonnées pour OJS — autocomplétion de mots-clés depuis des thésaurus ouverts (UNESCO, Rameau, Eurovoc), recherche ORCID/ROR validée par un humain, tableau d'audit backoffice. Gratuit et illimité.</summary>
	<description locale="en"><![CDATA[<p>Sovereignty-friendly metadata curation for OJS journals.</p><p>Open thesauri (UNESCO, Rameau BnF, Eurovoc EU) for keyword autocomplete.</p><p>Human-validated ORCID/ROR contributor lookup — no silent auto-fill.</p><p>Free and unlimited — no API key, no rate limit, no third-party account.</p><p>Trilingual interface (EN/ES/FR).</p><p>Backoffice metadata audit dashboard for editors.</p><p>Requires OJS 3.4.x and PHP 8.1+.</p>]]></description>
	<description locale="es"><![CDATA[<p>Curación soberana de metadatos para revistas OJS.</p><p>Tesauros abiertos (UNESCO, Rameau BnF, Eurovoc UE) para el autocompletado de palabras clave.</p><p>Búsqueda de colaboradores ORCID/ROR con validación humana — sin autocompletado silencioso.</p><p>Gratis e ilimitado — sin clave API, sin límite de uso, sin cuenta de terceros.</p><p>Interfaz trilingüe (EN/ES/FR).</p><p>Panel de auditoría de metadatos en el backoffice para editores.</p><p>Requiere OJS 3.4.x y PHP 8.1+.</p>]]></description>
	<description locale="fr_FR"><![CDATA[<p>Curation souveraine des métadonnées pour les revues OJS.</p><p>Thésaurus ouverts (UNESCO, Rameau BnF, Eurovoc UE) pour l'autocomplétion des mots-clés.</p><p>Recherche ORCID/ROR avec validation humaine — pas d'auto-complétion silencieuse.</p><p>Gratuit et illimité — sans clé API, sans quota, sans compte tiers.</p><p>Interface trilingue (EN/ES/FR).</p><p>Tableau d'audit des métadonnées en backoffice pour les éditeurs.</p><p>Nécessite OJS 3.4.x et PHP 8.1+.</p>]]></description>
	<maintainer>
		<name>Wilfried Fouillaret</name>
		<email>wilfriedfouillaret@gmail.com</email>
	</maintainer>
	<release date="2026-04-26" version="2.0.4.0" md5="{RELEASE_MD5}">
		<package>{RELEASE_URL}/nvMetadataCuration-2.0.4.0.tar.gz</package>
		<compatibility application="ojs2">
			<version>3.4.0.0</version>
			<version>3.4.0.1</version>
			<version>3.4.0.2</version>
			<version>3.4.0.3</version>
			<version>3.4.0.4</version>
			<version>3.4.0.5</version>
			<version>3.4.0.6</version>
			<version>3.4.0.7</version>
			<version>3.4.0.8</version>
			<version>3.4.0.9</version>
			<version>3.4.0.10</version>
		</compatibility>
		<description locale="en">First public release for the PKP Plugin Gallery — full SPARQL keyword autocomplete, validated ORCID/ROR contributor lookup, backoffice metadata audit dashboard, trilingual EN/ES/FR. Free and unlimited.</description>
	</release>
</plugin>
```

---

## 4. Checklist pré-soumission

- [x] `version.xml` conforme DTD `pluginVersion.dtd` (4 chiffres : 2.0.4.0)
- [x] `LICENSE` GPL v3 inclus dans le tarball
- [x] Locales : en, es, fr_FR (fichiers `.po` **conservés** dans le tarball)
- [x] Aucune dépendance externe runtime
- [x] Plugin extends `GenericPlugin` (OJS 3.4)
- [x] Namespace PSR-4 correct
- [x] Tests unitaires inclus (5 suites — `RateLimitManagerTest` retiré en v2.0)
- [x] Tarball structure : `nvMetadataCuration/` racine unique
- [x] Compat OJS 3.4.x vérifiée à jour vs `github.com/pkp/ojs/tags` (2026-04-26 : `3.4.0.0` → `3.4.0.10`)
- [x] GitHub repo public : `github.com/h7r/ojs-metadata-curation`
- [ ] MD5 recalculé pour `nvMetadataCuration-2.0.4.0.tar.gz` (post-merge, GST-46.2)
- [ ] Tarball validé via install Docker OJS local + test 3 locales (GST-46.3 — Gate 1)
- [ ] Tag `v2.0.4.0` poussé (GST-46.4)
- [ ] GitHub Release publiée avec tarball + MD5 (GST-46.4)
- [ ] PR draft ouverte sur `pkp/plugin-gallery` (GST-46.4)
