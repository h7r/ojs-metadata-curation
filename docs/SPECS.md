# SPECS.md — Vocabulaires SKOS cibles et API de suggestion métadonnées

*Nuria, Research Engineer — Ne Varietur · v1.0 · 2026-04-21*
*Issue : NEV-187 · Destinataire : Étienne (implémentation Phase 2)*

---

## 1. Périmètre de ce document

Ce document complète [`docs/thesaurus-api.md`](./thesaurus-api.md) produit par Camille (design) en ajoutant :

- La validation technique des endpoints SPARQL (disponibilité, formats, rate limits réels)
- Une correction importante sur l'endpoint ISOC
- Les requêtes SPARQL adaptées à chaque thésaurus avec les namespaces exacts
- La spec d'interface PHP du proxy SPARQL
- La réponse aux questions OJS ouvertes dans `thesaurus-api.md` §9

---

## 2. Inventaire des endpoints SPARQL — état validé

### 2.1 UNESCO Thesaurus

| Attribut | Valeur |
|----------|--------|
| **Endpoint SPARQL** | `https://vocabularies.unesco.org/sparql` |
| **Interface web** | `https://vocabularies.unesco.org/sparql-form/` |
| **Disponibilité** | Public, sans authentification |
| **Backend** | Apache Jena Fuseki (SKOSMOS) |
| **Rate limits** | Limite documentée : 25 000 résultats par requête ; pas de rate limit temporel publié |
| **Formats réponse** | `application/sparql-results+json`, `application/sparql-results+xml`, `text/turtle`, `application/ld+json`, `application/n-triples` |
| **Langues** | EN, ES, FR, AR, RU, ZH |
| **Schéma** | SKOS pur, compliant ISO 25964 |
| **Taille corpus** | ~4 400 concepts, 7 microthésaurus thématiques |
| **URIs pérennes** | Oui (`http://vocabularies.unesco.org/thesaurus/conceptXXXX`) |

**Recommandation Phase 2 :** endpoint prioritaire pour le PoC. Le plus stable et documenté.

---

### 2.2 Rameau (BnF)

