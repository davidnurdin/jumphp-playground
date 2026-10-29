# Le bac à sable : essayer sans compte

Le [bac à sable](/bac-a-sable) ouvre le jeu **Hello World** dans l'éditeur en ligne. Rien à installer, aucun
compte : vous changez le code, vous cliquez sur **▶ Tester**, et le jeu tourne dans votre navigateur avec vos
modifications.

1. Ouvrez [le bac à sable](/bac-a-sable).
2. À gauche, ouvrez `src/Scene/HelloScene.php`.
3. Remplacez `'HELLO WORLD!'` par votre texte, ou changez la vitesse (`90 * $dt`).
4. Cliquez sur **▶ Tester** : le jeu se lance à droite. Les flèches déplacent le texte, Espace joue un son.

Vos fichiers sont enregistrés au fil de la frappe, pour ce navigateur seulement, pendant 30 jours. **Tout
recommencer** revient au jeu d'origine.

Vous pouvez partir de **n'importe quel jeu** du site : `/bac-a-sable/<nom-du-jeu>` (par exemple
[/bac-a-sable/pong](/bac-a-sable/pong)).

## Et ensuite ?

Pour garder votre jeu, le publier et le faire grandir : [créez-le avec votre compte GitHub](/studio/nouveau),
puis suivez [le tutoriel hello world](/docs/hello-world) pour comprendre chaque ligne.

Le PHP du bac à sable s'exécute **dans votre navigateur** (WebAssembly), jamais sur le serveur.
