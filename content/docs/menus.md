# Tutoriel 5 — Menus, options, pause et langues

## Un menu

Toute scène qui hérite de `MenuScene` est un menu : un `$title`, des lignes `items()`.

```php
protected function items(): array
{
    return [
        // [libellé, valeur à droite, sur Entrée, sur gauche / droite]
        ['PLAY', null, fn () => $this->scenes()->switchTo(PlayScene::class, ['level' => 1])],
        ['VOLUME', fn () => round($this->sound()->getSfxVolume() * 100) . '%', null,
            fn (int $dir) => $this->sound()->setSfxVolume($this->sound()->getSfxVolume() + $dir / 10)],
        ['OPTIONS', null, fn () => $this->scenes()->push(OptionsScene::class)],
    ];
}

protected function back(): void
{
    $this->scenes()->pop();    // Échap dans un sous-menu
}
```

`switchTo()` remplace l'écran (avec un fondu), `push()` en ouvre un par-dessus, `pop()` y revient.

## La pause

Une pause est un menu **par-dessus** le jeu : `isOverlay()` renvoie `true`, et le jeu reste dessiné dessous.

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

// dans le jeu :
if ($this->actions()->isPressed('pause')) {
    $this->scenes()->push(PauseScene::class);
    return;
}
```

Avec `pause_scene: App\Scene\PauseScene` dans `game.yaml`, la pause s'ouvre aussi toute seule quand l'onglet
est caché ou que la fenêtre perd le focus.

## Les langues

`config/lang.yaml` traduit les textes (les libellés des menus le sont automatiquement) :

```yaml
fr:
  PLAY: JOUER
  '{n} COINS': '{n} PIECES'
```

Dans le code : `Lang::t('{n} COINS', ['n' => $this->coins])` ; changer de langue :
`$this->engine->lang->setLang('fr')`.
