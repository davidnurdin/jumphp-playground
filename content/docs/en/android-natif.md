# Your game as a native Android app

JumPHP makes your game a **real Android app**, with no browser: PHP 8.5, SDL, OpenGL ES and OpenAL run inside the
app, as on a computer. It works **offline**, on phones (ARM) and tablets, Android 8.0 or newer.

You need a JumPHP project on your computer ([on your computer](/docs/en-local)), Docker, and for the Play Store a
[Google Play developer account](https://play.google.com/console) ($25 once). Your Claude can do it all for you:
ask it "make a native Android app of my game".

The other way, [the Trusted Web Activity](/docs/android), puts the game's page in Chrome: lighter, but it needs
the network and the site. With the same package and key, the native app replaces the TWA on the Play Store, as a
new version.

## One command

```bash
bin/jumphp android my-game
```

1. The first time, it asks for the app's **package name** (`io.github.you.my_game`: it never changes once
   published) and makes your **upload key** in `~/.jumphp/android/keys/` (the TWA's if the game has one).
   **Back it up**; it is never in git.
2. It puts the workflow `.github/workflows/android.yml` in your project and, **after your yes**, sends the key to
   your GitHub repository's secrets (encrypted, read by the workflow only).
3. It writes the version in `games/my-game/android/app.json`, commits it and, **after your yes**, pushes.
4. **GitHub builds the app** (the first time ~25 minutes, then ~8: PHP and the libraries are cached) and
   publishes a release of your repository with the **`.apk`** (for a phone) and the **`.aab`** (for Google
   Play), signed with your key. The command downloads them into `games/my-game/dist/android-native/`.

The next versions: `bin/jumphp android my-game --version-name 1.1` (the version code goes up by one each time).
Without GitHub: `--local` builds the app on your computer, in Docker.

To try it on your phone: send it the `.apk` and open it (allow "install unknown apps"), or
`adb install my-game-1.0.apk`.

## Touch controls

The engine draws the **on-screen controls** itself, as in the browser: the d-pad (or a joystick), A, B and START,
in the black bands on the sides when there are some. They are set in `game.yaml`, as for the browser:

```yaml
game:
  touch: { pad: stick, a: Space, b: Escape, labels: { a: JUMP } }   # pad: stick = joystick, else the d-pad
```

Android's back button is Escape. To see them on your computer: `JUMPHP_TOUCH_CONTROLS=1 bin/jumphp run my-game`
(with the mouse).

## In the app

- PHP 8.5 built for Android with JumPHP's extensions (SDL with its image, sound and font modules, OpenGL, OpenAL,
  gd, mbstring…), SDL2 and OpenAL Soft: what runs the game on a computer.
- Your game, unpacked on the first launch of each version (a second or two), in the app's private storage. Saves
  and options stay there from one update to the next.
- The name, the icon (the PWA's: `assets/icon.png`) and the orientation (the game's shape) come from `game.yaml`.

## On the Play Store

1. In the [Play Console](https://play.google.com/console), **create the app** (name, language, "Game", free) and
   fill in the declarations: privacy policy (a page saying the game collects nothing), content rating, target
   audience, no ads.
2. **Play App Signing**: Google keeps the key that signs the app for phones; yours only proves the uploads come
   from you (if lost, Google can replace it).
3. **Internal testing**: Test and release > Internal testing > Create new release > upload the `.aab` > Save >
   Review > Roll out. Add your testers; they install from Google's link.
4. **The listing**: descriptions, a 512 × 512 icon (`bin/jumphp build` makes it: `dist/web/icon-512.png`), a
   1024 × 500 image, at least 2 screenshots (`bin/jumphp shot my-game`, or on a phone).
5. **Production**: when the tests are good, promote the release. Google reviews it (a few hours to a few days).
   A new personal account must first have 12 testers for 14 days.

## Good to know

- No HTTPS in the app yet: `$engine->http()` and online play don't work on Android yet.
- The app weighs 30 to 40 MB (PHP and the libraries for two processors).

The technical details: `docs/android-native.md` in your project.
