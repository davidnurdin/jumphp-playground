# Le remake d'un jeu Amstrad CPC

Refaire un jeu CPC **avec ses graphismes, sa musique, ses sons, ses niveaux et ses règles d'origine**, extraits
de sa disquette. C'est ce qu'a fait le [remake de Rainbow Islands](/rainbow-island).

L'outil est [dsk2jumphp](https://github.com/davidnurdin/dsk2jumphp) : un émulateur CPC 6128 sans écran qui fait
tourner le jeu et note tout (captures, mémoire, écritures surveillées, son de la puce AY, touches), les outils
DSK / Z80 / son, et la méthode pas à pas.

## Avec votre Claude

1. [Nouveau jeu](/studio/nouveau), type **Remake d'un jeu Amstrad CPC**. Dans la description : le jeu, et le
   chemin de sa disquette sur votre ordinateur (`~/jeux/mon-jeu.dsk`).
2. Votre Claude (voir [Avec votre Claude](/docs/avec-claude)) lit le `BRIEF.md` du projet : il clone
   dsk2jumphp **à côté** du projet, installe sa compétence (skill) Claude, et suit sa méthode :
   - il lance le jeu dans l'émulateur et regarde les captures ;
   - il trouve les variables en comparant des copies de la mémoire, et lit le code seulement autour des
     adresses que les journaux donnent ;
   - il extrait la palette, les tuiles, les sprites, la police, les niveaux et la musique ;
   - il garde l'extraction reproductible dans `games/<id>/tools/extract/` ;
   - il écrit le jeu en PHP lisible, avec la taille d'écran, les règles et les cadences d'origine (50 Hz), et le
     compare image par image à l'émulateur.
3. **La disquette, les ROM du CPC et les copies de mémoire ne sont jamais publiées.** Si les images et les sons
   extraits ne doivent pas être publics non plus, ils restent hors du dépôt et vont dans les **fichiers
   privés** du projet (Studio).

## À la main

```bash
git clone https://github.com/davidnurdin/dsk2jumphp.git ~/dsk2jumphp && make -C ~/dsk2jumphp test
```

Puis lisez son `AGENTS.md` et `docs/method.md`, et l'exemple complet `examples/rainbow-islands/`.
