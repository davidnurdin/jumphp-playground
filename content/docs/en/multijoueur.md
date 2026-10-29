# Tutorial 7 — Online multiplayer

JumPHP connects players through a relay server (WebSocket): the same online code on the desktop and in the
browser. The [Pong](/pong) game is the complete example (`src/Scene/PlayScene.php`).

## The lobby

```php
$this->scenes()->switchTo(LobbyScene::class, [
    'next' => PlayScene::class,             // the game scene, once the players are gathered
    'nextParams' => ['mode' => 'online'],
    'back' => TitleScene::class,
    'players' => 2,
]);
```

The lobby creates a room (a 4-letter code to share), joins one, or finds an opponent. Then it opens the game
scene with `['online' => true, 'net' => NetSession, 'host' => bool, 'peer' => int]`.

## Messages

```php
$net = $params['net'];
$net->removeAllListeners();
$net->on('message', fn (int $from, int $type, string $payload) => $this->receive($from, $type, $payload));
$net->on('peerLeave', fn (int $peer) => $this->scenes()->switchTo(TitleScene::class));

$net->send(self::MSG_STATE, pack('gg', $this->x, $this->y), reliable: false);   // frequent: may get lost
$net->sendJson(self::MSG_EVENT, ['goal' => 1]);                                   // rare: always arrives
```

## The right setup

- **The host decides**: it simulates the match, keeps the score, and sends the state ~20 times per second;
- the others send their inputs ~30 times per second;
- the other players' objects are drawn with a small smoothed delay: `SnapshotBuffer`
  (`push($this->engine->time(), $state)` then `sample($this->engine->time())`).

Run the relay server on your machine with `bin/jumphp relay up`; the game sets its own with `net_url` in
`game.yaml` (or `?net=wss://…` in the page address).
