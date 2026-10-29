# With your Claude

The quickest way to make a real game: you describe it, **Claude Code on your computer** writes it, tests it,
makes its home page and publishes it here. The playground has an **MCP server**: your Claude creates the
project, follows its publication and reads other people's games for inspiration.

## What you need

- a GitHub account (the game lives in a public repository of your account);
- [Claude Code](https://claude.com/claude-code);
- [Docker](https://docs.docker.com/get-docker/): JumPHP runs everything in containers (PHP, the browser build,
  the tests), nothing else to install.

## Connecting Claude to the playground

1. [Sign in with GitHub](/connect/github).
2. In the Studio, on the [MCP](/studio/mcp) page, create your token.
3. Copy the command shown and run it in a terminal:

```bash
claude mcp add --transport http --scope user jumphp https://<this site>/mcp --header "Authorization: Bearer jph_..."
```

The token gives access to your playground account: never put it in a repository.

## Making a game

In Claude Code, just ask:

> Create a platform game for me on the JumPHP playground: a ninja cat collects fish on the rooftops of Paris,
> 5 levels, enemy pigeons, a boss. Pixel art, 320 × 180 screen. Make its home page too.

Claude:

1. creates the project (`create_project`): a GitHub repository with JumPHP, a first game and your request in
   `BRIEF.md`;
2. clones it and writes the game following `AGENTS.md` (the JumPHP contract);
3. tests it (`bin/jumphp test`), **looks at the screenshots** it takes itself, and checks the browser build;
4. makes the game's home page (`site/`) with its real images;
5. pushes to GitHub: the playground publishes the game within a minute (`sync_project` publishes right away and
   returns the errors);
6. when the game is ready, shows it in the directory (`update_project listed=true`).

You can also create the project yourself ([New game](/studio/nouveau)): the project page gives the command to
run to get Claude started on it.

## The MCP tools

| Tool | Purpose |
|---|---|
| `whoami` | your account and the site's addresses |
| `list_categories` | the directory's categories |
| `create_project` | a new game (title, description, category, type, request) |
| `get_project`, `list_projects` | the state of your projects, their publishing errors |
| `update_project` | title, description, category, visible or not in the directory |
| `sync_project` | publish the latest commit now |
| `import_project` | publish an existing JumPHP repository |
| `list_games`, `list_game_files`, `read_game_file` | read published games (get inspired, understand) |

The `cpc` game type makes Claude work with [dsk2jumphp](/docs/remake-amstrad) for an Amstrad remake.
