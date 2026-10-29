# Tutorial 5 — Menus, options, pause and languages

## A menu

Any scene that extends `MenuScene` is a menu: a `$title`, and lines from `items()`.

```php
protected function items(): array
{
    return [
        // [label, value on the right, on Enter, on left / right]
        ['PLAY', null, fn () => $this->scenes()->switchTo(PlayScene::class, ['level' => 1])],
        ['VOLUME', fn () => round($this->sound()->getSfxVolume() * 100) . '%', null,
            fn (int $dir) => $this->sound()->setSfxVolume($this->sound()->getSfxVolume() + $dir / 10)],
        ['OPTIONS', null, fn () => $this->scenes()->push(OptionsScene::class)],
    ];
}

protected function back(): void
{
    $this->scenes()->pop();    // Escape in a submenu
}
```

`switchTo()` replaces the screen (with a fade), `push()` opens one on top, `pop()` goes back to it.

## Pause

A pause is a menu **on top of** the game: `isOverlay()` returns `true`, and the game stays drawn underneath.

```php
final class PauseScene extends MenuScene
{
    protected string $title = 'PAUSE';

    public function isOverlay(): bool
    {
        return true;
    }

    protected function items(): array
    {
        return [
            ['RESUME', null, fn () => $this->scenes()->pop()],
            ['TITLE SCREEN', null, fn () => $this->scenes()->switchTo(TitleScene::class)],
        ];
    }
}

// in the game:
if ($this->actions()->isPressed('pause')) {
    $this->scenes()->push(PauseScene::class);
    return;
}
```

With `pause_scene: App\Scene\PauseScene` in `game.yaml`, the pause also opens by itself when the tab is hidden
or the window loses focus.

## Languages

`config/lang.yaml` translates the texts (menu labels are translated automatically):

```yaml
fr:
  PLAY: JOUER
  '{n} COINS': '{n} PIECES'
```

In the code: `Lang::t('{n} COINS', ['n' => $this->coins])`; to change the language:
`$this->engine->lang->setLang('fr')`.
