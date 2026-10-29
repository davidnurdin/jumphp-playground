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

Also: `press($action)`, `run($frames)`, `wait($seconds)`, `scene()`, `shot($name)` (a screenshot). Name the tests
as sentences (`testTheBallBouncesOnThePaddle`): they are shown that way (“The ball bounces on the paddle”).

**You watch them play**, everywhere:

- **on your computer**: `bin/jumphp test <game>` runs them, and [the JumPHP pane for Claude
  Code](/docs/panneau-claude) shows each test live (at the game's speed), then its last frame;
- **on the site**: the playground's sandbox plays them without a window and **films each test** (one frame every
  4 of the game's). The Studio's tests page shows them while they run, then replays them in a loop: ▶ / ⏸, a
  slider to go frame by frame, a click to open the frame full size.

These tests need `JumPHP\Testing` (JumPHP 0.2.1 or newer): a game on 0.2.0 is tested on the site with the newest
engine, and the output says so. On your computer, `bin/jumphp update` brings JumPHP up to date.

When Claude develops your game, it writes these tests **as it goes**: each mechanic (a move, a jump, a shot, a
pickup, an enemy, the end of a level) gets a test that plays it, run after each change.

## Running them

- **Studio** → your project → **Tests (PHPUnit)** → “▶ Run the tests”: the page updates by itself (the game
  tests' films appear while they run), then shows each test (✔ / ✘ and the failure message), the film of each
  game test and PHPUnit's full output.
- **Your Claude**: `run_tests` then `get_test_run` (MCP server), for instance after each `sync_project`.

## The sandbox

The tests run on the playground's server, isolated: PHP 8.5 (as the engine in the browser), PHPUnit 11,
**no network**, as a user without rights, **120 seconds** and 256 MB at most, no window or sound (SDL runs
“blank”: the game tests still draw every frame, for the film). The game's private files are not there. Only the
game's owner sees the results and the films (those of the last 5 runs are kept).
