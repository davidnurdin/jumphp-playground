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

## Partager par un lien

Le bouton **🔗 Partager** de l'éditeur fait un lien vers **votre version du jeu, telle qu'elle est à ce moment**
(un instantané : vos changements suivants n'y sont pas). Choisissez combien de temps il reste valable :
**1 jour, 1 semaine, 1 mois ou sans limite**, et éventuellement un titre. Envoyez le lien à qui vous voulez :

- il **joue à votre démo** tout de suite, sans compte ;
- **✏️ Continuer dans mon bac à sable** : il reprend votre projet en cours dans son propre bac à sable, et le
  modifie à son tour (il peut le repartager) ;
- **📥 Importer dans mon espace** : connecté avec GitHub, il le récupère dans son espace privé.

Les liens que vous créez en étant connecté sont listés dans le [Studio](/studio) (« Mes liens partagés » :
nombre de vues, date de fin, bouton pour les supprimer). Un lien expiré disparaît avec ses fichiers.

## Récupérer son bac à sable dans son espace

Vous avez commencé sans compte ? Connectez-vous avec GitHub puis cliquez sur **📥 Importer dans mon espace**
(dans la barre du bac à sable, ou sur un lien partagé) : vos fichiers modifiés deviennent **vos brouillons
privés** de ce jeu, dans l'[éditeur en ligne](/docs/editeur-en-ligne). Le Studio les liste (« En cours dans
l'éditeur »). De là :

- **Forker pour garder** : une copie du jeu dans votre compte GitHub, publiée sur le playground, avec vos
  changements ;
- ou, si le jeu est à vous, **Envoyer sur GitHub** (un commit).

## Et ensuite ?

Pour garder votre jeu, le publier et le faire grandir : [créez-le avec votre compte GitHub](/studio/nouveau),
puis suivez [le tutoriel hello world](/docs/hello-world) pour comprendre chaque ligne.

Le PHP du bac à sable s'exécute **dans votre navigateur** (WebAssembly), jamais sur le serveur.
