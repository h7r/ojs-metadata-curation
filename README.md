# Plugin OJS — Curation Métadonnées Ne Varietur

Plugin OJS open source de curation de métadonnées par thésaurus contrôlés.
Distribution via **PKP Plugin Gallery** (40 000 installations actives).
Modèle freemium : plugin gratuit + service NV payant via API key.

## Contexte

4 obligations réglementaires convergentes en 2026 rendent ce plugin **Must-have** :

| Déclencheur | Échéance |
|-------------|----------|
| CNEAI BOE-A-2025-26118 — DOI obligatoire pour sexenios | 2026 |
| Schémas Crossref dépréciés | fin 2026 |
| Convocatoria FECYT : DOI + mots-clés bilingues + ORCID | bisannuelle |
| DOAJ-Crossref partenariat renouvelé — plancher qualité rehaussé | continu |

## Architecture

```
Formulaire auteur OJS
  └── kwd-group inline
        ├── Lookup SKOS/SPARQL → termes candidats valides (avec URI)
        ├── LLM → explication sémantique pour aider le choix
        └── Validation humaine obligatoire

Backoffice éditeur
  └── Audit backstock (champs manquants, non conformes FECYT)
```

Thésaurus supportés : **UNESCO** · **ISOC (CSIC)** · **Rameau (BnF)**
Couverture estimée : ~75–80 % des disciplines SHS ES/FR.

## Phases

| Phase | Responsable | Statut |
|-------|-------------|--------|
| Phase 1 — Sketch (architecture UX + specs) | Camille | **En cours** |
| Phase 2 — PoC (plugin PHP OJS minimal) | Étienne | À planifier |
| Phase 3 — Implémentation complète + PKP Gallery | Étienne + NV | Backlog |

Voir `PLAN.md` pour le détail des livrables et critères de passage.

## Installation locale (développement)

### Prérequis

- PHP 8.1+
- Instance OJS 3.4.x fonctionnelle ([guide d'installation PKP](https://docs.pkp.sfu.ca/admin-guide/en/install))

### Déploiement du plugin

```bash
# Depuis la racine de l'installation OJS :
cp -r /chemin/vers/plugin-ojs/plugin plugins/generic/nvMetadataCuration
```

Puis dans OJS : **Paramètres → Site web → Modules → Modules externes installés** → activer « NV Metadata Curation ».

### Structure du plugin

```
plugin/
├── NvMetadataCurationPlugin.php   # Classe principale (GenericPlugin)
├── index.php                       # Point d'entrée
├── version.xml                     # Métadonnées PKP
├── settings.xml                    # Réglages par défaut
├── classes/                        # Logique métier (Phase 2)
├── templates/                      # Templates Smarty (Phase 2)
├── js/                             # JavaScript côté client (Phase 2)
├── css/                            # Feuilles de style (Phase 2)
└── locale/
    ├── en/locale.xml               # Anglais
    ├── es/locale.xml               # Espagnol
    └── fr_FR/locale.xml            # Français
```

## Décisions C-K (session NEV-128)

- `C3b — Plugin OJS freemium PKP Gallery` → **decidable_true**
- `C1b — Résolution ORCID/ROR via API` → **decidable_true** (validation humaine obligatoire)
- `Validation MD par thésaurus` → **decidable_true**
- Architecture thésaurus : lookup SKOS/SPARQL déterministe, **pas** de matching LLM
- `C1a-β — Extraction LLM directe sur PDF` → en évaluation (benchmark en cours)
