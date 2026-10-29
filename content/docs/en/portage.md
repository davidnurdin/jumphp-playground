# Porting an existing game

A game whose source code exists (C, Pascal, BASIC, assembly, JavaScript…) is translated into PHP within the
JumPHP structure: its screens become scenes, its objects entities, and its main loop is the engine's.
[Moktar](/moktar) (the port of OpenTitus) and [Sapiens](/sapiens) were made this way.

- Choose the **Porting an existing game** type when creating it, and say in the description where the original
  code is: your Claude will use it.
- Keep the original rules, levels and feel; credit the authors and respect the license.
- Data from the original game that is not free does not go into the public repository: upload it to the
  project's **private files** (Studio → the project). The game loads it like its own files.
