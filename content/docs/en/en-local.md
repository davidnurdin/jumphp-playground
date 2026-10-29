# On your computer

Everything goes through one command, `bin/jumphp`, which runs PHP, Composer, the WebAssembly compiler, the web
server and the tests **in Docker**. All you need is Docker and git.

```bash
git clone https://github.com/davidnurdin/jumPhp.git my-games && cd my-games
bin/jumphp new my-game "MY GAME"      # a game from the skeleton (a small platform game)
bin/jumphp install my-game
bin/jumphp run my-game                # the game in a window, with sound
```

| Command | |
|---|---|
| `bin/jumphp test my-game` | checks syntax, static analysis, and plays 600 frames without a screen (the slightest PHP warning is an error) |
| `bin/jumphp shot my-game 120` | a screenshot after 2 seconds of play, without a window |
| `bin/jumphp build my-game` | the browser version (`dist/web`), then `bin/jumphp serve my-game` |
| `bin/jumphp build:remote my-game` | the game as this site loads it (shared engine, files one by one) |
| `bin/jumphp deploy my-game` | on your own server |

To publish it here: push your project to a public GitHub repository, then [import it](/docs/importer). Every
push is then republished.

The project contains an `AGENTS.md`: it is the complete JumPHP documentation (and what your Claude reads).
