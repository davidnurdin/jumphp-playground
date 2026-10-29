# Le panneau JumPHP de Claude Code

Pendant que Claude écrit votre jeu, **voyez ce qu'il fait** : un panneau à côté de la conversation montre les
tests qui tournent, les écrans du jeu qu'il regarde, et vous permet d'intervenir. C'est un *mod* de Claude Code,
`jumphp-live`, fourni avec [JumPHP](https://github.com/davidnurdin/jumPhp/tree/main/claude-mod/jumphp-live).

## L'installer

Dans Claude Code (dans un terminal), tapez :

```
/plugin install jumphp-live --marketplace davidnurdin/jumPhp
```

Répondez `y` pour ajouter la source, puis choisissez la portée (« user » : pour tous vos projets). Depuis un
shell : `claude plugin install jumphp-live --marketplace davidnurdin/jumPhp`.

## S'en servir

Dans votre projet JumPHP (le dossier qui contient `bin/jumphp`), lancez Claude Code et tapez `/jumphp <jeu>`, par
exemple `/jumphp pong`. Le panneau s'ouvre à droite (il faut une fenêtre assez large, 110 colonnes ou plus). Il
s'ouvre aussi tout seul la première fois que Claude lance `bin/jumphp test` ou `bin/jumphp shot`.

Ce qu'il montre :

- **Les vérifications en direct** : quand Claude lance `bin/jumphp test`, la sortie défile dans le panneau et
  chaque étape est cochée au fur et à mesure : `✔ php -l   ✔ phpstan   … phpunit   smoke test`. À la fin : le
  résumé de PHPUnit et ce qui a échoué (les tests en échec, les avertissements et erreurs PHP).
- **L'écran du jeu en direct** (`● live`) : pendant que `bin/jumphp test` (son smoke test) ou `bin/jumphp shot`
  fait tourner le jeu, le panneau montre son écran au fil des frames (le moteur réécrit `var/live/frame.png`,
  option `JUMPHP_LIVE`). Les runs sans fenêtre vont plus vite que le temps réel : `JUMPHP_REALTIME=1` les ralentit
  à la vitesse du jeu. Un projet créé avant cette option la récupère avec `git pull jumphp main` (le dépôt JumPHP
  d'origine, gardé sous le nom `jumphp`).
- **Les tests de jeu, sous vos yeux** : les [tests qui jouent le jeu](/docs/tests) s'affichent pendant qu'ils
  tournent (⏳, puis ✔ / ✘), avec le nom du test en cours au-dessus de l'écran live ; ensuite, cliquez sur un test
  pour voir sa dernière image. Le bouton **🐢 Game speed / ⏩ Fast** (`r`) choisit la vitesse : celle du jeu (par
  défaut, pour voir la raquette bouger) ou le plus vite possible.
- **Les écrans** : la capture que `bin/jumphp shot` ou `siteshot` vient de faire, et chaque image que Claude ouvre
  pour regarder le jeu, affichées dans le panneau.
- **Les images dans n'importe quel terminal** : par défaut elles sont dessinées en petits blocs de couleur
  (`▦ Blocks`), que tout terminal en couleurs vraies affiche (GNOME Terminal, Konsole, Windows Terminal, VS Code…) :
  le moteur écrit pour cela, à côté de chaque PNG, une petite copie brute (`.rgb`). Dans kitty, Ghostty ou iTerm2
  le panneau affiche le PNG lui-même (`🖼 HD`) ; la touche `p` bascule. Le bouton **🖼 Open** (`o`) ouvre l'image
  affichée dans la visionneuse de votre bureau (`xdg-open`, `open` sur macOS), en taille réelle.
- **Le playground** : le résultat des [tests PHPUnit du site](/docs/tests) (`run_tests`, `get_test_run`) et des
  publications (`sync_project`) quand Claude utilise le serveur MCP.

Ce que vous pouvez faire :

- **▶ Tests** (touche `t`) et **📸 Shot** (`s`) : lancer les tests ou une capture tout de suite, sans Claude ; la
  sortie défile dans le panneau.
- **🛠 Ask Claude to fix** (`f`), après un échec : Claude reçoit ce qui a échoué et corrige.
- **Tell Claude** : un champ pour lui écrire (« saute plus haut », « le boss est trop dur ») ; il le lit dès qu'il
  a fini l'étape en cours.

## Comment il suit Claude

Une commande Bash de Claude qui lance `bin/jumphp test`, `shot`, `siteshot` ou `webshot` est exécutée telle quelle,
sa sortie étant aussi copiée dans un fichier temporaire que le panneau suit (`{ export JUMPHP_LIVE=1; commande } 2>&1 | tee …`, le code de
sortie gardé). Les autres commandes ne sont pas touchées. Rien n'est envoyé ailleurs.
