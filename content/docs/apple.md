# Votre jeu en appli Mac, iPhone et iPad

JumPHP fait de votre jeu de **vraies applis Apple**, sans navigateur : PHP 8.5, SDL, OpenGL et OpenAL tournent
dans l'appli, comme sur un ordinateur et dans [l'appli Android native](/docs/android-natif).

- **Mac** : une appli pour les Mac Apple (M1 et suivants) et Intel, macOS 11 ou plus récent, en `.dmg` ;
- **iPhone et iPad** : une appli `.ipa`, iOS 14 ou plus récent, avec les commandes tactiles dessinées par le
  moteur et l'orientation du jeu ; et la même pour le **simulateur** d'Xcode.

Fabriquer une appli Apple demande Xcode, donc un Mac. Pas besoin d'en avoir un : **GitHub la fabrique sur un de
ses Mac** (gratuit pour un dépôt public), depuis n'importe quel ordinateur. Il faut un projet JumPHP sur votre
ordinateur ([sur votre ordinateur](/docs/en-local)) et un dépôt GitHub. Votre Claude peut tout faire à votre
place : demandez-lui « fais les applis Mac et iPhone de mon jeu ».

## Une commande

```bash
bin/jumphp apple mon-jeu      # Mac + iPhone / iPad
bin/jumphp macos mon-jeu      # seulement le Mac
bin/jumphp ios mon-jeu        # seulement l'iPhone / iPad (et le simulateur)
```

1. La première fois, elle demande l'**identifiant** de l'appli (`io.github.vous.mon-jeu` : il ne change plus une
   fois sur l'App Store).
2. Elle note la version dans `games/mon-jeu/apple/app.json`, met le workflow `.github/workflows/apple.yml` dans
   votre projet, les commite et, **après votre oui**, pousse.
3. **GitHub fabrique les applis sur un Mac** (la première fois ~40 minutes, ensuite ~10 : PHP et les
   bibliothèques sont gardés en cache), puis **les essaie** : l'appli Mac fait tourner le jeu et en garde une
   image, l'appli iPhone est installée dans un iPhone simulé, lancée et photographiée.
4. Elle publie une *release* de votre dépôt avec les applis et les deux captures, et les télécharge dans
   `games/mon-jeu/dist/apple/` (les captures dans `check/` : regardez-les).

Les versions suivantes : `bin/jumphp apple mon-jeu --version 1.1` (le numéro de build monte de un à chaque
fois). Sur un Mac avec Xcode : `--local` fabrique tout sur place.

**Des fichiers hors de git** (les données d'un jeu d'origine, gardées hors d'un dépôt public, comme les
[fichiers privés](/docs/importer) du playground) : la commande les trouve et, après votre oui, les met dans
une *release* **brouillon** de votre dépôt, que seuls ceux qui peuvent y écrire voient ; le Mac de GitHub les
remet dans le jeu avant de le fabriquer. Les applis, elles, contiennent ces fichiers : leur *release* est alors un brouillon aussi, c'est vous qui
décidez de la publier.

## Sans compte développeur Apple (pour commencer)

Les applis sont signées « ad hoc », sans identité :

- **Mac** : la première fois, **clic droit sur l'appli > Ouvrir** (macOS demande une fois). Parfait pour vous,
  vos amis, vos testeurs.
- **iPhone / iPad** : un iPhone n'installe qu'une appli signée pour lui. Le `.ipa` attend le compte (ci-dessous).
  En attendant : l'appli du simulateur (sur un Mac avec Xcode), et la version web s'installe depuis Safari
  (Partager > Sur l'écran d'accueil).

## Avec un compte développeur Apple (ensuite)

Un compte de l'[Apple Developer Program](https://developer.apple.com/programs/) (99 $ par an) sert à :

- ouvrir l'appli Mac sans le clic droit : signature **Developer ID** et **notarisation** par Apple ;
- installer l'appli sur un iPhone, l'envoyer sur **TestFlight** puis sur l'**App Store**.

C'est la prochaine étape de JumPHP. L'App Store accepte un jeu en PHP : le code tourne dans l'appli et vient
avec elle, rien n'est téléchargé ensuite (règle 2.5.2).

## Les commandes tactiles

Sur iPhone et iPad, le moteur dessine les **commandes à l'écran** comme dans le navigateur et sur Android : la
croix (ou un joystick), A, B et START. Elles se règlent dans `game.yaml` :

```yaml
game:
  touch: { pad: stick, a: Space, b: Escape, labels: { a: SAUT } }   # pad: stick = joystick, sinon la croix
```

Une manette (Bluetooth, MFi) marche aussi. Sur Mac : clavier, souris, manette, comme sur un ordinateur.

## Dans l'appli

- PHP 8.5 compilé pour Mac (Apple et Intel) et pour iPhone avec les extensions de JumPHP (SDL et ses modules
  image, son et police, OpenGL, OpenAL, gd, openssl, mbstring…), SDL2 et OpenAL Soft. Sans JIT : Apple
  l'interdit sur iPhone.
- Votre jeu, tel quel, dans l'appli. Les sauvegardes et options vont dans le dossier de l'appli (Application
  Support) et restent d'une mise à jour à l'autre.
- Le nom, l'icône (`assets/icon.png`, sinon l'initiale du titre) et l'orientation (la forme du jeu) viennent de
  `game.yaml`.
- Les jeux en 3D (`gl()`, `opengl()`) marchent : OpenGL ES sur iPhone, OpenGL sur Mac (le moteur adapte les
  shaders lui-même).
- HTTPS marche (`$engine->http()`, le jeu en ligne en `wss://`).

Les détails techniques : `docs/apple.md` dans votre projet.
