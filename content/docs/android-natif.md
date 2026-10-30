# Votre jeu en appli Android native

JumPHP fait de votre jeu une **vraie appli Android**, sans navigateur : PHP 8.5, SDL, OpenGL ES et OpenAL tournent
dans l'appli, comme sur un ordinateur. Elle marche **hors ligne**, sur les téléphones (ARM) et les tablettes,
Android 8.0 ou plus récent.

Il faut un projet JumPHP sur votre ordinateur ([sur votre ordinateur](/docs/en-local)), Docker, et pour le
Play Store un [compte développeur Google Play](https://play.google.com/console) (25 $ une fois). Votre Claude
peut tout faire à votre place : demandez-lui « fais une appli Android native de mon jeu ».

L'autre façon, [la Trusted Web Activity](/docs/android), met la page du jeu dans Chrome : plus légère, mais il
faut le réseau et le site. Avec le même paquet et la même clé, l'appli native remplace la TWA sur le Play Store,
comme une nouvelle version.

## Une commande

```bash
bin/jumphp android mon-jeu
```

1. La première fois, elle demande le **nom du paquet** de l'appli (`io.github.vous.mon_jeu` : il ne change plus
   une fois publié) et fabrique votre **clé d'envoi** dans `~/.jumphp/android/keys/` (celle de la TWA si le jeu
   en a une). **Sauvegardez-la**, elle n'est jamais dans git.
2. Elle met le workflow `.github/workflows/android.yml` dans votre projet et, **après votre oui**, envoie la clé
   dans les secrets de votre dépôt GitHub (chiffrés, lus par le workflow seulement).
3. Elle note la version dans `games/mon-jeu/android/app.json`, la commite et, **après votre oui**, pousse.
4. **GitHub fabrique l'appli** (la première fois ~25 minutes, ensuite ~8 : PHP et les bibliothèques sont gardés
   en cache) et publie une *release* de votre dépôt avec le **`.apk`** (pour un téléphone) et le **`.aab`**
   (pour Google Play), signés avec votre clé. La commande les télécharge dans
   `games/mon-jeu/dist/android-native/`.

Les versions suivantes : `bin/jumphp android mon-jeu --version-name 1.1` (le numéro de version monte de un à
chaque fois). Sans GitHub : `--local` fabrique l'appli sur votre ordinateur, dans Docker.

Pour l'essayer sur votre téléphone : envoyez-lui le `.apk` et ouvrez-le (autorisez « installer des applis
inconnues »), ou `adb install mon-jeu-1.0.apk`.

## Les commandes tactiles

Le moteur dessine lui-même les **commandes à l'écran**, comme dans le navigateur : la croix (ou un joystick), A,
B et START, dans les bandes noires sur les côtés quand il y en a. Elles se règlent dans `game.yaml`, comme pour
le navigateur :

```yaml
game:
  touch: { pad: stick, a: Space, b: Escape, labels: { a: SAUT } }   # pad: stick = joystick, sinon la croix
```

Le bouton retour d'Android vaut Échap. Pour les voir sur votre ordinateur :
`JUMPHP_TOUCH_CONTROLS=1 bin/jumphp run mon-jeu` (avec la souris).

## Dans l'appli

- PHP 8.5 compilé pour Android avec les extensions de JumPHP (SDL et ses modules image, son et police, OpenGL,
  OpenAL, gd, openssl, mbstring…), SDL2 et OpenAL Soft : ce qui fait tourner le jeu sur un ordinateur.
- Votre jeu, déballé au premier lancement de chaque version (une ou deux secondes), dans le stockage privé de
  l'appli. Les sauvegardes et options y restent d'une mise à jour à l'autre.
- Le nom, l'icône (celle de la PWA : `assets/icon.png`) et l'orientation (celle de la forme du jeu) viennent de
  `game.yaml`.

## Sur le Play Store

1. Dans la [Play Console](https://play.google.com/console), **créez l'appli** (nom, langue, « Jeu », gratuit)
   et remplissez les déclarations : règles de confidentialité (une page qui dit que le jeu ne collecte rien),
   classification du contenu, public cible, pas de publicité.
2. **Signature d'application par Google Play** : Google garde la clé qui signe l'appli pour les téléphones ; la
   vôtre prouve seulement que les envois viennent de vous (perdue, Google peut la remplacer).
3. **Test interne** : Tester et publier > Tests internes > Créer une version > envoyez le `.aab` > Enregistrer >
   Examiner > Déployer. Ajoutez vos testeurs, ils installent depuis le lien de Google.
4. **La fiche** : descriptions, icône 512 × 512 (`bin/jumphp build` la fait : `dist/web/icon-512.png`), une
   image 1024 × 500, au moins 2 captures (`bin/jumphp shot mon-jeu`, ou sur un téléphone).
5. **Production** : quand les tests sont bons, promouvez la version. Google l'examine (quelques heures à quelques
   jours). Un nouveau compte personnel doit d'abord avoir 12 testeurs pendant 14 jours.

## Bon à savoir

- HTTPS marche (`$engine->http()`, le jeu en ligne en `wss://`) : les certificats viennent avec le jeu.
- L'appli pèse 30 à 40 Mo (PHP et les bibliothèques pour deux processeurs).

Les détails techniques : `docs/android-native.md` dans votre projet.
