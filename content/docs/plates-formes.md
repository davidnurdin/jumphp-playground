# Tutoriel 3 — Un jeu de plates-formes : niveau, collisions, caméra

## Un niveau écrit en lignes

Pour commencer, un niveau s'écrit directement dans le code : un caractère par tuile de 16 × 16 pixels.

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
    legend: ['#' => 1, '=' => 2, '/' => 3, '\\' => 4],     // les tuiles
    objects: ['P' => 'start', 'C' => 'coin'],                // des objets placés dans le niveau
    tileWidth: 16, tileset: 'generated:tiles');
$tileset = $this->map->tilesets[0];
$tileset->masks[1] = CollisionMask::oneWay();                // '=' : on saute à travers par dessous
$tileset->masks[2] = CollisionMask::slope(16, 16, 1, 16);    // '/' : une pente
```

Quand le jeu grandit, dessinez les niveaux avec [Tiled](https://www.mapeditor.org) :
`TiledJsonLoader::load($this->engine->config->path('assets/maps/niveau1.tmj'))`.

## Les collisions

Injectez les services dans le constructeur de la scène (ils sont fournis automatiquement) :

```php
public function __construct(
    private TileMapRenderer $tiles,
    private CollisionSystem $collisions,
    private EntityManager $entities,
) {
}
```

Chaque image, on règle la vitesse puis on **déplace avec le système de collisions** : il avance pixel par
pixel, s'arrête aux murs, se pose sur le sol, monte les pentes et remplit `$player->onGround`.

```php
$this->player->control($move, $jump, $held, $dt);
$this->player->update($dt);
$this->collisions->move($this->player, $this->map, $dt);
```

Tomber hors du niveau : `$this->collisions->on('fell', fn () => $this->respawn());` (dans `enter()`, après
`$this->collisions->removeAllListeners()`).

## Les pièces

Les objets du niveau deviennent des entités :

```php
$this->entities->clear();
$this->entities->register('coin', Coin::class);
$this->entities->spawnFromMap($this->map);

// dans update() :
$this->entities->update($dt, $this->camera);
foreach ($this->entities->overlapping($this->player->getBoundingBox()) as $entity) {
    if ($entity instanceof Coin) {
        $entity->kill();                    // retirée automatiquement
        $this->coins++;
        $this->sound()->playSfx('coin');
    }
}
```

## La caméra

```php
$this->camera = new Camera($this->width(), $this->height());
$this->camera->setBounds(0, 0, $this->map->pixelWidth(), $this->map->pixelHeight());
$this->camera->follow($this->player, 0.15, 0.3);    // une zone morte au centre de l'écran

// update() : $this->camera->update($dt);
// render() : on dessine tout décalé de la caméra
$cx = $this->camera->getX();
$cy = $this->camera->getY();
$this->tiles->renderBackground($r, $this->map, $cx, $cy, $this->time);
$this->entities->render($r, $this->camera);
$this->player->render($r, $cx, $cy);
$this->tiles->renderForeground($r, $this->map, $cx, $cy, $this->time);
```

`$this->camera->shake(4, 0.3)` fait trembler l'écran. Le jeu complet est le squelette de JumPHP
(`src/Scene/PlayScene.php`) : `bin/jumphp new mon-jeu` vous le donne, prêt à modifier.
