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

## Running them

- **Studio** → your project → **Tests (PHPUnit)** → “▶ Run the tests”: the page updates by itself, then shows
  each test (✔ / ✘ and the failure message) and PHPUnit's full output.
- **Your Claude**: `run_tests` then `get_test_run` (MCP server), for instance after each `sync_project`.

## The sandbox

The tests run on the playground's server, isolated: PHP 8.3 (as the engine in the browser), PHPUnit 11,
**no network**, as a user without rights, **120 seconds** and 256 MB at most, no window or sound (no SDL: test
the game's logic, not the display). The game's private files are not there. Only the game's owner sees the
results.
