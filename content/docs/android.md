# Votre jeu sur Google Play

Un jeu publié ici s'installe déjà sur un téléphone (bouton **Installer** de sa page). Pour qu'il soit aussi
**sur le Play Store**, JumPHP en fait une vraie appli Android : une *Trusted Web Activity*. C'est la page du jeu
dans Chrome, en plein écran, sans barre d'adresse, avec son icône et son nom ; le jeu reste celui du site (une
mise à jour publiée ici est tout de suite dans l'appli). Pour une appli qui marche sans réseau et sans navigateur :
[l'appli Android native](/docs/android-natif).

Il faut : un projet JumPHP sur votre ordinateur ([sur votre ordinateur](/docs/en-local)), Docker, et un
[compte développeur Google Play](https://play.google.com/console) (25 $ une fois). Votre Claude peut tout faire
à votre place : demandez-lui « mets mon jeu sur Google Play ».

## 1. Fabriquer l'appli

```bash
bin/jumphp twa mon-jeu --url https://jumphp.net/mon-jeu
```

La première fois, la commande demande :

- le **nom du paquet** de l'appli (par exemple `io.github.moi.mon_jeu`) : son identifiant sur le Play Store,
  il ne change plus jamais ;
- d'**accepter la licence du SDK Android** de Google (il est téléchargé une fois, ~1 Go, dans `~/.jumphp`).

Elle crée votre **clé d'envoi** (`~/.jumphp/android/keys/`, avec son mot de passe : sauvegardez-les, ne les
mettez jamais dans git), puis fabrique dans `games/mon-jeu/dist/android/` :

| Fichier | Pour |
|---|---|
| `mon-jeu-1.aab` | le Play Store |
| `mon-jeu-1.apk` | l'essayer sur votre téléphone (`adb install`, ou copiez-le dessus) |
| `assetlinks.json` | prouver que le site et l'appli vont ensemble (étape 2) |

Elle affiche l'**empreinte SHA-256** de votre clé. Le nom sous l'icône est le nom court du jeu
(`pwa: {short_name: …}` dans `game.yaml`, sinon son titre).

## 2. Dire au site que l'appli est la vôtre

Sans ça, l'appli s'ouvre avec une barre d'adresse. Dans le **Studio**, sur la page de votre jeu, partie
**Application Android** : le nom du paquet et l'empreinte. Le site les publie dans
`https://jumphp.net/.well-known/assetlinks.json`, qu'Android vérifie.

Votre Claude le fait avec `update_project` (`android_package`, `android_fingerprints`). Sur votre propre
serveur, mettez `assetlinks.json` à l'adresse `https://votre-domaine/.well-known/assetlinks.json`.

## 3. Le publier sur Google Play

1. Dans la [Play Console](https://play.google.com/console) : **Créer une application**, avec le même nom de
   paquet.
2. **Tests internes** d'abord : envoyez le `.aab`, installez-le depuis le lien de test.
3. **Signature de l'application** (Configuration) : Google signe l'appli avec sa propre clé ; copiez son
   empreinte SHA-256 et **ajoutez-la** à côté de la vôtre (étape 2), sinon la barre d'adresse revient.
4. La fiche (captures : `bin/jumphp shot`), le classement du contenu, puis la production.

## Les versions suivantes

Le code du jeu se met à jour tout seul (c'est le site). Pour changer l'appli elle-même (nom, icône, couleurs) :

```bash
bin/jumphp twa mon-jeu      # le numéro de version monte de 1
```

`games/mon-jeu/android/twa-manifest.json` garde le paquet, l'adresse et la version : il va dans git.
