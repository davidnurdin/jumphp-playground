# Tester son jeu (PHPUnit)

Un jeu JumPHP peut avoir des **tests PHPUnit** : le calcul du score, les collisions, l'IA, le chargement d'un
niveau… Le playground les fait tourner **sur la version publiée**, dans un bac à sable, et vous montre le
résultat dans le Studio, test par test.

## Écrire un test

Dans le dossier du jeu, un dossier `tests/` avec des classes `…Test.php` (PHPUnit 11) :

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

Le code du jeu est chargé comme dans le jeu (les espaces de noms de son `composer.json`, `App\` → `src/`), avec
le moteur JumPHP de sa version. `Tests\` → `tests/`. Sur votre ordinateur, `bin/jumphp test <jeu>` les lance
aussi (avec le reste des vérifications).

## Des tests qui jouent le jeu

Un **test de jeu** fait jouer le jeu, image par image, puis vérifie ce qui s'est passé : « la raquette va de haut
en bas », « la balle rebondit », « le saut retombe sur la plate-forme ». Il étend `JumPHP\Testing\GameTestCase` :

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
        $this->game->start(PlayScene::class, ['mode' => 'local']);  // la scène, tout de suite
        $paddle = $this->game->peek('left');                       // un champ de la scène, même privé
        $this->game->hold('p1_up', 60);                            // la touche tenue 60 images (1 s)
        self::assertEqualsWithDelta(PlayScene::TOP, $paddle->getBoundingBox()['y'], 1);
        $this->game->hold('p1_down', 90);
        self::assertEqualsWithDelta(PlayScene::BOTTOM - Paddle::HEIGHT, $paddle->getBoundingBox()['y'], 1);
    }
}
```

Aussi : `press($action)`, `run($images)`, `wait($secondes)`, `scene()`, `shot($nom)` (une capture). Ces tests ont
besoin de SDL : ils tournent sur votre ordinateur avec `bin/jumphp test`, et **on les regarde jouer** dans
[le panneau JumPHP de Claude Code](/docs/panneau-claude) (chaque test en direct, puis sa dernière image). Le bac
à sable du playground les laisse de côté et le dit.

## Les lancer

- **Studio** → votre projet → **Tests (PHPUnit)** → « ▶ Lancer les tests » : la page se met à jour toute seule,
  puis montre chaque test (✔ / ✘ et le message d'échec) et la sortie complète de PHPUnit.
- **Votre Claude** : `run_tests` puis `get_test_run` (serveur MCP), par exemple après chaque `sync_project`.

## Le bac à sable

Les tests tournent sur le serveur du playground, isolés : PHP 8.3 (comme le moteur dans le navigateur),
PHPUnit 11, **sans réseau**, sous un utilisateur sans droits, **120 secondes** et 256 Mo au plus, sans fenêtre
ni son (pas de SDL : testez la logique du jeu, pas l'affichage). Les fichiers privés du jeu n'y sont pas. Seul
le propriétaire du jeu voit les résultats.
