# The sandbox: try it without an account

The [sandbox](/bac-a-sable) opens the **Hello World** game in the online editor. Nothing to install, no
account: you change the code, click **▶ Test**, and the game runs in your browser with your changes.

1. Open [the sandbox](/bac-a-sable).
2. On the left, open `src/Scene/HelloScene.php`.
3. Replace `'HELLO WORLD!'` with your own text, or change the speed (`90 * $dt`).
4. Click **▶ Test**: the game starts on the right. The arrow keys move the text, Space plays a sound.

Your files are saved as you type, for this browser only, for 30 days. **Start over** goes back to the original
game.

You can start from **any game** on the site: `/bac-a-sable/<game-name>` (for example
[/bac-a-sable/pong](/bac-a-sable/pong)).

## Share by a link

The editor's **🔗 Share** button makes a link to **your version of the game, as it is at that moment** (a
snapshot: your later changes are not in it). Choose how long it works: **1 day, 1 week, 1 month or no limit**,
and a title if you like. Send the link to whoever you want:

- they **play your demo** right away, without an account;
- **✏️ Continue in my sandbox**: they pick up your work in progress in their own sandbox and change it in turn
  (they can share it again);
- **📥 Import into my space**: signed in with GitHub, they get it into their private space.

The links you make while signed in are listed in the [Studio](/studio) (“My shared links”: views, end date, a
button to remove them). An expired link is gone with its files.

## Your sandbox into your space

Started without an account? Sign in with GitHub then click **📥 Import into my space** (in the sandbox's bar, or
on a shared link): your changed files become **your private drafts** of that game, in the
[online editor](/docs/editeur-en-ligne). The Studio lists them (“In progress in the editor”). From there:

- **Fork to keep**: a copy of the game in your GitHub account, published on the playground, with your changes;
- or, if the game is yours, **Send to GitHub** (a commit).

## What next?

To keep your game, publish it and make it grow: [create it with your GitHub account](/studio/nouveau), then
follow [the hello world tutorial](/docs/hello-world) to understand every line.

The sandbox's PHP runs **in your browser** (WebAssembly), never on the server.
