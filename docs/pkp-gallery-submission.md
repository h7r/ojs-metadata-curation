# PKP Plugin Gallery — Dossier de soumission

**Plugin :** NV Metadata Curation
**Version :** 1.0.0.0
**Date :** 2026-04-22
**Catégorie :** generic
**Licence :** GPL v3

---

## 1. Tarball de release

Fichier : `nvMetadataCuration-1.0.0.0.tar.gz`
MD5 : `71e60181411c9b5d84d7264f747edcb3`

Reconstruit avec :
```bash
tar czf nvMetadataCuration-1.0.0.0.tar.gz \
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
git tag -a v1.0.0 -m "Release 1.0.0 — initial PKP Plugin Gallery submission"
git push origin main --tags
```

### 2b. Créer la GitHub Release

```bash
gh release create v1.0.0 \
  nvMetadataCuration-1.0.0.0.tar.gz \
  --title "v1.0.0 — Initial release" \
  --notes "Initial release for PKP Plugin Gallery submission.

Features:
- SKOS/SPARQL autocomplete (UNESCO, Rameau, Eurovoc)
- ORCID/ROR contributor lookup with human validation
- 3 interaction modes: suggestion, choice, bypass
- Backoffice metadata audit dashboard
- Freemium model (50 req/day free, unlimited with API key)
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
	<summary locale="en">Controlled vocabulary lookup for OJS metadata fields using SKOS/SPARQL thesauri (UNESCO, Rameau, Eurovoc).</summary>
	<summary locale="es">Búsqueda de vocabularios controlados para campos de metadatos OJS mediante tesauros SKOS/SPARQL (UNESCO, Rameau, Eurovoc).</summary>
	<summary locale="fr_FR">Recherche de vocabulaires contrôlés pour les champs de métadonnées OJS via des thésaurus SKOS/SPARQL (UNESCO, Rameau, Eurovoc).</summary>
	<description locale="en"><![CDATA[<p>NV Metadata Curation enriches OJS submission metadata with controlled vocabularies from SKOS/SPARQL thesauri (UNESCO, Rameau BnF, Eurovoc EU).</p><p>Features: autocomplete keyword suggestions, ORCID/ROR contributor lookup with human validation, 3 interaction modes (suggestion/choice/bypass), backoffice metadata audit dashboard, freemium gating (50 req/day free), and full trilingual support (EN/ES/FR).</p><p>Requires OJS 3.4.x and PHP 8.1+.</p>]]></description>
	<description locale="fr_FR"><![CDATA[<p>NV Metadata Curation enrichit les métadonnées de soumission OJS avec des vocabulaires contrôlés issus de thésaurus SKOS/SPARQL (UNESCO, Rameau BnF, Eurovoc UE).</p><p>Fonctionnalités : autocomplétion par thésaurus, recherche ORCID/ROR avec validation humaine, 3 modes d'interaction (suggestion/choix/bypass), tableau d'audit backoffice, modèle freemium (50 req/jour gratuit), support trilingue complet (EN/ES/FR).</p><p>Requiert OJS 3.4.x et PHP 8.1+.</p>]]></description>
	<maintainer>
		<name>Ne Varietur</name>
		<institution>Ne Varietur</institution>
		<email>contact@nevarietur.com</email>
	</maintainer>
	<release date="2026-04-22" version="1.0.0.0" md5="71e60181411c9b5d84d7264f747edcb3">
		<package>{RELEASE_URL}/nvMetadataCuration-1.0.0.0.tar.gz</package>
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
		<description locale="en">Initial release: SKOS/SPARQL autocomplete, ORCID/ROR lookup, 3 interaction modes, metadata audit, freemium, trilingual EN/ES/FR.</description>
	</release>
</plugin>
```

---

## 4. Checklist pré-soumission

- [x] `version.xml` conforme DTD `pluginVersion.dtd` (4 chiffres : 1.0.0.0)
- [x] `LICENSE` GPL v3 inclus dans le tarball
- [x] Locales : en, es, fr_FR
- [x] Aucune dépendance externe runtime
- [x] Plugin extends `GenericPlugin` (OJS 3.4)
- [x] Namespace PSR-4 correct
- [x] Tests unitaires inclus (5 suites)
- [x] Tarball structure : `nvMetadataCuration/` racine unique
- [x] MD5 calculé
- [ ] GitHub repo public créé
- [ ] GitHub Release publiée avec tarball
- [ ] PR ouverte sur `pkp/plugin-gallery`
