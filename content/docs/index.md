# Créer un jeu avec JumPHP

JumPHP est un framework pour écrire des **jeux rétro en 2D en PHP**. Le même code tourne sur le bureau (PHP + SDL2,
dans une vraie fenêtre) et **dans le navigateur** (PHP compilé en WebAssembly) : c'est ce qui fait marcher tous les
jeux de ce site. Il y a plusieurs façons de s'y mettre, de la plus rapide à la plus complète.

| Façon | Compte | Ce qu'il faut | Pour qui |
|---|---|---|---|
| [Bac à sable](/docs/bac-a-sable) | non | un navigateur | essayer en 1 minute : changer un hello world et le lancer |
| [Avec votre Claude](/docs/avec-claude) | GitHub | Claude Code + Docker | décrire son jeu et le laisser l'écrire, le tester, le publier |
| [Éditeur en ligne](/docs/editeur-en-ligne) | GitHub | un navigateur | retoucher un jeu publié, ou forker celui d'un autre |
| [Sur votre ordinateur](/docs/en-local) | non (GitHub pour publier) | Docker | développer comme un pro : tests, captures, build navigateur |
| [Importer un dépôt](/docs/importer) | GitHub | un dépôt JumPHP | publier ici un jeu qui existe déjà |
| [Remake Amstrad CPC](/docs/remake-amstrad) | GitHub | la disquette `.dsk` du jeu | refaire un jeu CPC avec ses graphismes et sa musique d'origine |
| [Portage d'un jeu existant](/docs/portage) | GitHub | son code source | traduire un vieux jeu (C, Pascal, BASIC…) en PHP |

Ensuite, les **tutoriels** construisent un jeu pas à pas :

1. [Hello world : un menu, un texte, un son](/docs/hello-world)
2. [Un personnage qui bouge et s'anime](/docs/personnage)
3. [Un jeu de plates-formes : niveau, collisions, caméra](/docs/plates-formes)
4. [Sons et musique](/docs/sons)
5. [Menus, options, pause et langues](/docs/menus)
6. [Sauvegarder les scores et les options](/docs/sauvegarde)
7. [Le multijoueur en ligne](/docs/multijoueur)
8. [La page d'accueil du jeu et sa publication](/docs/page-du-jeu)

Une fois le jeu fini : [le mettre sur Google Play](/docs/android) (une appli Android, faite par `bin/jumphp twa`).

Et pour les programmes qui veulent lire l'annuaire : [l'API publique](/docs/api).

## Comment marche un jeu JumPHP

Un jeu est un dossier `games/<id>/` d'un projet JumPHP :

```
games/mon-jeu/
  config/game.yaml      le titre, la résolution (320 × 180…), la première scène
  config/input.yaml     les actions (sauter, tirer…) et leurs touches, manette, écran tactile
  src/Scene/*.php       les écrans : le titre, le jeu, la pause…
  src/Entity/*.php      ce qui bouge : le héros, les ennemis…
  assets/               images, sons, musique, niveaux (facultatifs : tout peut se dessiner en PHP)
  site/                 (facultatif) la page d'accueil du jeu
```

Chaque image (60 fois par seconde), le moteur appelle `update($dt)` de la scène affichée (on change l'état du
jeu) puis `render($r)` (on dessine). Les coordonnées sont des pixels « virtuels » (la résolution du jeu) :
le moteur agrandit l'image à la taille de l'écran.

Le contrat complet du framework, pour les humains comme pour les IA, est le fichier
[AGENTS.md](https://github.com/davidnurdin/jumPhp/blob/main/AGENTS.md) du dépôt JumPHP.
