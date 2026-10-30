# Your game on Google Play

A game published here can already be installed on a phone (the **Install** button of its page). To have it
**on the Play Store** too, JumPHP turns it into a real Android app: a *Trusted Web Activity*. It is the game's
page in Chrome, full screen, with no address bar, with its icon and name; the game stays the site's (an update
published here is in the app at once). For an app that works without the network or a browser:
[the native Android app](/docs/android-natif).

You need: a JumPHP project on your computer ([on your computer](/docs/en-local)), Docker, and a
[Google Play developer account](https://play.google.com/console) (25 $ once). Your Claude can do it all for
you: ask it "put my game on Google Play".

## 1. Build the app

```bash
bin/jumphp twa my-game --url https://jumphp.net/my-game
```

The first time, the command asks:

- the app's **package name** (for example `io.github.me.my_game`): its id on the Play Store, it never changes;
- to **accept the license of Google's Android SDK** (downloaded once, ~1 GB, into `~/.jumphp`).

It creates your **upload key** (`~/.jumphp/android/keys/`, with its password: back them up, never put them in
git), then builds into `games/my-game/dist/android/`:

| File | For |
|---|---|
| `my-game-1.aab` | the Play Store |
| `my-game-1.apk` | trying it on your phone (`adb install`, or copy it there) |
| `assetlinks.json` | proving that the site and the app belong together (step 2) |

It prints your key's **SHA-256 fingerprint**. The name under the icon is the game's short name
(`pwa: {short_name: …}` in `game.yaml`, else its title).

## 2. Tell the site the app is yours

Without it, the app opens with an address bar. In the **Studio**, on your game's page, **Android app** part:
the package name and the fingerprint. The site publishes them in
`https://jumphp.net/.well-known/assetlinks.json`, which Android checks.

Your Claude does it with `update_project` (`android_package`, `android_fingerprints`). On your own server, put
`assetlinks.json` at `https://your-domain/.well-known/assetlinks.json`.

## 3. Publish it on Google Play

1. In the [Play Console](https://play.google.com/console): **Create app**, with the same package name.
2. **Internal testing** first: upload the `.aab`, install it from the test link.
3. **App signing** (Setup): Google signs the app with its own key; copy its SHA-256 fingerprint and **add it**
   next to yours (step 2), or the address bar comes back.
4. The store listing (screenshots: `bin/jumphp shot`), the content rating, then production.

## Next versions

The game's code updates by itself (it is the site). To change the app itself (name, icon, colours):

```bash
bin/jumphp twa my-game      # the version number goes up by one
```

`games/my-game/android/twa-manifest.json` keeps the package, the address and the version: it goes into git.
