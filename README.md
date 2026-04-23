# NV Metadata Curation — Plugin OJS

Plugin OJS open source de curation de métadonnées par vocabulaires contrôlés (SKOS/SPARQL).
Distribution via **PKP Plugin Gallery**. Modèle freemium : plugin gratuit, service NV payant via clé API.

**Version :** 1.0.0 · **OJS :** 3.4.x · **Licence :** GPL v3

## Fonctionnalités

| Fonctionnalité | Description |
|----------------|-------------|
| **Autocomplete thésaurus** | Recherche SKOS/SPARQL dans UNESCO, Rameau (BnF), Eurovoc (UE) |
| **Multi-thésaurus** | Configurable par revue dans les paramètres du plugin |
| **3 modes d'interaction** | Suggestion (proposé, modifiable) · Choix (sélection obligatoire) · Bypass (texte libre + avertissement) |
| **ORCID lookup** | Recherche dans le registre ORCID public, validation humaine obligatoire |
| **ROR lookup** | Recherche d'affiliations institutionnelles via l'API ROR |
| **Backoffice audit** | Tableau de conformité des métadonnées par soumission (mots-clés, ORCID, affiliations) |
| **Freemium** | 50 requêtes/jour gratuit, illimité avec clé API NV |
| **Trilingue** | Interface EN / ES / FR complète |
| **Accessible** | Navigation clavier, ARIA, high contrast, reduced motion |

## Installation

### Prérequis

