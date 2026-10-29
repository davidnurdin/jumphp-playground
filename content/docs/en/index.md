# Making a game with JumPHP

JumPHP is a framework for writing **2D retro games in PHP**. The same code runs on the desktop (PHP + SDL2, in a
real window) and **in the browser** (PHP compiled to WebAssembly): that is what powers every game on this site.
There are several ways to get started, from the quickest to the most complete.

| Way | Account | What you need | For whom |
|---|---|---|---|
| [Sandbox](/docs/bac-a-sable) | no | a browser | try it in 1 minute: change a hello world and run it |
| [With your Claude](/docs/avec-claude) | GitHub | Claude Code + Docker | describe your game and let it write, test and publish it |
| [Online editor](/docs/editeur-en-ligne) | GitHub | a browser | tweak a published game, or fork someone else's |
| [On your computer](/docs/en-local) | no (GitHub to publish) | Docker | develop like a pro: tests, screenshots, browser build |
| [Import a repository](/docs/importer) | GitHub | a JumPHP repository | publish a game that already exists |
| [Amstrad CPC remake](/docs/remake-amstrad) | GitHub | the game's `.dsk` disk | remake a CPC game with its original graphics and music |
| [Porting an existing game](/docs/portage) | GitHub | its source code | translate an old game (C, Pascal, BASIC…) into PHP |

Then the **tutorials** build a game step by step:

1. [Hello world: a menu, some text, a sound](/docs/hello-world)
2. [A character that moves and animates](/docs/personnage)
3. [A platform game: level, collisions, camera](/docs/plates-formes)
4. [Sound and music](/docs/sons)
5. [Menus, options, pause and languages](/docs/menus)
6. [Saving scores and options](/docs/sauvegarde)
7. [Online multiplayer](/docs/multijoueur)
8. [The game's home page and publishing](/docs/page-du-jeu)

Once the game is done: [put it on Google Play](/docs/android) (an Android app, made by `bin/jumphp twa`).

And for programs that want to read the directory: [the public API](/docs/api).

## How a JumPHP game works

A game is a `games/<id>/` folder of a JumPHP project:

```
games/my-game/
  config/game.yaml      the title, the resolution (320 × 180…), the first scene
  config/input.yaml     the actions (jump, shoot…) and their keys, gamepad, touch screen
  src/Scene/*.php       the screens: title, game, pause…
  src/Entity/*.php      what moves: the hero, the enemies…
  assets/               images, sounds, music, levels (optional: everything can be drawn in PHP)
  site/                 (optional) the game's home page
```

Every frame (60 times per second), the engine calls `update($dt)` on the displayed scene (you change the game
state), then `render($r)` (you draw). Coordinates are "virtual" pixels (the game's resolution): the engine
scales the image up to the size of the screen.

The complete framework contract, for humans and AIs alike, is the
[AGENTS.md](https://github.com/davidnurdin/jumPhp/blob/main/AGENTS.md) file of the JumPHP repository.
