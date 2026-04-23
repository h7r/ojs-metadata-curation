# PKP Plugin Gallery — Dossier de soumission

**Plugin :** NV Metadata Curation
**Version :** 2.0.0.0
**Date :** 2026-04-23
**Catégorie :** generic
**Licence :** GPL v3

---

## 1. Tarball de release

Fichier : `nvMetadataCuration-2.0.0.0.tar.gz`
MD5 : `{RELEASE_MD5}` (à recalculer lors du build post-merge)

Reconstruit avec :
```bash
tar czf nvMetadataCuration-2.0.0.0.tar.gz \
  --transform='s|^plugin|nvMetadataCuration|' \
  --exclude='plugin/vendor' \
  --exclude='plugin/.phpunit.cache' \
  --exclude='plugin/composer.json' \
  --exclude='plugin/composer.lock' \
  --exclude='plugin/phpunit.xml' \
  --exclude='*.po' \
  plugin/
```

---

## 2. Étapes de publication

### 2a. Créer un dépôt GitHub public (ou utiliser l'existant)

Le tarball doit être hébergé sur une URL publique stable. GitHub Releases est recommandé.

```bash
# Ajouter le remote GitHub (si pas encore fait)
git remote add origin https://github.com/nevarietur/ojs-nv-metadata-curation.git

# Tag de release
git tag -a v2.0.0 -m "Release 2.0.0 — free pivot (paid tier removed)"
git push origin main --tags
```

### 2b. Créer la GitHub Release

```bash
gh release create v2.0.0 \
  nvMetadataCuration-2.0.0.0.tar.gz \
  --title "v2.0.0 — Free pivot" \
  --notes "Free pivot: paid tier removed, SPARQL-based.

Features:
- SPARQL autocomplete (UNESCO, Rameau, Eurovoc)
- ORCID/ROR contributor lookup with human validation
- 3 interaction modes: suggestion, choice, bypass
- Backoffice metadata audit dashboard
- Trilingual: EN, ES, FR"
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
	<homepage>https://github.com/nevarietur/ojs-nv-metadata-curation</homepage>
	<summary locale="en">Controlled vocabulary lookup for OJS metadata fields using SPARQL thesauri (UNESCO, Rameau, Eurovoc).</summary>
	<summary locale="es">Búsqueda de vocabularios controlados para campos de metadatos OJS mediante tesauros SPARQL (UNESCO, Rameau, Eurovoc).</summary>
	<summary locale="fr_FR">Recherche de vocabulaires contrôlés pour les champs de métadonnées OJS via des thésaurus SPARQL (UNESCO, Rameau, Eurovoc).</summary>
	<description locale="en"><![CDATA[<p>NV Metadata Curation enriches OJS submission metadata with controlled vocabularies from SPARQL thesauri (UNESCO, Rameau BnF, Eurovoc EU).</p><p>Features: autocomplete keyword suggestions, ORCID/ROR contributor lookup with human validation, 3 interaction modes (suggestion/choice/bypass), backoffice metadata audit dashboard, free and unlimited access, and full trilingual support (EN/ES/FR).</p><p>Requires OJS 3.4.x and PHP 8.1+.</p>]]></description>
	<description locale="fr_FR"><![CDATA[<p>NV Metadata Curation enrichit les métadonnées de soumission OJS avec des vocabulaires contrôlés issus de thésaurus SPARQL (UNESCO, Rameau BnF, Eurovoc UE).</p><p>Fonctionnalités : autocomplétion par thésaurus, recherche ORCID/ROR avec validation humaine, 3 modes d'interaction (suggestion/choix/bypass), tableau d'audit backoffice, accès gratuit et illimité, support trilingue complet (EN/ES/FR).</p><p>Requiert OJS 3.4.x et PHP 8.1+.</p>]]></description>
	<maintainer>
		<name>h7r</name>
		<institution>Ne Varietur (WIP)</institution>
		<email>ojs-plugin@ne-varietur.mozmail.com</email>
	</maintainer>
	<release date="2026-04-23" version="2.0.0.0" md5="{RELEASE_MD5}">
		<package>{RELEASE_URL}/nvMetadataCuration-2.0.0.0.tar.gz</package>
		<compatibility application="ojs2">
			<version>3.4.0.0</version>
			<version>3.4.0.1</version>
			<version>3.4.0.2</version>
			<version>3.4.0.3</version>
			<version>3.4.0.4</version>
			<version>3.4.0.5</version>
			<version>3.4.0.6</version>
			<version>3.4.0.7</version>
		</compatibility>
		<certification type="reviewed"/>
		<description locale="en">Free pivot: paid tier removed, SPARQL autocomplete, ORCID/ROR lookup, 3 interaction modes, metadata audit, trilingual EN/ES/FR.</description>
	</release>
</plugin>
```

---

## 4. Checklist pré-soumission

- [x] `version.xml` conforme DTD `pluginVersion.dtd` (4 chiffres : 2.0.0.0)
- [x] `LICENSE` GPL v3 inclus dans le tarball
- [x] Locales : en, es, fr_FR
- [x] Aucune dépendance externe runtime
- [x] Plugin extends `GenericPlugin` (OJS 3.4)
- [x] Namespace PSR-4 correct
- [x] Tests unitaires inclus (5 suites — `RateLimitManagerTest` retiré en v2.0)
- [x] Tarball structure : `nvMetadataCuration/` racine unique
- [ ] MD5 recalculé pour `nvMetadataCuration-2.0.0.0.tar.gz` (post-merge)
- [ ] GitHub repo public créé
- [ ] GitHub Release publiée avec tarball
- [ ] PR ouverte sur `pkp/plugin-gallery`
