# The online editor

Every game has an **Edit** button. The editor shows the files of the published game; what you change is kept
**for you only** (your drafts), saved as you type.

- **▶ Test** runs the game with your drafts, on the right, without publishing anything. With **⚡ Live** (on by
  default), each change saved goes into the game **without reloading it** (hot reload):
  - a **picture**, a **sound**, the **translations** (`lang.yaml`), the **keys** (`input.yaml`): replaced in place,
    the game goes on where you are;
  - a **data** file (a level, a `.json`…): the current scene starts again, with its parameters;
  - **PHP code**: PHP restarts in the same engine, in less than a second, **right in the scene you were in** (no
    going back to the title screen). A syntax error shows on the right; fix it, the game starts again by itself.

  A scene can also keep its state from one version to the next (the room, the score, the lives…): see
  [below](#keeping-a-scenes-state).
- **Console** (under the preview, closed by default; a counter says how many lines came): what the game's PHP
  writes, `echo`, `var_dump()`, `print_r()`, warnings (in red). A `phpinfo()` shows there as a page, with its
  tables.
- **Undo my changes** goes back to the published version of a file.
- **+ File** adds a file (a new scene, an enemy…).
- **Send to GitHub** (your games): your drafts become a commit in your repository; the game is republished
  within a minute.
- **Fork to keep** (someone else's game): a copy of the game in your GitHub account, published under another
  name; your drafts are carried over to it.

The code runs in the browser: you can try anything without risk. Without an account, the
[sandbox](/docs/bac-a-sable) does the same thing (without sending to GitHub).

The **sources** of every game (*Sources* button) can be browsed folder by folder, without an account.

## Keeping a scene's state

When the code changes, PHP starts from scratch: the scene you were in is opened again with the same parameters,
but its variables are back to their starting values. To keep what matters while you tune the game, the scene
implements `HotReloadable`: it says what it keeps, and takes it back.

```php
use JumPHP\Scene\HotReloadable;

final class PlayScene extends AbstractScene implements HotReloadable
{
    public function hotState(): array
    {
        return ['room' => $this->room, 'score' => $this->score, 'lives' => $this->lives];
    }

    public function hotRestore(array $state): void   // called after enter()
    {
        ['room' => $this->room, 'score' => $this->score, 'lives' => $this->lives] = $state;
    }
}
```

Plain values only (numbers, strings, arrays): they go from one version of the code to the next. Without
`HotReloadable` nothing changes for the game; it only matters to hot reload (the editor, the sandbox).
