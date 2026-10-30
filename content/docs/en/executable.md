# Your game as an executable

To give your game to someone who has **nothing installed** (no PHP, no Docker): JumPHP makes it **one file**
to download and run, for Linux and for Windows. PHP, SDL, OpenGL and OpenAL are inside.

You need a JumPHP project on your computer ([on your computer](/docs/en-local)) and Docker. Your Claude can do it
for you: ask it "make an executable of my game".

```bash
bin/jumphp export:standalone my-game              # Linux: games/my-game/dist/standalone/my-game-linux-x86_64
bin/jumphp export:standalone my-game --windows    # Windows: my-game-windows-x86_64.exe
bin/jumphp export:standalone my-game --linux --windows
```

## How it is made

- **PHP** is [StaticPHP](https://github.com/crazywhalecc/static-php-cli)'s (*micro*): PHP 8.5 as one executable,
  with JumPHP's extensions (SDL with its image, sound and font modules, OpenGL, OpenAL, gd, zip…), all built in.
- **Your game** (its code, pictures, sounds, the engine) is appended to it, as a PHAR archive.

That PHP is the same for every game: it is built once (~15 minutes on Linux) or downloaded from JumPHP's
releases, then kept in `~/.jumphp/standalone`. After that, an executable takes a few seconds.

## On the player's computer

The first launch unpacks the game into their cache (a second); later launches start at once. Saves and options
go to their data folder:

| | Linux | Windows |
|---|---|---|
| the game | `~/.cache/jumphp/` | `%LOCALAPPDATA%\JumPHP\` |
| the saves | `~/.local/share/<game id>/` | `%APPDATA%\<game id>\` |

On Linux it runs on any distribution since 2013 (glibc 2.17) and uses the computer's display (X11 or XWayland),
OpenGL and sound (PulseAudio, PipeWire, ALSA), as any Linux game does. On Windows (10 or newer), nothing to
install.

## Good to know

- The file is not signed: Windows shows "Windows protected your PC"; click **More info** then **Run anyway**.
  On Linux, after a download: `chmod +x my-game-linux-x86_64`.
- PHP for Windows can only be built on Windows: GitHub builds it (the `standalone` workflow of the JumPHP
  repository) and the command downloads it.
- x86_64 processors only (no Mac or ARM yet).

The technical details: `docs/standalone.md` in your project.
