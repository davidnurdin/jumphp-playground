# Tutorial 8 — The game's home page and publishing

## The home page: `site/`

A `games/<id>/site/` folder (an `index.html` page, images) becomes the game's page on this site, and the root
of your domain if you deploy it yourself (the game being in `play/`). Examples:
[Rainbow Islands](/rainbow-island), [Sapiens](/sapiens).

- in the game's colors, with its real images: screenshots (`bin/jumphp shot`), its sprites, its tiles;
- a big link to the game: `href="play/"`;
- SEO: `<title>`, `<meta name="description">`, Open Graph (a 1200 × 630 `og:image`), an `<h1>`, `alt` on
  images, `VideoGame` structured data;
- absolute addresses are written `https://{{DOMAIN}}/…`: the site replaces them with the real address;
- readable on phones (390 pixels wide, no horizontal scrolling).

Check it with `bin/jumphp build:site <id>` then `bin/jumphp siteshot <id>`.

## The description, in French and in English

The site exists in French and in English: it shows the game's description in the visitor's language. Write it
in both languages in `config/game.yaml`:

```yaml
game:
  description: "A brick breaker: 3 lives, a new wall every level."
  descriptions:
    fr: "Un casse-briques : 3 vies, un nouveau mur à chaque niveau."
    en: "A brick breaker: 3 lives, a new wall every level."
```

Without a translation in the visitor's language, the project's description is shown.

## The directory image

`assets/thumbnail.png` (16:9). Otherwise the site uses the home page's `og:image`, otherwise the game's icon,
and failing that it **photographs the game** itself in a browser.

## What the site adds to every game

- **Install**: every game installs like an app (on phones and computers), with its icon (`assets/icon.png`);
- **Share**: the phone's share sheet, or social networks on computers;
- players' **ratings** (1 to 5 stars, no account) and the play counter;
- the **sources** and the online **editor**;
- players' **comments**, **idea box** and reported **issues**, with or without an account. Anonymous messages
  only show once you approve them. The **bell 🔔** at the top of the site counts the new messages on your games
  and those waiting for your approval; its page ([Notifications](/studio/notifications)) lists them all, with
  **✓ Approve**, **Mark resolved** (for an issue) and **Delete** buttons.

## Publishing

Every push to your repository's main branch is published within a minute. In the Studio, the project page shows
the publishing status, the errors, and lets you hide the game from the directory.
