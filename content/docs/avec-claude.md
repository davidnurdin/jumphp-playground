# Avec votre Claude

La façon la plus rapide de faire un vrai jeu : vous le décrivez, **Claude Code sur votre ordinateur** l'écrit,
le teste, fait sa page d'accueil et le publie ici. Le playground a un **serveur MCP** : votre Claude crée le
projet, suit sa publication et lit les jeux des autres pour s'en inspirer.

## Ce qu'il faut

- un compte GitHub (le jeu vit dans un dépôt public de votre compte) ;
- [Claude Code](https://claude.com/claude-code) ;
- [Docker](https://docs.docker.com/get-docker/) : JumPHP fait tout tourner dans des conteneurs (PHP, le build
  navigateur, les tests), rien d'autre à installer.

## Brancher Claude au playground

1. [Connectez-vous avec GitHub](/connect/github).
2. Dans le Studio, page [MCP](/studio/mcp), créez votre jeton.
3. Copiez la commande affichée et lancez-la dans un terminal :

```bash
claude mcp add --transport http --scope user jumphp https://<ce site>/mcp --header "Authorization: Bearer jph_..."
```

Le jeton donne accès à votre compte du playground : ne le mettez jamais dans un dépôt.

## Faire un jeu

Dans Claude Code, demandez simplement :

> Crée-moi sur le playground JumPHP un jeu de plates-formes : un chat ninja récupère des poissons sur les toits de
> Paris, 5 niveaux, des pigeons ennemis, un boss. Pixel art, écran 320 × 180. Fais aussi sa page d'accueil.

Claude :

1. crée le projet (`create_project`) : un dépôt GitHub avec JumPHP, un premier jeu et votre demande dans
   `BRIEF.md` ;
2. le clone et écrit le jeu en suivant `AGENTS.md` (le contrat de JumPHP) ;
3. le teste (`bin/jumphp test`), **regarde les captures d'écran** qu'il prend lui-même, et vérifie le build
   navigateur ;
4. fait la page d'accueil du jeu (`site/`) avec ses vraies images ;
5. pousse sur GitHub : le playground publie le jeu dans la minute (`sync_project` publie tout de suite et
   renvoie les erreurs) ;
6. quand le jeu est prêt, le montre dans l'annuaire (`update_project listed=true`).

Vous pouvez aussi créer le projet vous-même ([Nouveau jeu](/studio/nouveau)) : la page du projet donne la
commande à lancer pour que Claude s'y mette.

## Les outils MCP

| Outil | Rôle |
|---|---|
| `whoami` | votre compte et les adresses du site |
| `list_categories` | les catégories de l'annuaire |
| `create_project` | un nouveau jeu (titre, description, catégorie, type, demande) |
| `get_project`, `list_projects` | l'état de vos projets, leurs erreurs de publication |
| `update_project` | titre, description, catégorie, visible ou non dans l'annuaire |
| `sync_project` | publier maintenant le dernier commit |
| `import_project` | publier un dépôt JumPHP existant |
| `list_games`, `list_game_files`, `read_game_file` | lire les jeux publiés (s'inspirer, comprendre) |

Le type de jeu `cpc` fait travailler Claude avec [dsk2jumphp](/docs/remake-amstrad) pour un remake Amstrad.
