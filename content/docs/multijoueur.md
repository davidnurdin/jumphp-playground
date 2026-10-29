# Tutoriel 7 — Le multijoueur en ligne

JumPHP relie les joueurs par un serveur relais (WebSocket) : le même code en ligne sur le bureau et dans le
navigateur. Le jeu [Pong](/pong) en est l'exemple complet (`src/Scene/PlayScene.php`).

## Le salon

```php
$this->scenes()->switchTo(LobbyScene::class, [
    'next' => PlayScene::class,             // la scène du jeu, une fois les joueurs réunis
    'nextParams' => ['mode' => 'online'],
    'back' => TitleScene::class,
    'players' => 2,
]);
```

Le salon crée une salle (un code de 4 lettres à partager), en rejoint une, ou trouve un adversaire. Puis il
ouvre la scène du jeu avec `['online' => true, 'net' => NetSession, 'host' => bool, 'peer' => int]`.

## Les messages

```php
$net = $params['net'];
$net->removeAllListeners();
$net->on('message', fn (int $from, int $type, string $payload) => $this->receive($from, $type, $payload));
$net->on('peerLeave', fn (int $peer) => $this->scenes()->switchTo(TitleScene::class));

$net->send(self::MSG_STATE, pack('gg', $this->x, $this->y), reliable: false);   // souvent : peut se perdre
$net->sendJson(self::MSG_EVENT, ['goal' => 1]);                                   // rare : arrive toujours
```

## La bonne organisation

- **L'hôte décide** : il simule la partie, compte les points, et envoie l'état ~20 fois par seconde ;
- les autres envoient leurs commandes ~30 fois par seconde ;
- les objets des autres se dessinent avec un petit retard lissé : `SnapshotBuffer`
  (`push($this->engine->time(), $state)` puis `sample($this->engine->time())`).

Le serveur relais se lance chez vous avec `bin/jumphp relay up` ; le jeu indique le sien avec `net_url` dans
`game.yaml` (ou `?net=wss://…` dans l'adresse de la page).
