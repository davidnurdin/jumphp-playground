# Importing an existing repository

[Import](/studio/importer) publishes a JumPHP game that is already on GitHub (a public repository you have
rights to).

The playground finds the game:

- in `games/<id>/` (a complete JumPHP project): the requested folder, or the only game in the repository;
- or at the root (the repository **is** the game folder: `config/game.yaml` at the root).

It publishes the latest commit of the main branch, then every push (webhook). The **Synchronize** button on the
project page (or the `sync_project` MCP tool) publishes right away and shows the errors.

What is published and how:

- only the game folder; the engine and its packages come from the site's shared engine (Composer packages
  specific to the game are not available);
- the engine version follows the repository's `VERSION` file;
- a commit with a PHP syntax error is not published (the previous version stays online);
- not a single line of the game's PHP runs on the server: everything runs in the players' browsers;
- a game without an image is photographed by the site (its screen, in a browser);
- files that cannot be public (the data of an original game) are provided separately: Studio → the project →
  **Private files**. The game loads them; no repository or fork contains them.
