# Tutoriel 6 — Sauvegarder les scores et les options

Les fichiers du joueur vont dans le dossier `var/` du jeu sur le bureau, et dans le stockage du navigateur
(IndexedDB) sur le web : le même code.

```php
// un meilleur score
$best = $this->engine->storage->readJson('scores.json', ['best' => 0]);
if ($this->score > $best['best']) {
    $this->engine->storage->writeJson('scores.json', ['best' => $this->score]);
}

// des réglages (enregistrés tout de suite)
$difficulty = $this->engine->options->get('difficulty', 'normal');
$this->engine->options->set('difficulty', 'hard');
```

Au moment où l'on quitte la page ou ferme la fenêtre, l'évènement `close` laisse sauver l'état :

```php
$this->engine->lifecycle()->on('close', fn () => $this->engine->storage->writeJson('partie.json', $this->state()));
```

Ne lisez jamais de fichier du joueur au moment du build : le dossier `var/` n'est pas publié.
