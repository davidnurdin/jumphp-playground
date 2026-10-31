# Your game as a Mac, iPhone and iPad app

JumPHP makes your game **real Apple apps**, with no browser: PHP 8.5, SDL, OpenGL and OpenAL run inside the app,
as on a computer and in [the native Android app](/docs/android-natif).

- **Mac**: one app for Apple silicon (M1 and later) and Intel Macs, macOS 11 or newer, as a `.dmg`;
- **iPhone and iPad**: an `.ipa`, iOS 14 or newer, with the on-screen controls drawn by the engine and the game's
  orientation; and the same app for Xcode's **simulator**.

Building an Apple app needs Xcode, so a Mac. You don't need one: **GitHub builds it on one of its Macs** (free for
a public repository), from any computer. You need a JumPHP project on your computer ([on your computer](/docs/en-local))
and a GitHub repository. Your Claude can do it all for you: ask it "make the Mac and iPhone apps of my game".

## One command

```bash
bin/jumphp apple my-game      # Mac + iPhone / iPad
bin/jumphp macos my-game      # only the Mac
bin/jumphp ios my-game        # only the iPhone / iPad (and the simulator)
```

1. The first time, it asks the app's **identifier** (`io.github.you.my-game`: it never changes once on the App
   Store).
2. It writes the version in `games/my-game/apple/app.json`, puts the workflow `.github/workflows/apple.yml` in your
   project, commits them and, **after your yes**, pushes.
3. **GitHub builds the apps on a Mac** (first time ~40 minutes, then ~10: PHP and the libraries are kept in the
   cache), then **tries them**: the Mac app runs the game and keeps a picture of it, the iPhone app is installed in
   a simulated iPhone, launched and photographed.
4. It publishes a release of your repository with the apps and both screenshots, and downloads them into
   `games/my-game/dist/apple/` (the screenshots in `check/`: look at them).

Next versions: `bin/jumphp apple my-game --version 1.1` (the build number goes up by one each time). On a Mac with
Xcode: `--local` builds everything there.

**Files outside git** (an original game's data, kept out of a public repository, like the playground's
[private files](/docs/importer)): the command finds them and, after your yes, puts them in a **draft**
release of your repository, which only its writers see; GitHub's Mac puts them back into the game before building
it. The apps themselves contain these files: their release is then a draft too, you decide whether to publish it.

## Without an Apple developer account (to start)

The apps are signed "ad hoc", with no identity:

- **Mac**: the first time, **right click on the app > Open** (macOS asks once). Fine for you, your friends, your
  testers.
- **iPhone / iPad**: an iPhone only installs an app signed for it. The `.ipa` waits for the account (below). In the
  meantime: the simulator's app (on a Mac with Xcode), and the web version installs from Safari (Share > Add to Home
  Screen).

## With an Apple developer account (next)

An [Apple Developer Program](https://developer.apple.com/programs/) account ($99 a year) is needed to:

- open the Mac app without the right click: **Developer ID** signing and **notarization** by Apple;
- install the app on an iPhone, send it to **TestFlight**, then to the **App Store**.

That is JumPHP's next step. The App Store accepts a PHP game: the code runs inside the app and ships with it,
nothing is downloaded later (guideline 2.5.2).

## Touch controls

On iPhone and iPad the engine draws the **on-screen controls** as in the browser and on Android: the d-pad (or a
joystick), A, B and START. They are set in `game.yaml`:

```yaml
game:
  touch: { pad: stick, a: Space, b: Escape, labels: { a: JUMP } }   # pad: stick = joystick, else the d-pad
```

A gamepad (Bluetooth, MFi) works too. On a Mac: keyboard, mouse, gamepad, as on a computer.

## Inside the app

- PHP 8.5 built for Mac (Apple and Intel) and iPhone with JumPHP's extensions (SDL and its image, sound and font
  modules, OpenGL, OpenAL, gd, openssl, mbstring...), SDL2 and OpenAL Soft. No JIT: Apple forbids it on iPhone.
- Your game, as is, inside the app. Saves and options go to the app's folder (Application Support) and stay from
  one update to the next.
- The name, the icon (`assets/icon.png`, else the title's initial) and the orientation (the game's shape) come from
  `game.yaml`.
- 3D games (`gl()`, `opengl()`) work: OpenGL ES on iPhone, OpenGL on Mac (the engine adapts the shaders itself).
- HTTPS works (`$engine->http()`, online play over `wss://`).

Technical details: `docs/apple.md` in your project.
