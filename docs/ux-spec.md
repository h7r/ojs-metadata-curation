# Architecture UX — Plugin OJS NV

*Camille, Head of Design · Version 1.0 · 2026-04-21*

---

## 1. Persona central

**Coordinateur éditorial** — persona issu de la session C-K (K design 2026-04-21).

| Attribut | Valeur |
|----------|--------|
| Rôle | Secrétaire de rédaction ou coordinateur éditorial d'une revue SHS |
| Contexte | Travaille dans OJS, gère 2–4 numéros/an, équipe de 1–3 personnes |
| Compétence numérique | Intermédiaire — maîtrise OJS, ne connaît pas SPARQL ni les thésaurus |
| Langue de travail | ES ou FR (bilingue requis) |
| Pain point principal | Mots-clés libres, non bilingues, hors vocabulaire — signalés à la certification FECYT |
| Motivation | Passer la certification FECYT, alléger la charge de correction avant soumission Crossref |
| Anti-pattern à éviter | Forcer un workflow technique — doit rester transparent dans OJS |

---

## 2. Surfaces du plugin

### Surface 1 — Formulaire auteur (inline)

Intégration dans le formulaire de soumission OJS existant, au niveau du champ `kwd-group`.

#### 2.1 Comportement de base

```
[Champ mots-clés OJS existant]
┌─────────────────────────────────────────────────────┐
│ Mots-clés (discipline) *                            │
│ ┌─────────────────────────────────────┐  [+ Ajouter]│
│ │ sociol...                           │             │
│ └─────────────────────────────────────┘             │
│ ┌─ Suggestions NV ──────────────────────────────┐  │
│ │ ✓ Sociologie urbaine  (UNESCO 5.3.2)  [→ ES]  │  │
│ │ ✓ Sociologie du travail (UNESCO 5.3.4) [→ ES]  │  │
│ │   Autre terme…                                 │  │
│ └────────────────────────────────────────────────┘  │
│ Thésaurus : [UNESCO ▼]                              │
└─────────────────────────────────────────────────────┘
```

- Le panneau de suggestions s'ouvre **au focus** du champ, pas au clic d'un bouton
- Suggestions : termes candidates avec URI et traduction EN/ES si disponible
- Score de confiance : visible uniquement si < 80 % (sinon bruit inutile)
- Maximum 5 suggestions affichées (charge cognitive)

#### 2.2 Mode d'interaction : suggestion-only

Depuis v2.0.1 le plugin expose un seul mode : **suggestion**. L'auteur voit les termes proposés, peut en accepter un (validation humaine), modifier son texte, ou ignorer la proposition. Le champ OJS natif (Publication::keywords) reçoit le terme sélectionné ; la métadonnée NV (URI, thésaurus, lang, `kwd_validated`) est persistée en parallèle.

> **Note de rétractation.** Les versions antérieures exposaient aussi les modes `choice` (sélection obligatoire dans la liste) et `bypass` (texte libre avec avertissement). Ces deux modes ont été retirés en v2.0.1 ([GST-11](../plugin/)) : aucun caller réel n'en avait demandé l'activation, et la surface multi-modes forçait trois branches i18n/CSS/JS à rester en parité avec la branche suggestion. La page de paramétrage du plugin ne propose plus de sélecteur de mode.

> **Multi-thésaurus UI.** L'onglet de bascule entre thésaurus (UNESCO / Rameau / Eurovoc) côté auteur n'a pas été relandé en v2.0.1. Un seul thésaurus est actif par revue, configuré par l'administrateur OJS. L'UI multi-thésaurus est suivie en backlog (GST-32, v2.1+) et sera rouverte seulement sur demande utilisateur réelle post-soumission PKP gallery.

#### 2.3 Gestion bilingue (FECYT)

- Pour chaque terme validé : afficher le couple ES + EN (ou FR + EN)
- Si un seul terme est accepté : proposer la traduction automatique (LLM) **comme suggestion**, validation humaine obligatoire
- Indicateur visuel : `[ES ✓]` / `[EN ✓]` / `[EN ?]` par terme

#### 2.4 Champs couverts par phase

| Champ OJS | Phase 1 MVP | Phase 3 |
|-----------|-------------|---------|
| `kwd-group` (mots-clés) | ✓ | ✓ |
| `aff` (affiliations) | — | ✓ (ROR lookup) |
| `contrib-id` (ORCID) | — | ✓ (validation humaine) |
| `abstract` secondaire (bilingue) | — | ✓ |

