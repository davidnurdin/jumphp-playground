# The public API

The game directory can be read as JSON, without an account: for another site, an app, a bot…

- **Interactive documentation (OpenAPI)**: [/api/docs](/api/docs), where you can try every call; the raw
  description: [/api/docs.jsonopenapi](/api/docs.jsonopenapi)
- The games: [`GET /api/games`](/api/games)
- A game: `GET /api/games/{slug}`
- The categories: [`GET /api/categories`](/api/categories), a category: `GET /api/categories/{slug}`

The API is read-only and allows calls from any site (CORS). Responses are cached for one minute.

## Games

| Parameter | |
|---|---|
| `category` | a category (its `slug`) |
| `kind` | `original` (original creation), `cpc` (Amstrad CPC remake), `port` (port) |
| `order[rating]`, `order[plays]`, `order[publishedAt]`, `order[title]` | the sort order, `asc` or `desc`; several in a row to break ties. Default: most recent first |
| `page`, `itemsPerPage` | 30 games per page, up to 100 |

```bash
curl -g -H 'Accept: application/json' 'https://<this site>/api/games?category=arcade&order[rating]=desc'
```

Each game:

| Field | |
|---|---|
| `slug`, `title`, `description` | |
| `category`, `kind` | its category (`slug`), its kind of project |
| `author` | its author (GitHub username) |
| `rating`, `ratings` | its average rating out of 5 (`null` with no votes), the number of votes |
| `plays` | the number of games played |
| `engine`, `publishedAt` | the JumPHP engine version, the date of the latest publication |
| `forkOf` | the game it is a fork of, otherwise `null` |
| `url`, `play`, `source` | its page, the game in full screen, its browsable code |
| `image` | its 640 × 360 image (`null` if it has none) |
| `repository`, `manifest` | its GitHub repository, the list of its published files (JumPHP's [remote mode](https://github.com/davidnurdin/jumPhp/blob/main/docs/remote.md)) |

Sorting by rating is the same as on [the games page](/games): the average is pulled towards 3 while a game has
few votes (three phantom votes), so that a single 5 out of 5 does not rank above many 4.5s.

## Pages and JSON-LD

In plain JSON (`Accept: application/json`), a list is an array. In JSON-LD (`Accept: application/ld+json`, the
default format), it also gives the total count (`totalItems`) and the page addresses (`view.next`,
`view.last`):

```json
{
  "@id": "/api/games",
  "totalItems": 42,
  "member": [ { "@id": "/api/games/pong", "slug": "pong", "title": "PONG", "…": "…" } ],
  "view": { "next": "/api/games?page=2", "last": "/api/games?page=2" }
}
```

Errors: `404` for a game or category that does not exist (or is no longer listed), `422` for an invalid
parameter (`kind=zzz`), in Problem Details format (`application/problem+json`).
