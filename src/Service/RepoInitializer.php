<?php

namespace App\Service;

use App\Entity\Project;
use App\GitHub\GitHub;
use App\Message\InitRepository;
use App\Security\TokenCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Sets up the repository of a project (InitRepository, in the worker):
 * - new: a JumPHP project (the framework at the version of the newest engine here, with its history, so
 *   `bin/jumphp update` works) with one game, games/<slug>, made from the skeleton, BRIEF.md (what the creator
 *   asked), pushed as the creator to their new repository;
 * - fork: waits for GitHub to finish the fork;
 * then the webhook (every push is published) and the first publication.
 */
final class RepoInitializer
{
    public function __construct(
        private GitHub $github,
        private TokenCipher $cipher,
        private Shell $shell,
        private Runtimes $runtimes,
        private Publisher $publisher,
        private EntityManagerInterface $em,
        private string $storageDir,
        private string $appUrl,
        private string $jumphpRepo,
    ) {
    }

    public function init(Project $project, string $mode): SyncResult
    {
        try {
            $token = $this->cipher->decrypt((string)$project->getOwner()->getGithubToken());
            if ($mode === InitRepository::NEW) {
                $this->makeProject($project, $token);
            } elseif ($mode === InitRepository::FORK) {
                $this->waitForFork($project, $token);
            }
            $this->hook($project, $token);
        } catch (\Throwable $e) {
            $project->setStatus(Project::STATUS_ERROR, 'Création du dépôt impossible : ' . $e->getMessage());
            $this->em->flush();
            return new SyncResult(SyncResult::FAILED, null, [$e->getMessage()]);
        }
        $project->setStatus(Project::STATUS_EMPTY);
        $this->em->flush();
        return $this->publisher->sync($project, true);
    }

