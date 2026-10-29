# JumPHP playground

Le site des jeux [JumPHP](https://github.com/davidnurdin/jumPhp) : des jeux rétro écrits en PHP, joués dans le
navigateur.

- **Annuaire** par catégorie, recherche, une page par jeu (le site du jeu, `games/<id>/site/`, s'il en a un).
- **Les jeux tournent dans le navigateur** avec le moteur JumPHP partagé (`/engine/<version>/`, téléchargé une
  fois pour tous les jeux) et les fichiers du jeu chargés un par un (mode distant de JumPHP, `docs/remote.md`).
  Ils sont servis par une origine à part (`PLAY_URL`) sans session ni cookie du portail.
- **Sources** navigables, **éditeur en ligne** (CodeMirror) : brouillons enregistrés au fil de la frappe,
  aperçu du jeu avec les brouillons, push sur GitHub en un commit, ou fork.
- **Connexion GitHub uniquement**. Un projet = un dépôt GitHub public de l'utilisateur (un projet JumPHP avec un
  jeu), publié à chaque push (webhook) ; catégorie choisie à la création.
- **Avis des joueurs** sur chaque jeu : commentaires, boîte à idées, problèmes. Tout le monde peut écrire ; un
  message anonyme n'est visible (de tous) qu'une fois validé par l'auteur du jeu, celui d'un connecté paraît tout
  de suite avec sa photo et un lien vers son profil.
- **Tests PHPUnit des jeux** (le dossier `tests/` d'un jeu) lancés depuis le Studio ou par le Claude du créateur
  (MCP `run_tests`), dans le service `tester` (`docker/tester`) : pas de réseau, PHPUnit sous `nobody`, temps et
  mémoire limités ; il ne voit que le volume `tests` (pas de secret ni de fichier privé). Résultats test par test.
- **Pages développeur** (`/dev/<login>`) : ses jeux, ses chiffres, les derniers messages écrits et reçus.
- **Bac à sable** (`/bac-a-sable`) : l'éditeur sans compte, sur hello-world ou n'importe quel jeu (brouillons
  liés à un cookie signé, gardés 30 jours, pas de push).
- **Documentation et tutoriels** (`/docs`, `content/docs/*.md`).
- **API publique** en lecture seule (API Platform, `/api/games`, `/api/categories`, OpenAPI sur `/api/docs`,
  CORS ouvert) : `src/ApiResource`, `src/State`.
- **Serveur MCP** (`/mcp`, jeton fait dans `/studio/mcp`) : le Claude local de l'utilisateur crée le projet,
  clone le dépôt, fait le jeu et son site, pousse et publie (`AGENTS.md` §14 de JumPHP) ; il garde des notes et
  une todo list privées par projet (état d'avancement, aussi visibles dans le studio) et lit / modère les avis.

Symfony 7.4, FrankenPHP, MariaDB, Messenger (création des dépôts et publications en tâche de fond).

## Développement

```bash
docker compose up -d
docker compose exec app php bin/console app:runtime:install           # le moteur JumPHP partagé
docker compose exec app php bin/console app:project:import davidnurdin/jumPhp pong arcade --game-dir games/pong
```

Portail : http://jumphp.localhost:8000, jeux : http://play.jumphp.localhost:8000. Pour se connecter, une
OAuth App GitHub (callback `http://jumphp.localhost:8000/connect/github/check`) dans `.env.local` :
`GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`.

Tests (base MariaDB `playground_test`) :

```bash
docker compose exec db mariadb -uroot -proot -e "CREATE DATABASE IF NOT EXISTS playground_test"
docker compose exec app sh -c 'APP_ENV=test php bin/console doctrine:migrations:migrate -n && php bin/phpunit'
docker compose exec app vendor/bin/phpstan analyse
```

### Passer à une nouvelle version de PHP

`bin/php-upgrade <version>` (par exemple `bin/php-upgrade 8.6`) migre tout, le playground et JumPHP
(`JUMPHP_DIR`, par défaut `../JumPHP`) : les images (site FrankenPHP, banc de test, runtime bureau, relais, moteur
WebAssembly à la dernière version corrective), la doc, et le code que la nouvelle version déprécie (casts
`(boolean)`…, `setAccessible()`, `imagedestroy()`…). Puis il reconstruit les images et lance tous les contrôles :
`php -l` de tout le code, PHPUnit et PHPStan du playground, les tests du moteur et `bin/jumphp test` de chaque jeu
(une dépréciation = un échec), et le moteur WebAssembly. Il ne touche pas à la prod et ne publie rien : la fin dit
ce qui reste à faire (release JumPHP, déploiement). `--skip-wasm` : sans le moteur WebAssembly (~25 min) ;
`--check` : seulement les contrôles.

## Production

Derrière un Traefik déjà en place qui laisse passer le TLS (FrankenPHP prend ses certificats lui-même pour les
deux noms) : `deploy/compose.yaml`.

```bash
cp deploy/.env.example deploy/.env      # noms, secrets, OAuth App GitHub (callback https://<APP_HOST>/connect/github/check)
docker compose -f deploy/compose.yaml --env-file deploy/.env up -d --build
docker compose -f deploy/compose.yaml --env-file deploy/.env exec app php bin/console app:project:import ...
```

Au démarrage : migrations, puis installation des moteurs `JUMPHP_RUNTIMES` depuis les releases de JumPHP.
