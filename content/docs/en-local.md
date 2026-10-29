# Sur votre ordinateur

Tout passe par une commande, `bin/jumphp`, qui fait tourner PHP, Composer, le compilateur WebAssembly, le
serveur web et les tests **dans Docker**. Il suffit de Docker et de git.

```bash
git clone https://github.com/davidnurdin/jumPhp.git mes-jeux && cd mes-jeux
bin/jumphp new mon-jeu "MON JEU"      # un jeu à partir du squelette (un petit jeu de plates-formes)
bin/jumphp install mon-jeu
bin/jumphp run mon-jeu                # le jeu dans une fenêtre, avec le son
```

| Commande | |
|---|---|
| `bin/jumphp test mon-jeu` | vérifie la syntaxe, l'analyse statique, et joue 600 images sans écran (la moindre alerte PHP est une erreur) |
| `bin/jumphp shot mon-jeu 120` | une capture d'écran après 2 secondes de jeu, sans fenêtre |
| `bin/jumphp build mon-jeu` | la version navigateur (`dist/web`), puis `bin/jumphp serve mon-jeu` |
| `bin/jumphp build:remote mon-jeu` | le jeu tel que ce site le charge (moteur partagé, fichiers un par un) |
| `bin/jumphp deploy mon-jeu` | sur votre propre serveur |

Pour le publier ici : poussez votre projet dans un dépôt public GitHub, puis
[importez-le](/docs/importer). Chaque push est ensuite republié.

Le projet contient un `AGENTS.md` : c'est la documentation complète de JumPHP (et ce que lit votre Claude).
