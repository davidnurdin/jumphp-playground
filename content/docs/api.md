# L'API publique

L'annuaire des jeux se lit en JSON, sans compte : pour un autre site, une application, un bot…

- **Documentation interactive (OpenAPI)** : [/api/docs](/api/docs)
- Les jeux : [`GET /api/games`](/api/games) — filtres `category`, `kind`, tri `order[rating]`, `order[plays]`,
  `order[publishedAt]`, `order[title]`, pages de 30 (`page=2`)
- Un jeu : `GET /api/games/{slug}`
- Les catégories : [`GET /api/categories`](/api/categories)

Chaque jeu donne son titre, sa description, sa catégorie, son auteur, sa note, son nombre de parties, et ses
adresses : sa page, le jeu, son image, son dépôt GitHub, et le manifeste de ses fichiers.

```bash
curl -H 'Accept: application/json' 'https://<ce site>/api/games?order[rating]=desc'
```

L'API est en lecture seule et autorise les appels depuis n'importe quel site (CORS).
