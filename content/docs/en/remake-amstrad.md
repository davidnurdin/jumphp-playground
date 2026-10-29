# Remaking an Amstrad CPC game

Remake a CPC game **with its original graphics, music, sounds, levels and rules**, extracted from its disk.
That is how the [Rainbow Islands remake](/rainbow-island) was made.

The tool is [dsk2jumphp](https://github.com/davidnurdin/dsk2jumphp): a headless CPC 6128 emulator that runs
the game and records everything (screenshots, memory, watched writes, AY chip sound, keys), the DSK / Z80 /
sound tools, and the step-by-step method.

## With your Claude

1. [New game](/studio/nouveau), type **Amstrad CPC game remake**. In the description: the game, and the path
   of its disk on your computer (`~/games/my-game.dsk`).
2. Your Claude (see [With your Claude](/docs/avec-claude)) reads the project's `BRIEF.md`: it clones
   dsk2jumphp **next to** the project, installs its Claude skill, and follows its method:
   - it runs the game in the emulator and looks at the screenshots;
   - it finds the variables by comparing memory dumps, and reads the code only around the addresses the logs
     point to;
   - it extracts the palette, tiles, sprites, font, levels and music;
   - it keeps the extraction reproducible in `games/<id>/tools/extract/`;
   - it writes the game in readable PHP, with the original screen size, rules and timing (50 Hz), and compares
     it frame by frame with the emulator.
3. **The disk, the CPC ROMs and the memory dumps are never published.** If the extracted images and sounds must
   not be public either, they stay out of the repository and go into the project's **private files** (Studio).

## By hand

```bash
git clone https://github.com/davidnurdin/dsk2jumphp.git ~/dsk2jumphp && make -C ~/dsk2jumphp test
```

Then read its `AGENTS.md` and `docs/method.md`, and the complete example `examples/rainbow-islands/`.
