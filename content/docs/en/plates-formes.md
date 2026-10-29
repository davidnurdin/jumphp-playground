# Tutorial 3 — A platform game: level, collisions, camera

## A level written as lines

To start with, a level is written directly in the code: one character per 16 × 16 pixel tile.

```php
private const LEVEL = [
    '..........................C.C.C.........',
    '.........................=======........',
    '...P....................................',
    '#########............C......######......',
    '#########..........#####...########.....',
    '########################################',
];

$this->map = ArrayLoader::fromRows(self::LEVEL,
    legend: ['#' => 1, '=' => 2, '/' => 3, '\\' => 4],     // the tiles
    objects: ['P' => 'start', 'C' => 'coin'],                // objects placed in the level
    tileWidth: 16, tileset: 'generated:tiles');
$tileset = $this->map->tilesets[0];
$tileset->masks[1] = CollisionMask::oneWay();                // '=': you can jump through it from below
$tileset->masks[2] = CollisionMask::slope(16, 16, 1, 16);    // '/': a slope
```

When the game grows, draw the levels with [Tiled](https://www.mapeditor.org):
`TiledJsonLoader::load($this->engine->config->path('assets/maps/level1.tmj'))`.

## Collisions

Inject the services in the scene's constructor (they are provided automatically):

```php
public function __construct(
    private TileMapRenderer $tiles,
    private CollisionSystem $collisions,
    private EntityManager $entities,
) {
}
```

Every frame, set the velocity, then **move with the collision system**: it advances pixel by pixel, stops at
walls, lands on the ground, climbs slopes and fills in `$player->onGround`.

```php
$this->player->control($move, $jump, $held, $dt);
$this->player->update($dt);
$this->collisions->move($this->player, $this->map, $dt);
```

Falling out of the level: `$this->collisions->on('fell', fn () => $this->respawn());` (in `enter()`, after
`$this->collisions->removeAllListeners()`).

## Coins

The level's objects become entities:

```php
$this->entities->clear();
$this->entities->register('coin', Coin::class);
$this->entities->spawnFromMap($this->map);

// in update():
$this->entities->update($dt, $this->camera);
foreach ($this->entities->overlapping($this->player->getBoundingBox()) as $entity) {
    if ($entity instanceof Coin) {
        $entity->kill();                    // removed automatically
        $this->coins++;
        $this->sound()->playSfx('coin');
    }
}
```

## The camera

```php
$this->camera = new Camera($this->width(), $this->height());
$this->camera->setBounds(0, 0, $this->map->pixelWidth(), $this->map->pixelHeight());
$this->camera->follow($this->player, 0.15, 0.3);    // a dead zone in the middle of the screen

// update(): $this->camera->update($dt);
// render(): draw everything offset by the camera
$cx = $this->camera->getX();
$cy = $this->camera->getY();
$this->tiles->renderBackground($r, $this->map, $cx, $cy, $this->time);
$this->entities->render($r, $this->camera);
$this->player->render($r, $cx, $cy);
$this->tiles->renderForeground($r, $this->map, $cx, $cy, $this->time);
```

`$this->camera->shake(4, 0.3)` shakes the screen. The complete game is the JumPHP skeleton
(`src/Scene/PlayScene.php`): `bin/jumphp new my-game` gives it to you, ready to modify.
