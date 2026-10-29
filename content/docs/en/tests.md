# Testing your game (PHPUnit)

A JumPHP game can have **PHPUnit tests**: the score, the collisions, the AI, loading a level… The playground
runs them **on the published version**, in a sandbox, and shows you the result in the Studio, test by test.

## Writing a test

In the game's folder, a `tests/` folder with `…Test.php` classes (PHPUnit 11):

```php
<?php
// tests/ScoreTest.php
namespace Tests;

use App\Entity\Score;
use PHPUnit\Framework\TestCase;

final class ScoreTest extends TestCase
{
    public function testABrickIsWorthTenPoints(): void
    {
        $score = new Score();
        $score->brick();
        self::assertSame(10, $score->points());
    }
}
```

The game's code is loaded as in the game (the namespaces of its `composer.json`, `App\` → `src/`), with the
JumPHP engine of its version. `Tests\` → `tests/`. On your computer, `bin/jumphp test <game>` runs them too
(with the other checks).

## Tests that play the game

A **game test** plays the game, frame by frame, then checks what happened: “the paddle goes from the top to the
bottom”, “the ball bounces”, “the jump lands on the platform”. It extends `JumPHP\Testing\GameTestCase`:

```php
<?php
namespace Tests;

use App\Entity\Paddle;
use App\Scene\PlayScene;
use JumPHP\Testing\GameTestCase;

final class PaddleTest extends GameTestCase
{
    public function testThePaddleGoesFromTheTopToTheBottom(): void
    {
        $this->game->start(PlayScene::class, ['mode' => 'local']);  // the scene, at once
        $paddle = $this->game->peek('left');                       // a field of the scene, even private
        $this->game->hold('p1_up', 60);                            // the key held 60 frames (1 s)
        self::assertEqualsWithDelta(PlayScene::TOP, $paddle->getBoundingBox()['y'], 1);
        $this->game->hold('p1_down', 90);
        self::assertEqualsWithDelta(PlayScene::BOTTOM - Paddle::HEIGHT, $paddle->getBoundingBox()['y'], 1);
    }
}
```

Also: `press($action)`, `run($frames)`, `wait($seconds)`, `scene()`, `shot($name)` (a screenshot). These tests need
SDL: they run on your computer with `bin/jumphp test`, and **you watch them play** in
[the JumPHP pane for Claude Code](/docs/panneau-claude) (each test live, then its last frame). The playground's
sandbox leaves them out and says so.

## Running them

- **Studio** → your project → **Tests (PHPUnit)** → “▶ Run the tests”: the page updates by itself, then shows
  each test (✔ / ✘ and the failure message) and PHPUnit's full output.
- **Your Claude**: `run_tests` then `get_test_run` (MCP server), for instance after each `sync_project`.

## The sandbox

The tests run on the playground's server, isolated: PHP 8.3 (as the engine in the browser), PHPUnit 11,
**no network**, as a user without rights, **120 seconds** and 256 MB at most, no window or sound (no SDL: test
the game's logic, not the display). The game's private files are not there. Only the game's owner sees the
results.
