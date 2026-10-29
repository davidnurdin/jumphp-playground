# Tutoriel 8 — La page d'accueil du jeu et sa publication

## La page d'accueil : `site/`

Un dossier `games/<id>/site/` (une page `index.html`, des images) devient la page du jeu sur ce site, et la
racine de votre domaine si vous le déployez chez vous (le jeu étant dans `play/`). Exemples :
[Rainbow Islands](/rainbow-island), [Sapiens](/sapiens).

- aux couleurs du jeu, avec ses vraies images : des captures (`bin/jumphp shot`), ses sprites, ses tuiles ;
- un grand lien vers le jeu : `href="play/"` ;
- le référencement : `<title>`, `<meta name="description">`, Open Graph (`og:image` de 1200 × 630), un
  `<h1>`, des `alt` sur les images, les données structurées `VideoGame` ;
- les adresses absolues s'écrivent `https://{{DOMAIN}}/…` : le site les remplace par la vraie adresse ;
- lisible sur téléphone (390 pixels de large, sans défilement horizontal).

Vérifiez-la avec `bin/jumphp build:site <id>` puis `bin/jumphp siteshot <id>`.

## L'image de l'annuaire

`assets/thumbnail.png` (16:9). Sinon le site prend l'image `og:image` de la page d'accueil, sinon l'icône du
jeu, et à défaut il **photographie le jeu** lui-même dans un navigateur.

## Ce que le site ajoute à chaque jeu

- **Installer** : chaque jeu s'installe comme une application (sur téléphone et sur ordinateur), avec son
  icône (`assets/icon.png`) ;
- **Partager** : la feuille de partage du téléphone, ou les réseaux sociaux sur ordinateur ;
- **les notes** des joueurs (de 1 à 5 étoiles, sans compte) et le compteur de parties ;
- **les sources** et l'**éditeur** en ligne.

## Publier

Chaque push sur la branche principale de votre dépôt est publié dans la minute. Dans le Studio, la page du
projet montre l'état de la publication, les erreurs, et permet de cacher le jeu de l'annuaire.
