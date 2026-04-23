# Local dev environment — OJS 3.4 + NV Metadata Curation

*Recette testée 2026-04-23 contre `pkpofficial/ojs:stable-3_4_0`.*

---

## TL;DR

`docker compose up -d` **ne suffit pas** avec l'image `pkpofficial/ojs:stable-3_4_0` : l'auto-installation est cassée côté image (entrypoint race, flag curl invalide, `SERVERNAME` manquant). Cette doc donne la recette complète pour arriver à une instance OJS fonctionnelle avec le plugin NV activé sur un journal `testnv`.

**Sur Windows / Git Bash (MSYS)** : toutes les commandes `docker compose exec` et `curl` qui manipulent des chemins `/var/www/...` doivent être préfixées par `MSYS_NO_PATHCONV=1`. Voir [§ MSYS](#windows--msys-git-bash--pourquoi-msys_no_pathconv1-) pour la root cause.

---

## Prérequis

- Docker Desktop 4.x ou Docker Engine 24+
- Git Bash (Windows) / bash / zsh
- Port `8080` libre
- Clone de ce repo

---

## Recette complète

### 1. Démarrer les conteneurs

```bash
docker compose down -v   # si vous repartez d'un état précédent
docker compose up -d
```

Attendre ~10 s que `db` devienne `healthy`, puis ~3 s de plus pour que Apache accepte les requêtes dans le conteneur `ojs`. `docker compose ps` doit montrer `db` healthy et `ojs` running.

À ce stade l'instance **n'est pas installée** (voir [§ Bugs connus](#bugs-connus-de-limage-pkpofficialojsstable-3_4_0)). `http://localhost:8080` renvoie une page d'install partielle ou une 500.

### 2. Réparer `config.inc.php` (host DB)

L'entrypoint de l'image écrit `host = localhost` dans `config.inc.php` malgré `OJS_DB_HOST=db`. Il faut corriger à la main :

```bash
MSYS_NO_PATHCONV=1 docker compose exec -T ojs sh -c \
  'sed -i "s|^host = localhost|host = db|" /var/www/html/config.inc.php'
```

### 3. Lancer l'installation OJS manuellement

L'auto-install de l'image ne se déclenche jamais (race condition entrypoint + flag curl invalide). On POST directement sur l'installeur :

```bash
MSYS_NO_PATHCONV=1 curl -sSL -X POST 'http://localhost:8080/index/install/install' \
  -d installing=0 \
  -d adminUsername=admin -d adminPassword=admin -d adminPassword2=admin \
  -d adminEmail=admin@local \
  -d locale=en \
  -d 'additionalLocales[]=en' -d 'additionalLocales[]=fr_FR' -d 'additionalLocales[]=es' \
  -d clientCharset=utf-8 -d connectionCharset=utf8 -d databaseCharset=utf8 \
  -d filesDir=/var/www/files \
  -d databaseDriver=mysqli -d databaseHost=db \
  -d databaseUsername=ojs -d databasePassword=ojs -d databaseName=ojs \
  -d oaiRepositoryId=local -d enableBeacon=0 -d timeZone=UTC
```

La réponse doit se terminer par une page « Installation has completed successfully ». Si vous voyez « directory does not exist or is not writable » sur Windows, c'est que vous avez oublié `MSYS_NO_PATHCONV=1` (voir [§ MSYS](#windows--msys-git-bash--pourquoi-msys_no_pathconv1-)).

### 4. Créer un journal `testnv`

Via l'UI (le chemin API `/index/api/v1/contexts` existe mais n'est pas scopé dans ce doc) :

1. Ouvrir <http://localhost:8080/index/login>, login `admin` / `admin`.
2. **Site Admin → Hosted Journals → Create Journal**.
3. **Name** : `NV Test Journal`, **Path** : `testnv`, **Primary locale** : English.
4. Créer, puis cliquer « Settings Wizard » pour terminer les champs obligatoires (ça peut rester minimal).

### 5. Activer le plugin NV (SQL direct)

Plus rapide que l'UI Plugin Gallery. Active sur le site (`context_id = 0`) et sur le journal `testnv` (`context_id = 1`) :

```bash
MSYS_NO_PATHCONV=1 docker compose exec -T db mariadb -uojs -pojs ojs -e \
  "INSERT INTO plugin_settings (plugin_name, context_id, setting_name, setting_value, setting_type) \
   VALUES ('nvmetadatacurationplugin', 0, 'enabled', '1', 'bool'), \
          ('nvmetadatacurationplugin', 1, 'enabled', '1', 'bool');"
```

### 6. Vérifier

- <http://localhost:8080/index/login> → login `admin` / `admin` → OK.
- <http://localhost:8080/testnv/submission/wizard> → la console doit exposer `window.nvMetadataCuration`.
- Step « Metadata » : l'autocomplete SPARQL (UNESCO / Rameau / Eurovoc) doit s'activer à partir de 3 caractères.

---

## Windows / MSYS Git Bash — pourquoi `MSYS_NO_PATHCONV=1` ?

Le runtime MSYS (utilisé par Git Bash et MSYS2 sur Windows) intercepte les arguments passés aux exécutables natifs Windows (`curl.exe`, `docker.exe`) et convertit automatiquement toute chaîne qui **ressemble à un chemin Unix absolu** (commençant par `/`) en chemin Windows équivalent. Le but est d'aider les binaires Windows qui n'acceptent pas `/usr/bin/something`.

Conséquence ici :

```
curl -d filesDir=/var/www/files ...
```

est réécrit *avant* que curl le voie en :

```
curl -d filesDir=C:/Program Files/Git/var/www/files ...
```

Le serveur OJS reçoit donc `filesDir=C:/Program Files/Git/var/www/files`, tente de créer ce répertoire **dans le conteneur** (où il n'existe pas), et échoue avec « directory does not exist or is not writable ». Aucune trace côté MSYS : la conversion est silencieuse.

Le fix est de désactiver la conversion pour ces commandes :

```bash
MSYS_NO_PATHCONV=1 curl ...
MSYS_NO_PATHCONV=1 docker compose exec ...
```

Ça ne casse rien sur macOS ou Linux (la variable est simplement ignorée), donc les blocs copier-coller ci-dessus marchent cross-OS.

---

## Bugs connus de l'image `pkpofficial/ojs:stable-3_4_0`

État observé au 2026-04-23. Non fixés upstream à cette date.

1. **Entrypoint race** — `/usr/local/bin/ojs-start` lance `ojs-cli-install` *après* `httpd -DFOREGROUND`, qui bloque. L'auto-install ne se déclenche jamais.
2. **Flag curl invalide** — `ojs-cli-install` utilise `curl --ignore`, option inexistante. Le script échoue silencieusement.
3. **`SERVERNAME` non exporté** — même si l'entrypoint partait, l'URL cible deviendrait `https://://index/install/install` (host vide).

Ces trois bugs sont dans l'image PKP, pas dans ce repo. La décision de produit est de **documenter le contournement** plutôt que de forker l'image ou ajouter un Dockerfile custom. Si PKP livre un fix upstream, cette doc deviendra obsolète : re-tester avant d'y faire confiance.

Pour épingler exactement l'image testée :

```bash
docker images --digests pkpofficial/ojs:stable-3_4_0
```

---

## Reset complet

```bash
docker compose down -v
docker compose up -d
# puis refaire les étapes 2 à 5 ci-dessus
```

Les volumes `db_data`, `ojs_data`, `ojs_files` sont détruits par `-v`. Sans `-v`, la base persiste et l'étape 3 échouera (tables déjà installées).

---

## Troubleshooting

| Symptôme | Cause probable | Fix |
|----------|----------------|-----|
| `curl` étape 3 retourne « directory does not exist or is not writable » sur Windows | Path conversion MSYS | Ajouter `MSYS_NO_PATHCONV=1` |
| Étape 3 retourne une page d'install vide ou 500 | `host = localhost` non corrigé | Refaire étape 2 |
| `http://localhost:8080` → ERR_CONNECTION_REFUSED | Conteneur `ojs` pas encore ready | Attendre 5 s, `docker compose logs ojs` |
| Le plugin est invisible dans l'UI Plugin Gallery | `plugin_settings` pas inséré | Refaire étape 5, ou cocher manuellement dans **Website → Plugins** |
| `window.nvMetadataCuration` `undefined` sur la page soumission | Page visitée n'est pas le wizard | Aller sur `/testnv/submission/wizard`, pas sur `/workflow/index/…` |
