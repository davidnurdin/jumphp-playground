# Tutoriel 4 — Sons et musique

`$this->sound()` (le `SoundManager`) joue les effets et la musique, sur le bureau comme dans le navigateur.
Sans carte son, tout est silencieux, jamais une erreur.

## Des sons fabriqués en PHP

`Synth` crée des sons rétro sans aucun fichier :

```php
use JumPHP\Audio\Synth;

if (!$this->sound()->hasSfx('jump')) {                        // une seule fois
    $this->sound()->synth('jump', Synth::sweep(250, 600, 0.12));               // une fréquence qui monte
    $this->sound()->synth('coin', Synth::sequence([[988, 0.05], [1319, 0.12]])); // deux notes
    $this->sound()->synth('hit', Synth::noise(0.15));                          // un bruit
    $this->sound()->synth('bass', Synth::tone(110, 0.3, 'triangle'));          // square, triangle, sine, saw
}
$this->sound()->playSfx('jump');
```

## Des fichiers

```php
$this->sound()->loadSfx('explosion', 'assets/sfx/explosion.wav');     // wav, ogg, mp3
$this->sound()->loadMusic('level', 'assets/music/level.ogg');
$this->sound()->playMusic('level');
$this->sound()->stopMusic();
```

## Le volume

`setSfxVolume(0.8)`, `setMusicVolume(0.5)`, `setMuted(true)` : ces réglages sont enregistrés avec les
options du joueur. Un menu d'options en fait des lignes `['VOLUME', fn () => ..., null, fn (int $dir) => ...]`
(voir [les menus](/docs/menus)).

Le son en 3D (positionné dans l'espace) existe aussi : `$this->engine->audio3d()`.
