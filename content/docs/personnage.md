# Tutoriel 2 — Un personnage qui bouge et s'anime

Les choses qui bougent sont des **entités** (`src/Entity/`), créées avec `new` par les scènes. Une entité a
une position (`$this->x`, `$this->y`), une vitesse (`vx`, `vy`), une taille, une direction (`facing`) et un état
(`state`). Des *traits* ajoutent des capacités : `Sprite` (l'animation), `Gravity` (la chute),
`TileCollision` (savoir si on touche le sol).

## Un sprite dessiné en PHP

Pas besoin d'image : `createTextureFromPixels()` fabrique une texture à partir de lignes de texte. Chaque
caractère est un pixel, `.` est transparent.

```php
private const SHEET = [            // 3 images de 12 × 14 : immobile, marche, saut
    '...####........####........####.....',
    '..######......######......######....',
    '..##o#o#......##o#o#......##o#o#....',
    // ...
];

public function __construct(Sdl $sdl, float $x, float $y)
{
    $this->x = $x;
    $this->y = $y;
    $this->width = 10;
    $this->height = 14;
    if (!$sdl->hasTexture('player')) {
        $sdl->createTextureFromPixels('player', self::SHEET, ['#' => '#ffd23f', 'o' => '#202020']);
    }
    $this->initSprite($sdl, 'player', 'idleRight');
}
```

Avec une vraie planche de sprites : `$sdl->loadTexture('player', 'assets/sprites/player.png')`.

## Les animations

Une animation est une liste d'images de la planche. Le moteur choisit l'animation `<état><Direction>` :
`walkRight`, `idleLeft`… `reverseWalkRight` réutilise les images retournées.

```php
protected array $animations = [
    'idleRight' => ['coords' => [['x' => 0, 'y' => 0, 'w' => 12, 'h' => 14]]],
    'walkRight' => ['coords' => [['x' => 12, 'y' => 0, 'w' => 12, 'h' => 14], ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 14]], 'duration' => 0.12],
    'jumpRight' => ['coords' => [['x' => 24, 'y' => 0, 'w' => 12, 'h' => 14]]],
    'idleLeft' => 'reverseIdleRight',
    'walkLeft' => 'reverseWalkRight',
    'jumpLeft' => 'reverseJumpRight',
];
```

## Bouger avec de l'élan

```php
use Sprite, Gravity, TileCollision {
    Sprite::update as updateSprite;
    Gravity::update as updateGravity;
}

public function control(int $move, bool $jumpPressed, bool $jumpHeld, float $dt): void
{
    // accélère doucement vers la vitesse voulue
    $this->vx = Math::approach($this->vx, $move * 110, 900 * $dt);
    if ($move !== 0) {
        $this->facing = $move < 0 ? 'left' : 'right';
    }
    if ($jumpPressed && $this->onGround) {
        $this->vy = -290;
    }
    if (!$jumpHeld && $this->vy < -100) {
        $this->vy = -100;    // bouton relâché tôt : saut plus court
    }
}

public function update(float $dt): void
{
    $this->updateGravity($dt);
    $this->state = !$this->onGround ? 'jump' : (abs($this->vx) > 10 ? 'walk' : 'idle');
    $this->pickAnimation();      // choisit "<state><Facing>"
    $this->updateSprite($dt);    // image suivante
}

public function render(Renderer $r, float $camX = 0, float $camY = 0): void
{
    $this->drawAt($r, $this->x - 1 - $camX, $this->y - $camY);
}
```

Dans la scène : `$player->control($this->actions()->axis('left', 'right'), $this->actions()->isPressed('jump'),
$this->actions()->isHeld('jump'), $dt)`, puis `$player->update($dt)`. Le déplacement contre les murs et le sol
est le travail du système de collisions : [tutoriel 3](/docs/plates-formes).

Ce code est celui du squelette de JumPHP (`bin/jumphp new`) : `src/Entity/Player.php`.
