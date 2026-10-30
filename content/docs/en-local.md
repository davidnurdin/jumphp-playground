# Sur votre ordinateur

Tout passe par une commande, `bin/jumphp`, qui fait tourner PHP, Composer, le compilateur WebAssembly, le
serveur web et les tests **dans Docker**. Il suffit de Docker et de git.

## Installer en une ligne

**Linux, macOS, Windows avec WSL** (dans un terminal) :

```bash
curl -fsSL https://jumphp.net/install.sh | sh
```

**Windows** (dans PowerShell) : JumPHP tourne dans WSL (Linux dans Windows) ; la ligne vérifie qu'il est là
(sinon elle dit comment l'installer : `wsl --install` en administrateur, puis un redémarrage) et installe JumPHP
dedans. Docker : Docker Desktop, avec le moteur WSL 2.

```powershell
irm https://jumphp.net/install.ps1 | iex
```

Elle installe la commande **`jumphp`** (dans `~/.local/bin`, ajouté au `PATH` si besoin ; rien d'autre). Puis :

```bash
jumphp new mon-jeu       # un projet : l'assistant vérifie git et Docker (et propose de les installer), crée le jeu
```

Les deux d'un coup : `curl -fsSL https://jumphp.net/install.sh | sh -s -- mon-jeu`. Les scripts sont lisibles
avant de les lancer : [install.sh](https://github.com/davidnurdin/jumPhp/blob/main/install.sh),
[install.ps1](https://github.com/davidnurdin/jumPhp/blob/main/install.ps1).

## Sans l'installateur

```bash
composer create-project jumphp/jumphp mes-jeux   # ou : git clone https://github.com/davidnurdin/jumPhp.git mes-jeux
cd mes-jeux
bin/jumphp new mon-jeu "MON JEU"      # un jeu à partir du squelette (un petit jeu de plates-formes)
bin/jumphp install mon-jeu
bin/jumphp run mon-jeu                # le jeu dans une fenêtre, avec le son
```

`composer create-project` lance un assistant qui vérifie les outils, crée votre jeu et propose d'installer la
**commande `jumphp`** pour tout le système (`bin/jumphp cli:install`, dans `~/.local/bin`) :

- `jumphp test mon-jeu` depuis n'importe quel dossier du projet (au lieu de `bin/jumphp` à la racine) ;
- `jumphp new autre-projet` pour en commencer un autre (avec Composer, ou dans Docker sans Composer) ;
- une fois par jour, elle regarde sur Packagist si une **nouvelle version de JumPHP** est sortie et **vous
  demande** s'il faut mettre le projet à jour (`bin/jumphp update`) : oui, non (plus tard) ou « pas cette
  version ». `JUMPHP_NO_UPDATE_CHECK=1` la fait taire ; `bin/jumphp cli:uninstall` la retire.

| Commande | |
|---|---|
| `bin/jumphp test mon-jeu` | vérifie la syntaxe, l'analyse statique, et joue 600 images sans écran (la moindre alerte PHP est une erreur) |
| `bin/jumphp shot mon-jeu 120` | une capture d'écran après 2 secondes de jeu, sans fenêtre |
| `bin/jumphp build mon-jeu` | la version navigateur (`dist/web`), puis `bin/jumphp serve mon-jeu` |
| `bin/jumphp build:remote mon-jeu` | le jeu tel que ce site le charge (moteur partagé, fichiers un par un) |
| `bin/jumphp deploy mon-jeu` | sur votre propre serveur |
| `bin/jumphp twa mon-jeu --url …` | une appli Android pour Google Play ([le guide](/docs/android)) |
| `bin/jumphp export:standalone mon-jeu` | le jeu en un seul fichier exécutable, pour Linux et Windows ([le guide](/docs/executable)) |

Pour le publier ici : poussez votre projet dans un dépôt public GitHub, puis
[importez-le](/docs/importer). Chaque push est ensuite republié.

Le projet contient un `AGENTS.md` : c'est la documentation complète de JumPHP (et ce que lit votre Claude).
