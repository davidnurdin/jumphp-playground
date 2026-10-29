# Tester son jeu (PHPUnit)

Un jeu JumPHP peut avoir des **tests PHPUnit** : le calcul du score, les collisions, l'IA, le chargement d'un
niveau… et surtout des **tests qui jouent le jeu** (« la raquette va de haut en bas ») que l'on **regarde jouer**.
Le playground les fait tourner **sur la version publiée**, dans un bac à sable, et vous montre le résultat dans
le Studio, test par test, **avec le film de chaque test de jeu**.

Quand Claude développe votre jeu, il écrit ces tests **au fur et à mesure** : chaque mécanique (un déplacement, un
saut, un tir, un bonus, un ennemi, la fin d'un niveau) a son test qui la joue, lancé après chaque changement.
Vous les voyez jouer en direct dans [le panneau JumPHP](/docs/panneau-claude), puis vous les revoyez filmés dans
le Studio après chaque publication.

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

Aussi : `press($action)`, `run($images)`, `wait($secondes)`, `scene()`, `shot($nom)` (une capture). Nommez les
tests comme des phrases (`testTheBallBouncesOnThePaddle`) : ils s'affichent ainsi (« The ball bounces on the
paddle »).

**On les regarde jouer**, partout :

- **sur votre ordinateur** : `bin/jumphp test <jeu>` les lance, et [le panneau JumPHP de Claude
  Code](/docs/panneau-claude) montre chaque test en direct (à la vitesse du jeu), puis sa dernière image ;
- **sur le site** : le bac à sable du playground les joue sans fenêtre et **filme chaque test** (une image toutes
  les 4 du jeu). La page des tests du Studio les montre pendant qu'ils tournent, puis les rejoue en boucle :
  ▶ / ⏸, un curseur pour aller image par image, un clic pour ouvrir l'image en grand.

Ces tests ont besoin de `JumPHP\Testing` (JumPHP 0.2.1 ou plus) : un jeu en 0.2.0 est testé sur le site avec le
moteur le plus récent, et la sortie le dit. Sur votre ordinateur, `bin/jumphp update` met JumPHP à jour.

## Les lancer

- **Studio** → votre projet → **Tests (PHPUnit)** → « ▶ Lancer les tests » : la page se met à jour toute seule
  (les films des tests de jeu apparaissent pendant qu'ils tournent), puis montre chaque test (✔ / ✘ et le
  message d'échec), le film de chaque test de jeu et la sortie complète de PHPUnit.
- **Votre Claude** : `run_tests` puis `get_test_run` (serveur MCP), par exemple après chaque `sync_project`.

## Le bac à sable

Les tests tournent sur le serveur du playground, isolés : PHP 8.5 (comme le moteur dans le navigateur),
PHPUnit 11, **sans réseau**, sous un utilisateur sans droits, **120 secondes** et 256 Mo au plus, sans fenêtre
ni son (SDL tourne « à blanc » : les tests de jeu dessinent quand même chaque image, pour le film). Les fichiers
privés du jeu n'y sont pas. Seul le propriétaire du jeu voit les résultats et les films (ceux des 5 dernières
exécutions sont gardés).