| Attribut | Valeur |
|----------|--------|
| **Endpoint SPARQL** | `https://data.bnf.fr/sparql` |
| **Interface web** | Yasgui intégré à `https://data.bnf.fr/sparql` |
| **Disponibilité** | Public, sans authentification |
| **Backend** | Non documenté publiquement |
| **Rate limits** | Pas de limite formelle publiée ; recommandation : LIMIT systématique dans les requêtes |
| **Formats réponse** | `application/sparql-results+json`, `application/sparql-results+xml`, `application/ld+json`, `text/n3`, `application/rdf+json` |
| **Langues** | FR (autorité BnF), quelques libellés EN sur les notices liées |
| **Taille corpus** | 650 M+ triples (l'ensemble du LOD BnF — Rameau est une partie) |
| **URIs** | `http://data.bnf.fr/ark:/12148/cbXXXXXXXXX` (ARK) |

**Attention :** Rameau est embarqué dans le graphe LOD global BnF. Les requêtes doivent filtrer sur `skos:inScheme <http://rameau.bnf.fr/>` pour rester dans le périmètre du thésaurus.

---

### 2.3 ISOC (CSIC) — ⚠ Correction critique

**L'URL indiquée dans `thesaurus-api.md` est incorrecte pour le cas d'usage SHS.**

| Élément | Détail |
|---------|--------|
| **URL dans thesaurus-api.md** | `https://tesauros.mecd.es/tesauros/sparql` |
| **URL actuelle correspondante** | `https://tesauros.cultura.gob.es/tesauros/sparql` (domaine migré) |
| **Contenu réel** | Dictionnaires du Patrimoine Culturel d'Espagne (biens culturels, matériaux, mobilier) |
| **Pertinence pour revues SHS** | **Non pertinent** — c'est un thésaurus de conservation-restauration, pas un thésaurus disciplinaire de sciences humaines |

**ISOC-CSIC (le vrai) :**
- ISOC est la base bibliographique de CSIC pour les sciences sociales et humaines hispanophones
- **Il n'existe pas d'endpoint SPARQL public ISOC-CSIC confirmé**
- Les données ISOC ne sont pas publiées en LOD/SKOS accessible publiquement
- Accès via portail web : `https://bddoc.csic.es:8080/isoc.html` (pas de SPARQL)

**Impact sur l'architecture :** Le thésaurus "ISOC" tel que spécifié par Camille n'est pas intégrable via SPARQL en l'état. Deux alternatives :

| Alternative | Endpoint | Pertinence SHS | Note |
|-------------|----------|----------------|------|
| **Eurovoc** | `https://publications.europa.eu/webapi/rdf/sparql` | Bonne (politique, droit, économie, SHS) | 24 langues EU dont ES/FR |
| **AGROVOC** | `https://agrovoc.fao.org/sparql` | Limitée | Agriculture/alimentation — hors scope |
| **Thésaurus BSN** | Non public en SPARQL | — | Bibliothèque scientifique numérique FR |

**Recommandation :** substituer ISOC par Eurovoc pour la Phase 3. Eurovoc couvre les SHS politiques et sociales, est stable, multilingue (ES + FR + EN), et dispose d'un endpoint SPARQL public maintenu par l'Office des publications de l'UE.

---

### 2.4 Eurovoc (ajout recommandé Phase 3)

| Attribut | Valeur |
|----------|--------|
| **Endpoint SPARQL** | `https://publications.europa.eu/webapi/rdf/sparql` |
| **Disponibilité** | Public, sans authentification |
| **Rate limits** | Pas de limite formelle publiée |
| **Formats réponse** | `application/sparql-results+json`, `application/sparql-results+xml` |
| **Langues** | 24 langues officielles EU dont ES, FR, EN |
| **Taille** | ~7 000 concepts, très structurés |
| **URIs** | `http://eurovoc.europa.eu/XXXX` |

---

## 3. Requêtes SPARQL validées par thésaurus

### 3.1 UNESCO — lookup par préfixe (bilingue ES/EN)

```sparql
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>

SELECT DISTINCT ?concept ?labelPrimary ?labelEN ?notation ?broader ?broaderLabel
WHERE {
  ?concept a skos:Concept ;
           skos:prefLabel ?labelPrimary ;
           skos:prefLabel ?labelEN .

  OPTIONAL {
    ?concept skos:notation ?notation .
  }
  OPTIONAL {
    ?concept skos:broader ?broader .
    ?broader skos:prefLabel ?broaderLabel .
    FILTER(lang(?broaderLabel) = "es")
  }

  FILTER(lang(?labelPrimary) = "es")
  FILTER(lang(?labelEN) = "en")
  FILTER(strstarts(lcase(str(?labelPrimary)), lcase("{{INPUT_PREFIX}}")))
}
ORDER BY ?labelPrimary
LIMIT 5
```

**Variables :**
- `{{INPUT_PREFIX}}` : saisie normalisée (minuscules, sans diacritiques optionnel)

**Accès SPARQL (GET) :**
```
GET https://vocabularies.unesco.org/sparql?query=<ENCODED_QUERY>&format=application%2Fsparql-results%2Bjson
```

---

### 3.2 Rameau (BnF) — lookup par préfixe (FR)

```sparql
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

SELECT DISTINCT ?concept ?label ?altLabel ?broader ?broaderLabel
WHERE {
  GRAPH <http://data.bnf.fr/> {
    ?concept a skos:Concept ;
             skos:inScheme <http://rameau.bnf.fr/> ;
             skos:prefLabel ?label .

    OPTIONAL { ?concept skos:altLabel ?altLabel . FILTER(lang(?altLabel) = "fr") }
    OPTIONAL {
      ?concept skos:broader ?broader .
      ?broader skos:prefLabel ?broaderLabel .
      FILTER(lang(?broaderLabel) = "fr")
    }

    FILTER(lang(?label) = "fr")
    FILTER(strstarts(lcase(str(?label)), lcase("{{INPUT_PREFIX}}")))
  }
}
ORDER BY ?label
LIMIT 5
```

**Note :** La clause `GRAPH <http://data.bnf.fr/>` et `skos:inScheme <http://rameau.bnf.fr/>` sont obligatoires pour isoler Rameau du reste du LOD BnF. Sans elles, la requête interroge l'ensemble des 650 M triples et sera très lente.

---

### 3.3 Eurovoc — lookup bilingue ES/FR

```sparql
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
PREFIX dc:   <http://purl.org/dc/elements/1.1/>

SELECT DISTINCT ?concept ?labelPrimary ?labelTranslation ?broader ?broaderLabel
WHERE {
  ?concept a skos:Concept ;
           skos:inScheme <http://eurovoc.europa.eu/100141> ;
           skos:prefLabel ?labelPrimary ;
           skos:prefLabel ?labelTranslation .

  OPTIONAL {
    ?concept skos:broader ?broader .
    ?broader skos:prefLabel ?broaderLabel .
    FILTER(lang(?broaderLabel) = "{{LANG_PRIMARY}}")
  }

  FILTER(lang(?labelPrimary)      = "{{LANG_PRIMARY}}")
  FILTER(lang(?labelTranslation)  = "en")
  FILTER(strstarts(lcase(str(?labelPrimary)), lcase("{{INPUT_PREFIX}}")))
}
ORDER BY ?labelPrimary
LIMIT 5
```

**Variables :**
- `{{LANG_PRIMARY}}` : `"es"` ou `"fr"` selon la langue de saisie
- `{{INPUT_PREFIX}}` : saisie de l'auteur

---

## 4. Format de réponse API — spec fonctionnelle

### 4.1 Requête (côté JS → proxy PHP)

```
POST /index.php/{journalPath}/plugins/generic/nvMetadataCuration/suggest
Content-Type: application/x-www-form-urlencoded

q=sociol&lang=es&thesaurus=unesco
```

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `q` | string | oui | Texte libre saisi par l'auteur (min. 3 caractères) |
| `lang` | string | oui | Langue de saisie (`es`, `fr`) |
| `thesaurus` | string | non | Identifiant thésaurus (`unesco`, `rameau`, `eurovoc`) — défaut : config revue |

### 4.2 Réponse (proxy PHP → JS)

```json
{
  "query": "sociol",
  "thesaurus": "unesco",
  "lang": "es",
  "fallback": false,
  "results": [
    {
      "uri": "http://vocabularies.unesco.org/thesaurus/concept5332",
      "label_primary": "Sociología urbana",
      "label_translation": "Urban sociology",
      "lang_primary": "es",
      "lang_translation": "en",
      "notation": "5.32",
      "broader_uri": "http://vocabularies.unesco.org/thesaurus/concept5330",
      "broader_label": "Sociología",
      "scope_note": null
    }
  ]
}
```

| Champ | Type | Description |
|-------|------|-------------|
| `uri` | string | URI pérenne du concept SKOS — **obligatoire pour validation** |
| `label_primary` | string | Label préféré dans la langue de saisie |
| `label_translation` | string | Label préféré EN (traduction de référence) |
| `lang_primary` | string | Langue du label primaire |
| `lang_translation` | string | Langue de la traduction (toujours `"en"`) |
| `notation` | string\|null | Code de classification (si disponible dans le thésaurus) |
| `broader_uri` | string\|null | URI du concept parent |
| `broader_label` | string\|null | Label du concept parent (dans `lang_primary`) |
| `scope_note` | string\|null | Note d'application SKOS (`skos:scopeNote`) — si disponible |
| `fallback` | boolean | `true` si le thésaurus principal était indisponible et qu'un fallback a été appliqué |

### 4.3 Réponse en cas d'erreur / fallback

```json
{
  "query": "sociol",
  "thesaurus": "unesco",
  "lang": "es",
  "fallback": true,
  "fallback_reason": "timeout",
  "results": []
}
```

---

## 5. Interface PHP — SparqlLookupManager

Classe à créer dans `plugin/classes/managers/SparqlLookupManager.php`.

### 5.1 Signature publique

```php
interface SparqlLookupManagerInterface
{
    /**
     * @param string $prefix  Texte saisi (≥ 3 caractères, déjà sanitisé)
     * @param string $lang    Code langue ('es' | 'fr')
     * @param string $thesaurus  Identifiant ('unesco' | 'rameau' | 'eurovoc')
     * @return array{
     *   query: string,
     *   thesaurus: string,
     *   lang: string,
     *   fallback: bool,
     *   results: array<int, array{
     *     uri: string,
     *     label_primary: string,
     *     label_translation: string,
     *     lang_primary: string,
     *     lang_translation: string,
     *     notation: string|null,
     *     broader_uri: string|null,
     *     broader_label: string|null,
     *     scope_note: string|null
     *   }>
     * }
     */
    public function suggest(string $prefix, string $lang, string $thesaurus): array;
}
```

### 5.2 Règles de sécurité — injection SPARQL

Le `$prefix` vient du formulaire auteur. Avant injection dans la requête SPARQL :

```php
// Sanitisation du préfixe avant injection SPARQL
private function sanitizePrefix(string $prefix): string
{
    // 1. Normaliser en minuscules
    $prefix = mb_strtolower(trim($prefix), 'UTF-8');

    // 2. Interdire les caractères SPARQL dangereux
    $prefix = preg_replace('/[<>"{}|\\\\^`\[\]]/', '', $prefix);

    // 3. Limiter la longueur
    $prefix = mb_substr($prefix, 0, 50, 'UTF-8');

    return $prefix;
}
```

**Ne pas utiliser** la concaténation directe de `$prefix` dans la requête SPARQL. Toujours passer par la sanitisation ci-dessus. SPARQL n'a pas de paramètres positionnels comme PDO — la sanitisation manuelle est nécessaire.

### 5.3 Gestion timeout et fallback

```php
const TIMEOUT_SECONDS = 2;  // 2s max, cohérent avec seuil UX 800ms total

