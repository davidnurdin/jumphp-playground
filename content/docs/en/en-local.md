# On your computer

Everything goes through one command, `bin/jumphp`, which runs PHP, Composer, the WebAssembly compiler, the web
server and the tests **in Docker**. All you need is Docker and git.

```bash
composer create-project jumphp/jumphp my-games   # or: git clone https://github.com/davidnurdin/jumPhp.git my-games
cd my-games
bin/jumphp new my-game "MY GAME"      # a game from the skeleton (a small platform game)
bin/jumphp install my-game
bin/jumphp run my-game                # the game in a window, with sound
```

`composer create-project` starts a wizard that checks the tools, creates your game and offers to install the
**`jumphp` command** for the whole system (`bin/jumphp cli:install`, in `~/.local/bin`):

- `jumphp test my-game` from any folder of the project (instead of `bin/jumphp` at its root);
- `jumphp new other-project` to start another one (with Composer, or in Docker without it);
- once a day it checks Packagist for a **new version of JumPHP** and **asks you** whether to update the project
  (`bin/jumphp update`): yes, no (later) or "not this version". `JUMPHP_NO_UPDATE_CHECK=1` silences it;
  `bin/jumphp cli:uninstall` removes it.

| Command | |
|---|---|
| `bin/jumphp test my-game` | checks syntax, static analysis, and plays 600 frames without a screen (the slightest PHP warning is an error) |
| `bin/jumphp shot my-game 120` | a screenshot after 2 seconds of play, without a window |
| `bin/jumphp build my-game` | the browser version (`dist/web`), then `bin/jumphp serve my-game` |
| `bin/jumphp build:remote my-game` | the game as this site loads it (shared engine, files one by one) |
| `bin/jumphp deploy my-game` | on your own server |
| `bin/jumphp twa my-game --url …` | an Android app for Google Play ([the guide](/docs/android)) |

To publish it here: push your project to a public GitHub repository, then [import it](/docs/importer). Every
push is then republished.

The project contains an `AGENTS.md`: it is the complete JumPHP documentation (and what your Claude reads).
