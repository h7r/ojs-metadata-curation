# PKP Plugin Gallery — Dossier de soumission

**Plugin :** NV Metadata Curation
**Version :** 2.0.4.1
**Date :** 2026-10-13
**Catégorie :** generic
**Licence :** GPL v3

---

## 1. Tarball de release

Fichier : `nvMetadataCuration-2.0.4.1.tar.gz`
MD5 : `b5395cf829409e028e3e34e8d30ace1a`

Reconstruit avec :
```bash
tar czf nvMetadataCuration-2.0.4.1.tar.gz \
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
git tag -a v2.0.4.1 -m "v2.0.4.1 — audit.tpl escaping, semver compatibility (#13)"
git push origin v2.0.4.1
```

### 2b. Créer la GitHub Release

```bash
gh release create v2.0.4.1 \
  nvMetadataCuration-2.0.4.1.tar.gz \
  --title "v2.0.4.1 — PKP Gallery review fixes" \
  --notes "Fixes from the PKP maintainer review (pkp/plugin-gallery#485).

Changes:
- Security (#13): audit.tpl escapes every row value (statuses, counts, ratios), not only the title, plus the translated aria-label
- Gallery (#13): OJS compatibility declared as ~3.4.0.0

Features (unchanged):
- SPARQL autocomplete (UNESCO, Rameau, Eurovoc)
- ORCID/ROR contributor lookup with human validation
- Backoffice metadata audit dashboard
- Trilingual: EN, ES, FR
- Free and unlimited access"
```

### 2c. Soumettre la PR sur pkp/plugin-gallery

1. Forker `https://github.com/pkp/plugin-gallery`
2. Éditer `plugins.xml` — ajouter le bloc XML ci-dessous
3. Ouvrir une Pull Request

---

## 3. Entrée XML pour `plugins.xml`

> **IMPORTANT :** MD5 et URL du `<package>` sont ceux du tarball publié sur GitHub Releases.
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
		<institution>Independent</institution>
		<email>wilfriedfouillaret@gmail.com</email>
	</maintainer>
	<release date="2026-10-13" version="2.0.4.1" md5="b5395cf829409e028e3e34e8d30ace1a">
		<package>https://github.com/h7r/ojs-metadata-curation/releases/download/v2.0.4.1/nvMetadataCuration-2.0.4.1.tar.gz</package>
		<compatibility application="ojs2">
			<version>~3.4.0.0</version>
		</compatibility>
		<description locale="en">First release for the PKP Plugin Gallery — SPARQL keyword autocomplete, validated ORCID/ROR contributor lookup, backoffice metadata audit dashboard, trilingual EN/ES/FR. Free and unlimited. Includes output escaping in the audit dashboard template.</description>
	</release>
</plugin>
```

---

## 4. Checklist pré-soumission

- [x] `version.xml` conforme DTD `pluginVersion.dtd` (4 chiffres : 2.0.4.1)
- [x] `LICENSE` GPL v3 inclus dans le tarball
- [x] Locales : en, es, fr_FR (fichiers `.po` **conservés** dans le tarball)
- [x] Aucune dépendance externe runtime
- [x] Plugin extends `GenericPlugin` (OJS 3.4)
- [x] Namespace PSR-4 correct
- [x] Tests unitaires inclus (5 suites — `RateLimitManagerTest` retiré en v2.0)
- [x] Tarball structure : `nvMetadataCuration/` racine unique
- [x] Compatibilité déclarée en semver `~3.4.0.0` (demande du mainteneur PKP, #13) au lieu de la liste `3.4.0.0` → `3.4.0.10`
- [x] GitHub repo public : `github.com/h7r/ojs-metadata-curation`
- [x] `audit.tpl` : `|escape` sur toutes les valeurs de ligne et sur le libellé traduit de l'`aria-label` (#13)
- [x] MD5 calculé pour `nvMetadataCuration-2.0.4.1.tar.gz` (tarball construit depuis `main` @ 24e49de, revérifié sur le fichier téléchargé) : `b5395cf829409e028e3e34e8d30ace1a`
- [x] Tag `v2.0.4.1` poussé
- [x] GitHub Release publiée avec tarball : https://github.com/h7r/ojs-metadata-curation/releases/tag/v2.0.4.1
- [ ] Bloc `plugins.xml` de la PR https://github.com/pkp/plugin-gallery/pull/485 mis à jour (2.0.4.1 remplace 2.0.4.0, `~3.4.0.0`), validé contre `plugins.xsd`

Historique : v2.0.4.0 (2026-04-26, MD5 `f5a754ca27ff2d24f7b0d9544f7b483f`) a ouvert la PR #485 ; elle est remplacée par 2.0.4.1 dans le bloc de la galerie, jamais fusionnée.