    private function makeProject(Project $project, string $token): void
    {
        $owner = $project->getOwner();
        $engine = $this->runtimes->pick(null);
        $fs = new Filesystem();
        $work = "$this->storageDir/tmp/init-{$project->getId()}-" . bin2hex(random_bytes(4));
        $fs->mkdir(dirname($work));
        try {
            $clone = ['git', 'clone', '--quiet', '--origin', 'jumphp'];
            if ($engine && $this->shell->tryRun(['git', 'ls-remote', '--exit-code', '--tags', $this->jumphpRepo, "v$engine"]) !== null) {
                array_push($clone, '--branch', "v$engine");
            }
            $this->shell->run([...$clone, $this->jumphpRepo, $work], null, [], 600);
            $this->shell->run(['git', 'checkout', '--quiet', '-B', 'main'], $work);

            // one game: the skeleton, named after the project
            foreach (glob("$work/games/*", GLOB_ONLYDIR) ?: [] as $example) {
                $this->shell->run(['git', 'rm', '-r', '--quiet', 'games/' . basename($example)], $work);
            }
            $id = $project->getGameId();
            $game = "$work/games/$id";
            $fs->mirror("$work/skeleton", $game);
            $this->shell->run(['php', '../../tools/new-game.php', $id, $project->getTitle()], $game);
            $yaml = (string)file_get_contents("$game/config/game.yaml");
            $yaml = preg_replace('/^(\s*description:\s*).*/m', '${1}' . json_encode($project->getDescription(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $yaml, 1);
            file_put_contents("$game/config/game.yaml", $yaml);
            file_put_contents("$game/BRIEF.md", $this->brief($project));
            file_put_contents("$work/README.md", $this->readme($project));

            $this->shell->run(['git', 'add', '-A'], $work);
            $author = [
                'GIT_AUTHOR_NAME' => $owner->getDisplayName(),
                'GIT_AUTHOR_EMAIL' => $owner->getGithubId() . '+' . $owner->getLogin() . '@users.noreply.github.com',
            ];
            $author += ['GIT_COMMITTER_NAME' => $author['GIT_AUTHOR_NAME'], 'GIT_COMMITTER_EMAIL' => $author['GIT_AUTHOR_EMAIL']];
            $this->shell->run(['git', 'commit', '--quiet', '-m', "{$project->getTitle()}: a new JumPHP game (JumPHP playground)"], $work, $author);
            $this->shell->run(['git', 'remote', 'add', 'origin', $project->getCloneUrl()], $work);
            $this->shell->run(['git', 'push', '--quiet', '-u', 'origin', 'main'], $work, Shell::gitAuth($owner->getLogin(), $token), 600);
            $project->setDefaultBranch('main');
        } finally {
            $fs->remove($work);
        }
    }

    private function waitForFork(Project $project, string $token): void
    {
        // GitHub makes a fork in the background: a few seconds, sometimes minutes
        for ($i = 0; $i < 60; $i++) {
            $repo = $this->github->repo($project->getRepoFullName(), $token);
            if ($repo && !empty($repo['pushed_at']) && $this->shell->tryRun(['git', 'ls-remote', '--exit-code', '--heads', $project->getCloneUrl()]) !== null) {
                $project->setDefaultBranch($repo['default_branch'] ?? 'main');
                return;
            }
            sleep(3);
        }
        throw new \RuntimeException('GitHub has not finished the fork after 3 minutes: try "Synchroniser" later');
    }

    /** the GitHub webhook: each push publishes the game (only for a playground reachable from the internet) */
    private function hook(Project $project, string $token): void
    {
        $host = (string)parse_url($this->appUrl, PHP_URL_HOST);
        if (!str_starts_with($this->appUrl, 'https://') || preg_match('/(^|\.)(localhost|test|local)$/', $host) || $project->getWebhookId()) {
            return;
        }
        $url = rtrim($this->appUrl, '/') . '/webhook/github/' . $project->getId();
        $project->setWebhookId($this->github->createHook($token, $project->getRepoFullName(), $url, $project->getWebhookSecret()));
    }

    private function brief(Project $project): string
    {
        $url = rtrim($this->appUrl, '/') . '/' . $project->getSlug();
        $id = $project->getGameId();
        $kind = match ($project->getKind()) {
            'cpc' => <<<MD

## A remake of an Amstrad CPC game: use dsk2jumphp

This game is the remake of an Amstrad CPC game, **with its original graphics, music, sounds, levels and
rules**, taken from its disc. Follow `AGENTS.md` section 12 to the letter:

- use **[dsk2jumphp](https://github.com/davidnurdin/dsk2jumphp)** (headless CPC emulator, DSK / dump / Z80 /
  sound tools, the method): clone it **outside this repository**, `make -C <it> test`, link its Claude skill
  (`.claude/skills/dsk2jumphp`), read its `AGENTS.md` and `docs/method.md`. Do not write an emulator, a
  disassembler or a DSK reader yourself;
- **ask the user for the path of the game's `.dsk` on their computer** (never download a disc or CPC ROMs;
  ROMs only from the user's machine if the game needs the firmware);
- keep the extraction reproducible in `games/$id/tools/extract/` (scripts + README of the addresses + one
  script that rebuilds `assets/` from the .dsk path); **never commit the .dsk, ROMs or memory dumps**;
- the original's screen size, rules and timings (50 Hz); check it side by side with the emulator
  (`cpc -s` against `bin/jumphp shot`);
- if the extracted assets must not be public, keep them out of git (`.gitignore`) and give them to the
  playground as **private files** (MCP tool `upload_private_files`, or Studio → the project → "Fichiers
  privés"): the game loads them, no repository holds them.
- Example: the Rainbow Islands remake, https://github.com/davidnurdin/rainbow-island (`tools/extract/`).

MD,
            'port' => <<<MD

## A port of an existing game

This game is the port to PHP of an existing game whose source code exists (see above where it is). Keep its
rules, levels and feel; translate its code into readable PHP in the JumPHP structure (scenes, entities), with
its assets if their license allows them in a public repository (else: private files of the playground, MCP tool
`upload_private_files` or Studio → the project → "Fichiers privés"). Credit the original authors and keep their license.

MD,
            default => '',
        };
        return <<<MD
# {$project->getTitle()}

{$project->getBrief()}
$kind
---

## The JumPHP playground

This project was made on the JumPHP playground: the game's page is $url
(category: {$project->getCategory()->getName()}).

- The game is `games/$id/`. Read `AGENTS.md` (the JumPHP contract) first; replace the skeleton with the game
  described above; follow the definition of done (tests, screenshots you look at, browser build).
- **Develop with game tests, visually**: for each mechanic, as you write it, a test in `games/$id/tests/` that
  extends `JumPHP\Testing\GameTestCase` and plays it ("the player jumps onto the platform", "the enemy dies
  when hit"; AGENTS.md "Game tests"); `bin/jumphp test $id` after each change. The user watches them play live
  in Claude Code (the `jumphp-live` pane) and, after a push, replays each test filmed in the studio (MCP
  `run_tests`).
- **Publishing**: every push to `main` is online within a minute (GitHub webhook). The MCP tool
  `sync_project` (server `jumphp`) publishes at once and returns the errors: a commit with a PHP syntax error
  is not published (the previous version stays online).
- The playground loads the game file by file with its shared engine: Composer packages other than
  `jumphp/engine` are not available there.
- **The game's home page**: make `games/$id/site/` (AGENTS.md section 13: themed with the game's own graphics,
  SEO, a link to `play/`); it is the game's page on the playground. Also `games/$id/assets/thumbnail.png`.
- When the game is done: MCP `update_project` with `listed: true` shows it in the playground's directory.
MD;
    }

    private function readme(Project $project): string
    {
        $url = rtrim($this->appUrl, '/') . '/' . $project->getSlug();
        return <<<MD
# {$project->getTitle()}

{$project->getDescription()}

**Play: $url**

Made with [JumPHP](https://github.com/davidnurdin/jumPhp): retro games in PHP, on the desktop and in the browser.
The game is in `games/{$project->getGameId()}/`.

```bash
bin/jumphp install {$project->getGameId()}    # dependencies (everything runs in Docker)
bin/jumphp run {$project->getGameId()}        # play on the desktop
bin/jumphp test {$project->getGameId()}       # checks + a headless demo
```
MD;
    }
}