// Si timeout ou HTTP error :
// - Logger l'erreur (error_log)
// - Retourner ['fallback' => true, 'fallback_reason' => 'timeout', 'results' => []]
// - Ne pas lever d'exception (la soumission OJS ne doit pas être bloquée)
```

---

## 6. Contraintes OJS — réponses aux questions ouvertes

### 6.1 Hook d'injection JS

**Question (thesaurus-api.md §9 Q1) :** quel hook PKP pour injecter le JS inline dans le formulaire auteur ?

**Réponse :**

| Version OJS | Hook recommandé | Template cible |
|-------------|-----------------|----------------|
| 3.3.x | `TemplateManager::fetch` | `submission/form/metadata.tpl` |
| 3.4.x | `TemplateManager::display` | `submission/form/metadata.tpl` |

Implémentation dans `NvMetadataCurationPlugin::register()` :

```php
// Compatible 3.3.x et 3.4.x
$this->registerEventHook('TemplateManager::display', [$this, 'injectKeywordLookup']);
// Fallback 3.3.x si TemplateManager::display non disponible :
HookRegistry::register('TemplateManager::fetch', [$this, 'injectKeywordLookup']);
```

Méthode :
```php
public function injectKeywordLookup($hookName, $args): bool
{
    $templateMgr = $args[0];
    $template    = $args[1];

    if (strpos($template, 'submission/form/metadata.tpl') !== false) {
        $templateMgr->addJavaScript(
            'nvKeywordLookup',
            $this->getPluginPath() . '/js/keyword-lookup.js',
            ['inline' => false, 'contexts' => 'backend']
        );
        $templateMgr->assign('nvSuggestUrl', $this->_getSuggestUrl());
    }
    return false; // Ne pas interrompre la chaîne de hooks
}
```

### 6.2 Version OJS cible

**Question (thesaurus-api.md §9 Q2) :** OJS 3.3.x ou 3.4.x ?

**Recommandation :** cibler **OJS 3.3.x en priorité** (plus grande base installée en revues SHS hispanophones). La compatibilité 3.4.x est atteignable avec des gardes de version sur le système d'assets.

Différences principales 3.3.x → 3.4.x affectant le plugin :
- Webpack 3 → 5 pour les assets JS (packaging différent)
- `HookRegistry::register()` → `registerEventHook()` (3.4.x)
- Doctrine migrations 3.4.x plus robustes pour la table dédiée

### 6.3 Stockage des URIs

**Question (thesaurus-api.md §9 Q3) :** `submission_settings` ou table dédiée ?

**Recommandation par phase :**

| Phase | Stockage | Justification |
|-------|----------|---------------|
| Phase 2 PoC | `submission_settings` (JSON sérialisé) | Zéro migration, déploiement rapide |
| Phase 3 | Table `nv_kwd_metadata` | Requêtes SQL complexes pour le backoffice audit |

Structure `submission_settings` pour Phase 2 :
```php
// Clé : 'nvKeywords'
// Valeur JSON :
[
  {
    "kwd_value":     "Sociología urbana",
    "kwd_uri":       "http://vocabularies.unesco.org/thesaurus/concept5332",
    "kwd_lang":      "es",
    "kwd_thesaurus": "unesco",
    "kwd_validated": true
  }
]
```

### 6.4 Proxy SPARQL vs appel JS direct

**Question (thesaurus-api.md §9 Q4) :** cache HTTP OJS ?

**Réponse :** OJS ne dispose pas de cache HTTP intégré pour les appels sortants. Pour le PoC Phase 2, ne pas implémenter de cache. Si la latence dépasse 500 ms de façon systématique en Phase 2 :

1. **Option A — Cache PHP simple :** `APCu` ou fichier temp avec TTL (30 min)
2. **Option B — Miroir Fuseki local :** dump SKOS RDF chargé dans Fuseki Docker sur le serveur OJS
3. **Option C — Proxy NV (Phase 3) :** API NV avec cache centralisé — cohérent avec le modèle freemium

La décision cache/miroir est un point d'architecture à valider par Étienne après mesure des latences réelles en Phase 2.

---

## 7. Matrice de risques techniques

| Risque | Probabilité | Impact | Mitigation |
|--------|-------------|--------|------------|
| Endpoint UNESCO indisponible (maintenance) | Faible | Moyen | Fallback bypass silencieux (déjà spécifié) |
| Latence UNESCO > 500 ms depuis serveur OJS | Moyenne | Moyen | Mesurer en Phase 2 ; miroir Fuseki si besoin |
| ISOC absent (pas de SPARQL public) | **Confirmé** | Moyen | Remplacer par Eurovoc en Phase 3 |
| Injection SPARQL via champ auteur | Faible | Élevé | Sanitisation implémentée (§5.2) |
| Incompatibilité 3.3.x / 3.4.x | Moyenne | Faible | Gardes de version sur hooks et assets |
| Rate limit BnF non documenté | Incertain | Faible | LIMIT 5 dans toutes les requêtes — OK |

---

## 8. Décisions techniques — tranchées par Étienne (2026-04-21)

| # | Question | Décision |
|---|----------|----------|
| Q1 | Version OJS cible | **OJS 3.4.x uniquement** pour le PoC. Scaffold PSR-4 convention 3.4+. Backport 3.3.x en Phase 3 si le marché l'exige. |
| Q2 | Cache Phase 2 | **Pas de cache.** Mesurer les latences réelles UNESCO/Rameau depuis le serveur de test. Décision APCu / miroir Fuseki après benchmark. |
| Q3 | ISOC → Eurovoc | **Substitution confirmée.** Eurovoc couvre les SHS, multilingue ES/FR/EN, endpoint stable. Phase 3. |
| Q4 | Stockage Phase 2 | **`submission_settings` (JSON sérialisé) confirmé.** Zéro migration pour le PoC. Table `nv_kwd_metadata` en Phase 3. |

> Toutes les décisions prises par Étienne (CTO) — commentaire [NEV-187](/NEV/issues/NEV-187#comment-590d6378-3ee0-4ea5-9eb3-66e68b35c29c).

---

*Document issu de NEV-187. Complète [`docs/thesaurus-api.md`](./thesaurus-api.md) (Camille, design) avec les données techniques validées.*
