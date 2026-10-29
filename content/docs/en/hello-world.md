# Tutorial 1 — Hello world: a menu, some text, a sound

The smallest JumPHP game: a title screen with a menu (play, sound, quit), then some text that moves with the
arrow keys and a sound on Space. Two files. You can edit it right away in the [sandbox](/bac-a-sable), or create
it on your machine with `bin/jumphp new hello-world`.

## The configuration: `config/game.yaml`

```yaml
game:
  id: hello-world
  title: "HELLO WORLD"
  width: 320                    # the game's resolution, scaled up to the size of the screen
  height: 180
  start_scene: App\Scene\TitleScene
  touch: { a: Space, b: Escape, start: Return }    # the pad buttons on phones
  smoke:                        # what `bin/jumphp test` plays on its own, without a screen
    scene: App\Scene\HelloScene
    params: { demo: true }
```

Keys: the engine already defines `up down left right confirm back pause` (arrow keys, Enter, Escape, the
gamepad, the touch screen). We add an action in `config/input.yaml`:

```yaml
actions:
  jump: [Space, pad0:a, touch:tap]
```

## The title screen: `src/Scene/TitleScene.php`

A menu is written by extending `MenuScene`: you give the title and the lines, the engine takes care of the rest
(drawing, moving with the keyboard / gamepad / finger, menu sounds).

```php
final class TitleScene extends MenuScene
{
    protected string $title = 'HELLO WORLD';

    public function enter(array $params = []): void
    {
        parent::enter($params);
        // sounds made in PHP: no audio file needed
        if (!$this->sound()->hasSfx('menu_move')) {
            $this->sound()->synth('menu_move', Synth::tone(660, 0.04));
            $this->sound()->synth('menu_confirm', Synth::sequence([[660, 0.05], [990, 0.08]]));
        }
    }

    /** The lines: [label, value shown on the right, action on Enter, action on left / right] */
    protected function items(): array
    {
        $items = [
            ['PLAY', null, fn () => $this->scenes()->switchTo(HelloScene::class)],
            ['SOUND', fn () => $this->sound()->isMuted() ? 'OFF' : 'ON', fn () => $this->toggleSound(), fn () => $this->toggleSound()],
        ];
        if (Platform::isDesktop()) {
            $items[] = ['QUIT', null, fn () => $this->engine->quit()];   // no "quit" in the browser
        }
        return $items;
    }

    private function toggleSound(): void
    {
        $this->sound()->setMuted(!$this->sound()->isMuted());
        $this->refresh();    // the menu shows the new value
    }
}
```

The `menu_move` and `menu_confirm` sounds are played automatically by the menu. Labels are translated with
`config/lang.yaml` (`fr: { PLAY: JOUER }`).

## The game: `src/Scene/HelloScene.php`

A scene has three essential methods:

- `enter($params)`: the scene starts. **Reset everything here**: the same scene is used several times;
- `update($dt)`: 60 times per second, you change the game state. `$dt` is the elapsed time in seconds;
- `render($r)`: you draw, and nothing else.

```php
final class HelloScene extends AbstractScene
{
    private float $x = 0.0;
    private float $y = 0.0;

    public function enter(array $params = []): void
    {
        $this->x = $this->width() / 2;
        $this->y = $this->height() / 2;
        if (!$this->sound()->hasSfx('beep')) {
            $this->sound()->synth('beep', Synth::sweep(440, 880, 0.12));   // a rising "beep"
        }
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('back')) {          // Escape: back to the title
            $this->scenes()->switchTo(TitleScene::class);
            return;
        }
        // axis() is -1, 0 or 1: 90 pixels per second
        $this->x += $this->actions()->axis('left', 'right') * 90 * $dt;
        $this->y += $this->actions()->axis('up', 'down') * 90 * $dt;

        if ($this->actions()->isPressed('jump') || $this->actions()->isPressed('confirm')) {
            $this->sound()->playSfx('beep');
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([16, 16, 40]);
        // the built-in font (5 × 7 pixels), scaled 2 times, centered, with a black shadow
        $this->font()->draw($r, 'HELLO WORLD!', $this->x, $this->y, 2, [255, 220, 80], 'center', [0, 0, 0]);
    }
}
```

The real file adds stars that burst out with every sound, text that changes color, and a demo mode for the
tests: read it in [the game's sources](/hello-world/source/src/Scene/HelloScene.php).

## Try it

- In the [sandbox](/bac-a-sable): change the text, the color `[255, 220, 80]`, the speed, the sound
  (`Synth::tone(220, 0.3, 'triangle')`), then **▶ Test**.
- On your machine: `bin/jumphp run hello-world`, `bin/jumphp test hello-world`.

Next: [a character that moves and animates](/docs/personnage).
