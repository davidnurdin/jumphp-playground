# The JumPHP pane for Claude Code

While Claude writes your game, **see what it does**: a pane beside the conversation shows the tests as they run,
the game's screens it looks at, and lets you step in. It is a Claude Code *mod*, `jumphp-live`, shipped with
[JumPHP](https://github.com/davidnurdin/jumPhp/tree/main/claude-mod/jumphp-live).

## Installing it

In Claude Code (in a terminal), type:

```
/plugin install jumphp-live --marketplace davidnurdin/jumPhp
```

Answer `y` to add the source, then pick the scope (“user”: for all your projects). From a shell:
`claude plugin install jumphp-live --marketplace davidnurdin/jumPhp`.

## Using it

In your JumPHP project (the folder with `bin/jumphp`), start Claude Code and type `/jumphp <game>`, for instance
`/jumphp pong`. The pane opens on the right (the window must be wide enough, 110 columns or more). It also opens
by itself the first time Claude runs `bin/jumphp test` or `bin/jumphp shot`.

What it shows:

- **The checks, live**: when Claude runs `bin/jumphp test`, the output scrolls in the pane and each step is ticked
  as it goes: `✔ php -l   ✔ phpstan   … phpunit   smoke test`. At the end: PHPUnit's summary and what failed (the
  failing tests, PHP warnings and errors).
- **The game's screen, live** (`● live`): while `bin/jumphp test` (its smoke test) or `bin/jumphp shot` runs the
  game, the pane shows its screen frame after frame (the engine rewrites `var/live/frame.png`, option
  `JUMPHP_LIVE`). Runs without a window go faster than real time: `JUMPHP_REALTIME=1` slows them to the game's
  speed. A project made before this option gets it with `git pull jumphp main` (the original JumPHP repository,
  kept under the name `jumphp`).
- **Game tests, before your eyes**: the [tests that play the game](/docs/tests) show as they run (⏳, then ✔ /
  ✘), with the running test's name over the live screen; afterwards, press a test to see its last frame. The
  **🐢 Game speed / ⏩ Fast** button (`r`) picks the speed: the game's (the default, to see the paddle move) or as
  fast as it goes.
- **The screens**: the screenshot `bin/jumphp shot` or `siteshot` just made, and every picture Claude opens to look
  at the game, drawn in the pane.
- **Pictures in any terminal**: by default they are drawn as small coloured blocks (`▦ Blocks`), which any
  truecolor terminal shows (GNOME Terminal, Konsole, Windows Terminal, VS Code…): for this the engine writes, beside
  each PNG, a small raw copy (`.rgb`). In kitty, Ghostty or iTerm2 the pane draws the PNG itself (`🖼 HD`); the `p`
  key switches. The **🖼 Open** button (`o`) opens the picture shown in your desktop's image viewer (`xdg-open`,
  `open` on macOS), full size.
- **The playground**: the result of the [site's PHPUnit tests](/docs/tests) (`run_tests`, `get_test_run`) and of
  publications (`sync_project`) when Claude uses the MCP server.

What you can do:

- **▶ Tests** (key `t`) and **📸 Shot** (`s`): run the tests or take a screenshot now, without Claude; the output
  scrolls in the pane.
- **🛠 Ask Claude to fix** (`f`), after a failure: Claude gets what failed and fixes it.
- **Tell Claude**: a field to write to it (“jump higher”, “the boss is too hard”); it reads it as soon as it has
  finished its current step.

## How it follows Claude

A Bash command of Claude's that runs `bin/jumphp test`, `shot`, `siteshot` or `webshot` runs as written, its output
also copied to a temporary file the pane follows (`{ export JUMPHP_LIVE=1; command } 2>&1 | tee …`, the exit status kept). Other commands
are not touched. Nothing is sent anywhere.
