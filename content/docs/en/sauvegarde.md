# Tutorial 6 — Saving scores and options

The player's files go into the game's `var/` folder on the desktop, and into the browser's storage (IndexedDB)
on the web: the same code.

```php
// a high score
$best = $this->engine->storage->readJson('scores.json', ['best' => 0]);
if ($this->score > $best['best']) {
    $this->engine->storage->writeJson('scores.json', ['best' => $this->score]);
}

// settings (saved immediately)
$difficulty = $this->engine->options->get('difficulty', 'normal');
$this->engine->options->set('difficulty', 'hard');
```

When the player leaves the page or closes the window, the `close` event lets you save the state:

```php
$this->engine->lifecycle()->on('close', fn () => $this->engine->storage->writeJson('game.json', $this->state()));
```

Never read a player file at build time: the `var/` folder is not published.
