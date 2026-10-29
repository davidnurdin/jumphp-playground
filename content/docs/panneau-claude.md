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
- **Les écrans** : la capture que `bin/jumphp shot` ou `siteshot` vient de faire, et chaque image que Claude ouvre
  pour regarder le jeu, affichées dans le panneau (terminaux kitty, Ghostty, iTerm2 ; ailleurs, le chemin du
  fichier).
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
sa sortie étant aussi copiée dans un fichier temporaire que le panneau suit (`{ commande } 2>&1 | tee …`, le code de
sortie gardé). Les autres commandes ne sont pas touchées. Rien n'est envoyé ailleurs.
