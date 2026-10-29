# Tutoriel 1 — Hello world : un menu, un texte, un son

Le plus petit jeu JumPHP : un écran titre avec un menu (jouer, son, quitter), puis un texte qui se déplace avec
les flèches et un son sur Espace. Deux fichiers. Vous pouvez le modifier tout de suite dans le
[bac à sable](/bac-a-sable), ou le créer chez vous avec `bin/jumphp new hello-world`.

## La configuration : `config/game.yaml`

```yaml
game:
  id: hello-world
  title: "HELLO WORLD"
  width: 320                    # la résolution du jeu, agrandie à la taille de l'écran
  height: 180
  start_scene: App\Scene\TitleScene
  touch: { a: Space, b: Escape, start: Return }    # les boutons du pad sur téléphone
  smoke:                        # ce que `bin/jumphp test` joue tout seul, sans écran
    scene: App\Scene\HelloScene
    params: { demo: true }
```

Les touches : le moteur définit déjà `up down left right confirm back pause` (flèches, Entrée, Échap, la
manette, l'écran tactile). On ajoute une action dans `config/input.yaml` :

```yaml
actions:
  jump: [Space, pad0:a, touch:tap]
```

## L'écran titre : `src/Scene/TitleScene.php`

Un menu s'écrit en héritant de `MenuScene` : on donne le titre et les lignes, le moteur s'occupe du reste
(dessin, déplacement au clavier / à la manette / au doigt, sons du menu).

```php
final class TitleScene extends MenuScene
{
    protected string $title = 'HELLO WORLD';

    public function enter(array $params = []): void
    {
        parent::enter($params);
        // des sons fabriqués en PHP : aucun fichier audio nécessaire
        if (!$this->sound()->hasSfx('menu_move')) {
            $this->sound()->synth('menu_move', Synth::tone(660, 0.04));
            $this->sound()->synth('menu_confirm', Synth::sequence([[660, 0.05], [990, 0.08]]));
        }
    }

    /** Les lignes : [libellé, valeur affichée à droite, action sur Entrée, action sur gauche / droite] */
    protected function items(): array
    {
        $items = [
            ['PLAY', null, fn () => $this->scenes()->switchTo(HelloScene::class)],
            ['SOUND', fn () => $this->sound()->isMuted() ? 'OFF' : 'ON', fn () => $this->toggleSound(), fn () => $this->toggleSound()],
        ];
        if (Platform::isDesktop()) {
            $items[] = ['QUIT', null, fn () => $this->engine->quit()];   // pas de « quitter » dans le navigateur
        }
        return $items;
    }

    private function toggleSound(): void
    {
        $this->sound()->setMuted(!$this->sound()->isMuted());
        $this->refresh();    // le menu réaffiche la nouvelle valeur
    }
}
```

Les sons `menu_move` et `menu_confirm` sont joués automatiquement par le menu. Les libellés sont traduits
avec `config/lang.yaml` (`fr: { PLAY: JOUER }`).

## Le jeu : `src/Scene/HelloScene.php`

Une scène a trois méthodes essentielles :

- `enter($params)` : la scène commence. **Remettez tout à zéro ici** : la même scène sert plusieurs fois ;
- `update($dt)` : 60 fois par seconde, on change l'état du jeu. `$dt` est le temps écoulé en secondes ;
- `render($r)` : on dessine, et rien d'autre.

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
            $this->sound()->synth('beep', Synth::sweep(440, 880, 0.12));   // un « bip » qui monte
        }
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('back')) {          // Échap : retour au titre
            $this->scenes()->switchTo(TitleScene::class);
            return;
        }
        // axis() vaut -1, 0 ou 1 : 90 pixels par seconde
        $this->x += $this->actions()->axis('left', 'right') * 90 * $dt;
        $this->y += $this->actions()->axis('up', 'down') * 90 * $dt;

        if ($this->actions()->isPressed('jump') || $this->actions()->isPressed('confirm')) {
            $this->sound()->playSfx('beep');
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([16, 16, 40]);
        // la police intégrée (5 × 7 pixels), agrandie 2 fois, centrée, avec une ombre noire
        $this->font()->draw($r, 'HELLO WORLD!', $this->x, $this->y, 2, [255, 220, 80], 'center', [0, 0, 0]);
    }
}
```

Le vrai fichier ajoute des étoiles qui jaillissent à chaque son, un texte qui change de couleur, et un mode
démo pour les tests : lisez-le dans [les sources du jeu](/hello-world/source/src/Scene/HelloScene.php).

## Essayer

- Dans le [bac à sable](/bac-a-sable) : changez le texte, la couleur `[255, 220, 80]`, la vitesse, le son
  (`Synth::tone(220, 0.3, 'triangle')`), puis **▶ Tester**.
- Chez vous : `bin/jumphp run hello-world`, `bin/jumphp test hello-world`.

Suite : [un personnage qui bouge et s'anime](/docs/personnage).
