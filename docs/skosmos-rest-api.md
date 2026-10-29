---
title: Skosmos REST API
nav_order: 2
---

# Skosmos REST API

All endpoints are at `/skosmos/rest/v1/`. Only taxonomies enabled in Settings → WP-SKOS are exposed; all others return 404.

## `GET /vocabularies`

Lists all enabled vocabularies.

**Response:**

```json
{
  "@context": { "skos": "...", "uri": "@id", ... },
  "uri": "https://example.com/skosmos/rest/v1/vocabularies",
  "vocabularies": [
    {
      "uri":             "https://example.com/skosmos/rest/v1/post_tag/",
      "id":              "post_tag",
      "title":           "Tags",
      "defaultLanguage": "en",
      "languages":       ["en"]
    }
  ]
}
```

## `GET /{vocab}/`

Vocabulary metadata and concept scheme info.

## `GET /{vocab}/topConcepts?lang=en`

Terms with no WordPress parent (`parent = 0`). Each entry includes `uri`, `prefLabel`, and `hasChildren`.

## `GET /{vocab}/lookup?label=X&lang=en`

Partial-name term search within one vocabulary. Returns up to 50 results.

## `GET /{vocab}/data?uri=X`

Full JSON-LD for a concept or ConceptScheme URI. Accepts:

- A concept URI: `https://example.com/wp-skos/concept/{taxonomy}/{slug}`
- A scheme URI: `https://example.com/wp-skos/scheme/{vocab}`

Returns `application/ld+json`. Concept responses are transient-cached (see [Cache](hypertaxonomy-integration.html#cache)).

## `GET /search?query=X&vocab={v}&lang=en&maxhits=N`

Label search across one or all enabled vocabularies. `vocab` is optional; omitting it searches all enabled taxonomies. `maxhits` defaults to 50, max 200. Trailing `*` wildcard is stripped (WP handles substring matching natively).