---

### Surface 2 — Backoffice éditeur

Accessible dans OJS > Outils > Curation Métadonnées NV.

#### 2.5 Vue audit arrière-stock

```
Audit de conformité — Revue Ejemplo de Sociología
────────────────────────────────────────────────────────────
Période : [Jan 2023 ▼] — [Déc 2025 ▼]   [Exporter CSV]

  Article                    kwd-group  aff    ORCID  Score
  ──────────────────────────────────────────────────────────
  López et al. (2024-3)     ⚠ libre    ✓      —      72 %
  García García (2024-2)    ✓ UNESCO   ⚠ ROR?  ✓     89 %
  Martínez (2023-4)         ✗ vide     ✗       —      41 %
  …
  ──────────────────────────────────────────────────────────
  12 articles · Conformité moyenne : 74 %
  [Corriger les ⚠ et ✗]  →  Ouvre l'article en édition OJS
```

- Tri par score croissant par défaut (les plus urgents en tête)
- Filtre : par champ, par volume, par score seuil
- Action directe : lien vers l'article en édition OJS (pas de correction dans le backoffice)

#### 2.6 Vue paramètres plugin

```
Paramètres NV Metadata
──────────────────────────────────────────────────────
Thésaurus principal :   [UNESCO (Thésaurus) ▼]
Thésaurus secondaire :  [Eurovoc (UE) ▼]         [+ Ajouter]
Mode formulaire auteur : [Suggestion ▼]
Langue par défaut :     [Espagnol (ES) ▼]
──────────────────────────────────────────────────────
[Enregistrer]
```

---

## 3. Principes de design

### 3.1 Charge cognitive minimale

- Le plugin ne doit **pas** introduire de nouveau concept pour l'auteur (pas de « SPARQL », pas de « URI »)
- Les URIs thésaurus sont stockés côté base de données OJS, jamais exposés dans l'UI
- Zéro écran intermédiaire entre la soumission et la suggestion (inline = pas de popup modale)

### 3.2 Dégradation gracieuse

- Si l'endpoint SPARQL est indisponible : le champ fonctionne en mode `bypass` silencieux (pas de suggestion, pas de blocage)

### 3.3 Accessibilité

- Navigation clavier dans la liste de suggestions (flèches + Entrée)
- `aria-live` sur le panneau de suggestions (annonce lecteur d'écran)
- Contraste minimum AA sur les indicateurs de statut (`[ES ✓]`, `⚠`)

### 3.4 Design tokens

Le plugin hérite du thème OJS actif. Aucun token visuel propriétaire dans la Phase 1 MVP.
Pour les éléments NV spécifiques (bandeau, badge score), utiliser les tokens définis dans `C:\projets\ne-varietur-design-system\colors_and_type.css` :
- Couleur accent NV : `var(--nv-orange)` — à ne pas hardcoder
- Police : héritée d'OJS, pas de chargement externe

---

## 4. Flux auteur — soumission standard

```
Auteur démarre soumission OJS
    │
    ▼
Étape 3 « Métadonnées »
    │
    ├── Focus champ kwd-group
    │       │
    │       ▼
    │   [Plugin NV] Lookup SPARQL → termes candidats
    │       │
    │       ▼
    │   Panneau suggestions inline (≤ 5 termes)
    │       │
    │       ├── Auteur accepte un terme → URI stockée, tag affiché
    │       ├── Auteur modifie → retour lookup
    │       └── Auteur ignore → terme libre (avertissement si mode=choix)
    │
    ▼
Soumission complète → métadonnées conformes thésaurus
```

---

## 5. Questions ouvertes pour la Phase 2

Ces points nécessitent une décision technique d'Étienne avant le PoC :

1. **Hook OJS :** quel point d'extension PKP utiliser pour l'inline dans le formulaire auteur ? (`TemplateManager::fetch` hook ou composant Smarty dédié ?)
2. **Stockage URI :** les URIs thésaurus sont-elles stockées dans `submission_settings` ou dans un champ `kwd-group` étendu ?
3. **Appel SPARQL :** côté client (JS → proxy PHP) ou côté serveur uniquement (PHP → endpoint SPARQL) ? Implications CORS et latence.
4. **Version OJS cible :** OJS 3.3.x ou 3.4.x uniquement ? La compatibilité ascendante conditionne l'API plugin.
