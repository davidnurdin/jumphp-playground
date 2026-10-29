# Tutorial 2 — A character that moves and animates

Things that move are **entities** (`src/Entity/`), created with `new` by the scenes. An entity has a position
(`$this->x`, `$this->y`), a velocity (`vx`, `vy`), a size, a direction (`facing`) and a state (`state`).
*Traits* add abilities: `Sprite` (animation), `Gravity` (falling), `TileCollision` (knowing whether you touch
the ground).

## A sprite drawn in PHP

No image needed: `createTextureFromPixels()` builds a texture from lines of text. Each character is a pixel,
`.` is transparent.

```php
private const SHEET = [            // 3 frames of 12 × 14: idle, walk, jump
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

With a real sprite sheet: `$sdl->loadTexture('player', 'assets/sprites/player.png')`.

## Animations

An animation is a list of frames from the sheet. The engine picks the `<state><Direction>` animation:
`walkRight`, `idleLeft`… `reverseWalkRight` reuses the frames, flipped.

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

## Moving with momentum

```php
use Sprite, Gravity, TileCollision {
    Sprite::update as updateSprite;
    Gravity::update as updateGravity;
}

public function control(int $move, bool $jumpPressed, bool $jumpHeld, float $dt): void
{
    // smoothly accelerates towards the target speed
    $this->vx = Math::approach($this->vx, $move * 110, 900 * $dt);
    if ($move !== 0) {
        $this->facing = $move < 0 ? 'left' : 'right';
    }
    if ($jumpPressed && $this->onGround) {
        $this->vy = -290;
    }
    if (!$jumpHeld && $this->vy < -100) {
        $this->vy = -100;    // button released early: shorter jump
    }
}

public function update(float $dt): void
{
    $this->updateGravity($dt);
    $this->state = !$this->onGround ? 'jump' : (abs($this->vx) > 10 ? 'walk' : 'idle');
    $this->pickAnimation();      // picks "<state><Facing>"
    $this->updateSprite($dt);    // next frame
}

public function render(Renderer $r, float $camX = 0, float $camY = 0): void
{
    $this->drawAt($r, $this->x - 1 - $camX, $this->y - $camY);
}
```

In the scene: `$player->control($this->actions()->axis('left', 'right'), $this->actions()->isPressed('jump'),
$this->actions()->isHeld('jump'), $dt)`, then `$player->update($dt)`. Moving against walls and the ground is
the job of the collision system: [tutorial 3](/docs/plates-formes).

This code is the JumPHP skeleton's (`bin/jumphp new`): `src/Entity/Player.php`.
