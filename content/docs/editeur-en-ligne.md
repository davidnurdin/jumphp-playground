# L'éditeur en ligne

Chaque jeu a un bouton **Éditer**. L'éditeur montre les fichiers du jeu publié ; ce que vous changez est gardé
**pour vous seul** (vos brouillons), enregistré au fil de la frappe.

- **▶ Tester** lance le jeu avec vos brouillons, à droite, sans rien publier. Avec **⚡ En direct** (coché par
  défaut), chaque modification enregistrée va dans le jeu **sans le recharger** (rechargement à chaud) :
  - une **image**, un **son**, les **traductions** (`lang.yaml`), les **touches** (`input.yaml`) : remplacés en
    place, le jeu continue là où vous êtes ;
  - un fichier de **données** (un niveau, un `.json`…) : la scène en cours repart, avec ses paramètres ;
  - du **code PHP** : PHP redémarre dans le même moteur, en moins d'une seconde, **directement dans la scène où
    vous étiez** (pas de retour à l'écran titre). Une erreur de syntaxe s'affiche à droite ; corrigez-la, le jeu
    repart tout seul.

  Une scène peut aussi garder son état d'une version à l'autre (la salle, le score, les vies…) : voir
  [plus bas](#garder-létat-dune-scène).
- **Console** (sous l'aperçu, fermée par défaut ; un compteur dit combien de lignes sont arrivées) : ce que le
  PHP du jeu écrit, `echo`, `var_dump()`, `print_r()`, les avertissements (en rouge). Un `phpinfo()` s'y affiche
  comme une page, avec ses tableaux.
- **Annuler mes modifs** revient à la version publiée d'un fichier.
- **+ Fichier** ajoute un fichier (une nouvelle scène, un ennemi…).
- **Envoyer sur GitHub** (vos jeux) : vos brouillons deviennent un commit de votre dépôt ; le jeu est republié
  dans la minute.
- **Forker pour garder** (le jeu d'un autre) : une copie du jeu dans votre compte GitHub, publiée sous un autre
  nom ; vos brouillons y sont transférés.

Le code tourne dans le navigateur : vous pouvez tout essayer sans risque. Sans compte, le
[bac à sable](/docs/bac-a-sable) fait la même chose (sans l'envoi sur GitHub).

Les **sources** de chaque jeu (bouton *Sources*) se lisent dossier par dossier, sans compte.

## Garder l'état d'une scène

Quand le code change, PHP repart de zéro : la scène où vous étiez est rouverte avec les mêmes paramètres, mais
ses variables repartent de leurs valeurs de départ. Pour garder ce qui compte pendant que vous réglez le jeu,
la scène implémente `HotReloadable` : elle dit ce qu'elle garde, et le reprend.

```php
use JumPHP\Scene\HotReloadable;

final class PlayScene extends AbstractScene implements HotReloadable
{
    public function hotState(): array
    {
        return ['room' => $this->room, 'score' => $this->score, 'lives' => $this->lives];
    }

    public function hotRestore(array $state): void   // appelée après enter()
    {
        ['room' => $this->room, 'score' => $this->score, 'lives' => $this->lives] = $state;
    }
}
```

Seulement des valeurs simples (nombres, textes, tableaux) : elles passent d'une version du code à l'autre. Sans
`HotReloadable`, rien ne change pour le jeu ; ça ne sert qu'au rechargement à chaud (l'éditeur, le bac à sable).
