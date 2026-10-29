# Tutorial 4 — Sound and music

`$this->sound()` (the `SoundManager`) plays effects and music, on the desktop as in the browser. Without a
sound card, everything is silent, never an error.

## Sounds made in PHP

`Synth` creates retro sounds without any file:

```php
use JumPHP\Audio\Synth;

if (!$this->sound()->hasSfx('jump')) {                        // only once
    $this->sound()->synth('jump', Synth::sweep(250, 600, 0.12));               // a rising frequency
    $this->sound()->synth('coin', Synth::sequence([[988, 0.05], [1319, 0.12]])); // two notes
    $this->sound()->synth('hit', Synth::noise(0.15));                          // a noise
    $this->sound()->synth('bass', Synth::tone(110, 0.3, 'triangle'));          // square, triangle, sine, saw
}
$this->sound()->playSfx('jump');
```

## Files

```php
$this->sound()->loadSfx('explosion', 'assets/sfx/explosion.wav');     // wav, ogg, mp3
$this->sound()->loadMusic('level', 'assets/music/level.ogg');
$this->sound()->playMusic('level');
$this->sound()->stopMusic();
```

## Volume

`setSfxVolume(0.8)`, `setMusicVolume(0.5)`, `setMuted(true)`: these settings are saved with the player's
options. An options menu turns them into lines `['VOLUME', fn () => ..., null, fn (int $dir) => ...]` (see
[menus](/docs/menus)).

3D sound (positioned in space) exists too: `$this->engine->audio3d()`.
