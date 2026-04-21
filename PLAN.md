# Plan — Plugin OJS NV

*Head of Design : Camille · Dernière révision : 2026-04-21*

---

## Phase 1 — Sketch (Camille, en cours)

**Principe :** définir le *quoi* avant le *comment*. Zéro code PHP dans cette phase.

### Livrables

| Livrable | Chemin | Statut |
|----------|--------|--------|
| Architecture UX complète | `docs/ux-spec.md` | **Produit** |
| Spécification SKOS/SPARQL | `docs/thesaurus-api.md` | **Produit** |
| Wireframe formulaire auteur | `design/wireframes/formulaire-auteur.excalidraw` | **Produit** |
| Wireframe backoffice éditeur | `design/wireframes/backoffice-editeur.excalidraw` | **Produit** |
| Résultat benchmark C1a-β | `docs/benchmark-c1ab.md` | À produire |

### Contenu attendu par livrable

#### `docs/ux-spec.md` — Architecture UX
- **Persona central :** coordinateur éditorial (travaille dans OJS, non-technique)
- **Surface 1 — Formulaire auteur inline :**
  - Champs couverts MVP : `kwd-group` (mots-clés discipline), puis `aff` (affiliations), `contrib-id` (ORCID)
  - 3 modes d'interaction : `suggestion` (proposé, modifiable) / `choix` (liste obligatoire thésaurus) / `bypass` (champ libre avec avertissement)
  - Score de confiance visible par champ
  - Langue : ES + FR (bilingue obligatoire pour FECYT)
- **Surface 2 — Backoffice éditeur :**
  - Audit arrière-stock : tableau de conformité par champ × article
  - Export rapport (CSV / affichage OJS)
  - Priorité : champs les plus défaillants en premier (kwd-group, aff, contrib-id)

#### `docs/thesaurus-api.md` — Spec SKOS/SPARQL
- Interface attendue par le plugin (côté PHP OJS)
- Protocole de lookup : endpoint SPARQL → requête → réponse (termes + URI)
- Gestion des 3 thésaurus (UNESCO, ISOC, Rameau) — configurable par revue
- Fallback si endpoint indisponible

#### `design/wireframes/` — Maquettes
- Format : Excalidraw (`.excalidraw`)
- Maquette 1 : formulaire auteur, champ kwd-group avec suggestion inline
- Maquette 2 : backoffice éditeur, vue audit

#### `docs/benchmark-c1ab.md` — C1a-β
- Protocole : script `scripts/llm_eval.py` sur corpus SHS ES/FR (15–20 articles)
- Métriques : précision par champ vs saisie directe, coût cognitif estimé
- Décision attendue : C1a-β `decidable_true` ou `decidable_false`

### Critères de passage Phase 2

- [ ] Architecture UX validée par le CEO
- [x] Spec SKOS/SPARQL produite — en attente relecture Étienne
- [x] Wireframes formulaire auteur + backoffice produits
- [ ] C1a-β décidé (benchmark LLM terminé)

---

## Phase 2 — PoC (Étienne)

Plugin PHP OJS fonctionnel minimal, sur instance locale.

**Scope MVP :**
- 1 thésaurus : UNESCO uniquement
- 1 champ : `kwd-group`
- 1 mode : `suggestion` (pas encore choix forcé ni bypass)
- Formulaire auteur : suggestion inline au focus du champ
- Pas de backoffice dans le PoC

**Critère de passage Phase 3 :**
- Suggestion fonctionne sur une instance OJS locale de test
- Temps de réponse lookup SPARQL < 500 ms (acceptable pour UX inline)
- Revue interne avec Camille sur le rendu visuel

---

## Phase 3 — Implémentation complète

- Multilingue ES/FR (toutes surfaces)
- Thésaurus : UNESCO + ISOC + Rameau (configurable par revue dans plugin settings)
- Surface backoffice éditeur (audit arrière-stock)
- Intégration ORCID/ROR (C1b, validation humaine obligatoire)
- API key NV — modèle freemium (plugin gratuit, service payant via clé)
- Préparation dossier PKP Plugin Gallery

---

## Dépendances

| Dépendance | Propriétaire | Statut |
|------------|-------------|--------|
| Résultats benchmark Grobid ([NEV-133](/NEV/issues/NEV-133)) | Nuria | done |
| ANTHROPIC_API_KEY pour C1a-β | WF / Board | Activée 2026-04-21 |
| Instance OJS locale pour tests | Étienne | À provisionner Phase 2 |
| Endpoint SPARQL thésaurus UNESCO | Étienne | À provisionner Phase 2 |
