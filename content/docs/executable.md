# Votre jeu en un exécutable

Pour donner votre jeu à quelqu'un qui n'a **rien d'installé** (ni PHP, ni Docker) : JumPHP en fait **un seul
fichier** qu'on télécharge et qu'on lance, pour Linux et pour Windows. PHP, SDL, OpenGL et OpenAL sont dedans.

Il faut un projet JumPHP sur votre ordinateur ([sur votre ordinateur](/docs/en-local)) et Docker. Votre Claude
peut le faire à votre place : demandez-lui « fais un exécutable de mon jeu ».

```bash
bin/jumphp export:standalone mon-jeu              # Linux : games/mon-jeu/dist/standalone/mon-jeu-linux-x86_64
bin/jumphp export:standalone mon-jeu --windows    # Windows : mon-jeu-windows-x86_64.exe
bin/jumphp export:standalone mon-jeu --linux --windows
```

## Comment c'est fait

- **PHP** est celui de [StaticPHP](https://github.com/crazywhalecc/static-php-cli) (*micro*) : PHP 8.5 en un
  exécutable, avec les extensions de JumPHP (SDL et ses modules image, son et police, OpenGL, OpenAL, gd, zip…),
  toutes compilées dedans.
- **Votre jeu** (son code, ses images, ses sons, le moteur) est ajouté à la fin, sous forme d'archive PHAR.

Ce PHP est le même pour tous les jeux : il est fabriqué une fois (~15 minutes sous Linux) ou téléchargé depuis
les versions publiées de JumPHP, puis gardé dans `~/.jumphp/standalone`. Ensuite, un exécutable se fait en
quelques secondes.

## Chez le joueur

Le premier lancement déballe le jeu dans son cache (une seconde), les suivants démarrent tout de suite. Les
sauvegardes et options vont dans son dossier de données :

| | Linux | Windows |
|---|---|---|
| le jeu | `~/.cache/jumphp/` | `%LOCALAPPDATA%\JumPHP\` |
| les sauvegardes | `~/.local/share/<id du jeu>/` | `%APPDATA%\<id du jeu>\` |

Sous Linux, il marche sur toute distribution depuis 2013 (glibc 2.17) et utilise l'affichage (X11 ou XWayland),
OpenGL et le son (PulseAudio, PipeWire, ALSA) de l'ordinateur, comme tout jeu Linux. Sous Windows (10 ou plus),
rien à installer.

## Bon à savoir

- Le fichier n'est pas signé : Windows affiche « Windows a protégé votre ordinateur », il faut cliquer sur
  **Informations complémentaires** puis **Exécuter quand même**. Sous Linux, après un téléchargement :
  `chmod +x mon-jeu-linux-x86_64`.
- Le PHP pour Windows ne peut se fabriquer que sous Windows : il est fabriqué par GitHub (le workflow
  `standalone` du dépôt JumPHP) et téléchargé par la commande.
- Processeurs x86_64 seulement (pas encore de Mac ni d'ARM).

Les détails techniques : `docs/standalone.md` dans votre projet.
