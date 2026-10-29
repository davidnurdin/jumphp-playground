# Importer un dépôt existant

[Importer](/studio/importer) publie un jeu JumPHP déjà sur GitHub (un dépôt public dont vous avez les droits).

Le playground trouve le jeu :

- dans `games/<id>/` (un projet JumPHP complet) : le dossier demandé, ou le seul jeu du dépôt ;
- ou à la racine (le dépôt **est** le dossier du jeu : `config/game.yaml` à la racine).

Il publie le dernier commit de la branche principale puis chaque push (webhook). Le bouton **Synchroniser** de
la page du projet (ou l'outil MCP `sync_project`) publie tout de suite et affiche les erreurs.

Ce qui est publié et comment :

- seulement le dossier du jeu ; le moteur et ses paquets viennent du moteur partagé du site (les paquets
  Composer propres au jeu ne sont pas disponibles) ;
- la version du moteur suit le fichier `VERSION` du dépôt ;
- un commit avec une erreur de syntaxe PHP n'est pas publié (la version précédente reste en ligne) ;
- aucune ligne de PHP du jeu ne s'exécute sur le serveur : tout tourne dans le navigateur des joueurs ;
- un jeu sans image est photographié par le site (son écran, dans un navigateur) ;
- les fichiers qui ne peuvent pas être publics (les données d'un jeu d'origine) se donnent à part : Studio →
  le projet → **Fichiers privés**. Le jeu les charge, aucun dépôt ni fork ne les contient.
