# Spécification SPARQL — Interface thésaurus pour le plugin OJS

*Camille, Head of Design · Version 1.0 · 2026-04-21*
*Destinataire : Étienne (Phase 2 PoC)*

---

## 1. Principe architectural

Le plugin ne fait **pas** de matching LLM pour les mots-clés. Il fait un **lookup déterministe** :

```
Saisie auteur → SPARQL query → Termes candidats valides (avec URI)
                              ↓
                    LLM : explication sémantique pour aider le choix
                              ↓
                    Validation humaine obligatoire
```

**Règle fondamentale :** un terme n'est valide que s'il possède une URI dans le thésaurus configuré. Le LLM ne peut pas inventer d'appartenance à un vocabulaire contrôlé.

---

## 2. Thésaurus supportés

| Thésaurus | Organisme | Endpoint SPARQL public | Langues |
|-----------|-----------|----------------------|---------|
| UNESCO Thésaurus | UNESCO | `https://vocabularies.unesco.org/sparql` | EN, ES, FR, AR, RU, ZH |
| Rameau | BnF | `https://data.bnf.fr/sparql` | FR |
| Eurovoc | Publications Office EU | `https://publications.europa.eu/webapi/rdf/sparql` | 24 langues |

> **Pour le PoC Phase 2 :** UNESCO uniquement. L'endpoint est stable et documenté.

---

## 3. Requête SPARQL type (UNESCO)

### 3.1 Lookup par préfixe de saisie

```sparql
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
PREFIX uneskos: <http://purl.org/umu/uneskos#>

SELECT ?concept ?labelES ?labelEN ?broader
WHERE {
  ?concept a skos:Concept ;
           skos:prefLabel ?labelES ;
           skos:prefLabel ?labelEN .

  OPTIONAL { ?concept skos:broader ?broader . }

  FILTER(lang(?labelES) = "es")
  FILTER(lang(?labelEN) = "en")
  FILTER(strstarts(lcase(str(?labelES)), lcase("{{INPUT_PREFIX}}")))
}
ORDER BY ?labelES
LIMIT 10
```

Variables à substituer :
- `{{INPUT_PREFIX}}` : saisie de l'auteur (urlencodée, minuscules)

### 3.2 Lookup bilingue (ES + FR)

```sparql
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>

SELECT ?concept ?labelPrimary ?labelTranslation ?notation
WHERE {
  ?concept a skos:Concept ;
           skos:prefLabel ?labelPrimary ;
           skos:prefLabel ?labelTranslation .

  OPTIONAL { ?concept skos:notation ?notation . }

  FILTER(lang(?labelPrimary) = "{{LANG_PRIMARY}}")
  FILTER(lang(?labelTranslation) = "{{LANG_TRANSLATION}}")
  FILTER(strstarts(lcase(str(?labelPrimary)), lcase("{{INPUT_PREFIX}}")))
}
ORDER BY ?labelPrimary
LIMIT 5
```

Variables :
- `{{LANG_PRIMARY}}` : langue de saisie (`"es"` ou `"fr"`)
- `{{LANG_TRANSLATION}}` : langue de traduction (`"en"`)
- `{{INPUT_PREFIX}}` : saisie de l'auteur

---

## 4. Format de réponse attendu (côté plugin PHP)

Le plugin reçoit un tableau JSON de candidats :

```json
{
  "query": "sociol",
  "thesaurus": "unesco",
  "lang": "es",
  "results": [
    {
      "uri": "http://vocabularies.unesco.org/thesaurus/concept5332",
      "label_primary": "Sociología urbana",
      "label_translation": "Urban sociology",
      "lang_primary": "es",
      "lang_translation": "en",
      "notation": "5.32",
      "broader_label": "Sociología"
    },
    {
      "uri": "http://vocabularies.unesco.org/thesaurus/concept5334",
      "label_primary": "Sociología del trabajo",
      "label_translation": "Sociology of work",
      "lang_primary": "es",
      "lang_translation": "en",
      "notation": "5.34",
      "broader_label": "Sociología"
    }
  ]
}
```

---

## 5. Stockage côté OJS

Quand l'auteur valide un terme :

```php
// À stocker dans submission_settings ou champ kwd-group étendu
[
    'kwd_value'     => 'Sociología urbana',       // label affiché
    'kwd_uri'       => 'http://vocabularies.unesco.org/thesaurus/concept5332',
    'kwd_lang'      => 'es',
    'kwd_thesaurus' => 'unesco',
    'kwd_validated' => true
]
```

> **Question ouverte pour Étienne :** utiliser `submission_settings` (table OJS standard) ou créer une table dédiée `nv_kwd_metadata` ? La table dédiée permet de stocker l'URI sans polluer submission_settings, mais ajoute une migration de schema.

---

## 6. Architecture d'appel (côté PHP)

```
Navigateur auteur
    ↓ AJAX POST (debounce 300ms)
Plugin PHP handler (OJS hook)
    ↓ HTTP GET
Proxy SPARQL interne (PHP)     ← évite les problèmes CORS
    ↓ SPARQL query
Endpoint thésaurus externe
    ↓ JSON/XML
Plugin PHP handler
    ↓ JSON response
Navigateur auteur → affichage suggestions
```

**Pourquoi un proxy PHP et non appel direct JS :**
- Les endpoints SPARQL publics n'envoient pas toujours les headers CORS nécessaires
- Le proxy centralise la gestion des timeouts et du fallback

---

## 7. Gestion des erreurs et fallback

| Scénario | Comportement attendu |
|----------|---------------------|
| Endpoint SPARQL indisponible (timeout > 2s) | Champ bascule en mode `bypass` silencieux — aucune suggestion, soumission non bloquée |
| Réponse vide (0 résultats) | Afficher « Aucun terme correspondant — saisie libre » |
| Erreur HTTP 429 (rate limit) | Retry après 1s, puis fallback bypass |
| Terme saisi < 3 caractères | Ne pas déclencher le lookup (throttling UX) |

---

## 8. Critères de performance (Phase 2 PoC)

| Métrique | Seuil acceptable |
|----------|-----------------|
| Latence lookup SPARQL | < 500 ms (P95) |
| Latence totale JS→réponse | < 800 ms (P95) |
| Taille réponse JSON | < 5 KB |

> Si la latence dépasse 500 ms sur l'endpoint UNESCO public, envisager un miroir SPARQL local (dump RDF chargé en Virtuoso ou Fuseki Docker). À décider en Phase 2.

---

## 9. Questions techniques pour Étienne

1. **Hook OJS :** quel point d'extension PKP pour injecter le JS inline dans le formulaire auteur ? `TemplateManager::fetch` sur le template `submission/form/metadata.tpl` ?
2. **Version OJS cible :** 3.3.x ou 3.4.x uniquement ? L'API hooks differ between versions.
3. **Stockage URI :** `submission_settings` ou table dédiée `nv_kwd_metadata` ?
4. **Proxy SPARQL :** OJS dispose-t-il d'un mécanisme de cache HTTP intégré, ou faut-il gérer le cache manuellement dans le plugin ?
5. **Endpoint SPARQL UNESCO :** tester la latence depuis le serveur OJS de test avant de valider l'architecture sans miroir local.