- PHP 8.1+
- OJS 3.4.x ([guide d'installation PKP](https://docs.pkp.sfu.ca/admin-guide/en/install))

### Déploiement

```bash
# Depuis la racine de l'installation OJS :
cp -r /chemin/vers/plugin-ojs/plugin plugins/generic/nvMetadataCuration
```

Dans OJS : **Paramètres > Site web > Modules > Modules externes installés** > activer « NV Metadata Curation ».

### Configuration

Après activation, cliquez sur **Paramètres** à côté du plugin pour :

1. **Thésaurus** — cocher les vocabulaires actifs (UNESCO, Rameau, Eurovoc)
2. **Mode d'interaction** — choisir comment les suggestions s'affichent aux auteurs
3. **Clé API NV** — saisir la clé pour un accès illimité (optionnel)

### Docker (développement)

Un fichier `docker-compose.yml` est fourni à la racine. Il lance OJS 3.4.x + MariaDB avec le plugin monté en bind-mount :

```bash
docker compose up -d
# OJS accessible sur http://localhost:8080
# Le plugin est monté live — toute modification de plugin/ est reflétée sans rebuild.
```

Pour arrêter : `docker compose down` (ajouter `-v` pour purger les volumes).

## Architecture

```
Formulaire auteur OJS (métadonnées, step 3)
  ├── kwd-group : autocomplete SKOS/SPARQL (UNESCO · Rameau · Eurovoc)
  ├── ORCID : lookup registre public + validation humaine
  └── Affiliation : lookup ROR + validation humaine

Backoffice éditeur (/nv-metadata-audit)
  └── Tableau conformité : mots-clés × ORCID × affiliations par soumission
```

### Endpoints

| Route | Méthode | Description |
|-------|---------|-------------|
| `/nv-metadata-suggest/suggest` | POST | Recherche SPARQL dans le thésaurus |
| `/nv-metadata-suggest/save` | POST | Persiste les mots-clés sélectionnés |
| `/nv-metadata-suggest/orcid` | POST | Recherche ORCID par nom d'auteur |
| `/nv-metadata-suggest/ror` | POST | Recherche ROR par nom d'institution |
| `/nv-metadata-audit` | GET | Tableau d'audit métadonnées (éditeurs) |

### Structure

```
plugin/
├── NvMetadataCurationPlugin.php      # Classe principale (GenericPlugin)
├── index.php                          # Point d'entrée
├── version.xml                        # v1.0.0.0
├── settings.xml                       # Réglages par défaut
├── classes/
│   ├── forms/
│   │   └── SettingsForm.php           # Formulaire de configuration
│   ├── handlers/
│   │   ├── SuggestHandler.php         # Routes suggest/save/orcid/ror
│   │   └── AuditHandler.php           # Backoffice audit
│   └── managers/
│       ├── SparqlLookupManager.php    # Requêtes SPARQL (UNESCO/Rameau/Eurovoc)
│       └── OrcidRorManager.php        # Lookup ORCID + ROR
├── templates/
│   ├── settings.tpl                   # Formulaire paramètres
│   └── audit.tpl                      # Vue audit backoffice
├── js/
│   ├── keyword-lookup.js              # Widget autocomplete thésaurus
│   └── orcid-ror-lookup.js            # Widget ORCID/ROR
├── css/
│   ├── keyword-lookup.css             # Styles autocomplete + validation
│   └── audit.css                      # Styles tableau audit
└── locale/
    ├── en/locale.xml
    ├── es/locale.xml
    └── fr_FR/locale.xml
```

## Tests

### Test manuel sur instance OJS

1. Activer le plugin dans une revue de test
2. Configurer les thésaurus dans les paramètres
3. Créer une nouvelle soumission → étape 3 (métadonnées)
4. Taper un mot-clé (min. 3 caractères) → vérifier les suggestions
5. Sélectionner un terme → vérifier le tag chip
6. Naviguer au clavier (Tab, flèches, Enter, Escape)
7. Vérifier la page audit : `/index.php/{journal}/nv-metadata-audit`

### Vérifications accessibilité

- Navigation clavier complète sur le dropdown (ArrowUp/Down, Enter, Escape)
- Attributs ARIA : `role="combobox"`, `aria-expanded`, `aria-activedescendant`, `role="listbox"`
- Labels sur tous les boutons interactifs
- Support high contrast (forced-colors)
- Support reduced-motion

## Thésaurus supportés

| Thésaurus | Endpoint SPARQL | Langues | Couverture |
|-----------|----------------|---------|------------|
| **UNESCO** | `vocabularies.unesco.org/sparql` | ES, FR, EN (+ 20 langues) | ~4 400 concepts, SHS généraliste |
| **Rameau** | `data.bnf.fr/sparql` | FR | ~170 000 vedettes-matière |
| **Eurovoc** | `publications.europa.eu/webapi/rdf/sparql` | 24 langues UE | ~7 000 concepts, multidisciplinaire |

## Modèle freemium

- **Gratuit** : 50 requêtes SPARQL/ORCID/ROR par jour et par revue
- **Payant** : accès illimité avec une clé API Ne Varietur (saisie dans les paramètres du plugin)

Le compteur se réinitialise chaque jour. La sauvegarde des mots-clés n'est pas soumise à la limite.

## Security posture

- **Zéro dépendance en production** — aucun package Composer ou npm, surface d'attaque minimale (review par le PKP Plugin Gallery facilitée).
- **Écritures protégées CSRF** — `save` (mots-clés) et `saveContributorIds` (ORCID/ROR) valident un token de session côté serveur et contrôlent Origin/Referer contre `BaseUrl`. Les endpoints lookup (`suggest`, `orcid`, `ror`) restent en lecture seule.
- **Requêtes SPARQL sanitisées** — échappement strict des littéraux envoyés aux endpoints UNESCO, Rameau et Eurovoc (pas de concaténation directe).
- **Rate limiting atomique** — `flock()` par fichier, pas de race TOCTOU. Modèle freemium : 50 requêtes/jour/revue en gratuit, illimité avec clé API.
- **Validation serveur ORCID/ROR** — format + checksum ISO 7064 (C1b) ; aucune confiance au seul frontend.
- **IDOR** — chaque soumission ciblée est reliée au contexte courant et à un rôle participant / manager avant toute écriture.

## Licence

GNU General Public License v3.0. Voir [LICENSE](LICENSE).

## Soumission PKP Plugin Gallery

Le plugin est préparé pour soumission au [PKP Plugin Gallery](https://pkp.sfu.ca/software/ojs/plugin-gallery/) :

- `version.xml` conforme au DTD `pluginVersion.dtd`
- Lazy-load activé
- 3 locales complètes (en, es, fr_FR)
- Aucune dépendance externe (pas de Composer, pas de npm)
- Compatible OJS 3.4.x
