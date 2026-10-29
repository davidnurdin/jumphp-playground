# L'API publique

L'annuaire des jeux se lit en JSON, sans compte : pour un autre site, une application, un bot…

- **Documentation interactive (OpenAPI)** : [/api/docs](/api/docs), on peut y essayer chaque appel ; la
  description brute : [/api/docs.jsonopenapi](/api/docs.jsonopenapi)
- Les jeux : [`GET /api/games`](/api/games)
- Un jeu : `GET /api/games/{slug}`
- Les catégories : [`GET /api/categories`](/api/categories), une catégorie : `GET /api/categories/{slug}`

L'API est en lecture seule et autorise les appels depuis n'importe quel site (CORS). Les réponses sont gardées
en cache une minute.

## Les jeux

| Paramètre | |
|---|---|
| `category` | une catégorie (son `slug`) |
| `kind` | `original` (création), `cpc` (remake Amstrad CPC), `port` (portage) |
| `order[rating]`, `order[plays]`, `order[publishedAt]`, `order[title]` | le tri, `asc` ou `desc` ; plusieurs à la suite pour départager. Par défaut : les plus récents d'abord |
| `page`, `itemsPerPage` | 30 jeux par page, jusqu'à 100 |

```bash
curl -g -H 'Accept: application/json' 'https://<ce site>/api/games?category=arcade&order[rating]=desc'
```

Chaque jeu :

| Champ | |
|---|---|
| `slug`, `title`, `description` | |
| `category`, `kind` | sa catégorie (`slug`), son genre de projet |
| `author` | son auteur (identifiant GitHub) |
| `rating`, `ratings` | sa note moyenne sur 5 (`null` sans vote), le nombre de votes |
| `plays` | le nombre de parties lancées |
| `engine`, `publishedAt` | la version du moteur JumPHP, la date de la dernière publication |
| `forkOf` | le jeu dont il est le fork, sinon `null` |
| `url`, `play`, `source` | sa page, le jeu en plein écran, son code navigable |
| `image` | son image 640 × 360 (`null` s'il n'en a pas) |
| `repository`, `manifest` | son dépôt GitHub, la liste de ses fichiers publiés ([mode distant](https://github.com/davidnurdin/jumPhp/blob/main/docs/remote.md) de JumPHP) |

Le tri par note est le même que sur [la page des jeux](/games) : la moyenne est tirée vers 3 tant qu'un jeu a peu
de votes (trois votes fantômes), pour qu'un seul 5 sur 5 ne passe pas devant beaucoup de 4,5.

## Pages et JSON-LD

En JSON simple (`Accept: application/json`), une liste est un tableau. En JSON-LD
(`Accept: application/ld+json`, le format par défaut), elle donne aussi le nombre total (`totalItems`) et les
adresses des pages (`view.next`, `view.last`) :

```json
{
  "@id": "/api/games",
  "totalItems": 42,
  "member": [ { "@id": "/api/games/pong", "slug": "pong", "title": "PONG", "…": "…" } ],
  "view": { "next": "/api/games?page=2", "last": "/api/games?page=2" }
}
```

Erreurs : `404` pour un jeu ou une catégorie qui n'existe pas (ou n'est plus listé), `422` pour un paramètre
invalide (`kind=zzz`), au format Problem Details (`application/problem+json`).
