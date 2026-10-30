---
title: Document Search
nav_order: 4
---

# Document search endpoint

```
GET /wp-json/wp-skos/v1/posts
    ?concept=URI&concept=URI
    &operator=AND|OR
    &page=1
    &per_page=20
    &platforms=wordpress,feed   # external-document scope (default: all supported)
    &foaf_depth=0               # 0 = direct peers only
```

Resolves each concept URI to a WordPress term ID, applies HyperTaxonomy expansion via `htax_get_expanded_term_ids()` (inclusions, hierarchy, group-based expansion) when available, then queries posts and returns them as JSON-LD. Without HyperTaxonomy, expansion falls back to the term plus its WordPress child terms. The query is hardcoded to `post_type=post`; there is no `post_type` parameter. `per_page` defaults to 20 and is capped at 100.

**Response envelope:**

```json
{
  "@context": {
    "schema": "https://schema.org/",
    "foaf":   "http://xmlns.com/foaf/0.1/",
    "dct":    "http://purl.org/dc/terms/"
  },
  "results": [
    {
      "@type":       "schema:Article",
      "@id":         "https://example.com/my-post/",
      "dct:title":   "Post title",
      "dct:date":    "2024-03-15T10:00:00+00:00",
      "dct:subject": [
        { "@id": "https://example.com/wp-skos/concept/post_tag/electric-vehicles" }
      ]
    }
  ],
  "total":       12,
  "page":        1,
  "per_page":    20,
  "total_pages": 1
}
```

`dct:subject` contains only the queried concept URIs that the post actually matches — a post matches a concept when one of the terms in that concept's expansion (the term, its descendants, and its HyperTaxonomy inclusions) is assigned to it. With `operator=OR` a post may list a subset of the queried concepts; with `operator=AND` it lists all of them. Other terms assigned to the post are not listed.

**Relevance ordering.** When HyperTaxonomy is active and its search index table is present, results are ordered by descending summed relevance `strength` from the HyperTaxonomy index over the expanded term set. Posts not present in the index sort after scored posts. Posts with equal scores, and unscored posts, are ordered newest first (`post_date DESC`, then post ID descending). When the index is unavailable (HyperTaxonomy inactive or the table missing), results are ordered by that date/ID order alone. Index rows involving hidden terms count only for users HyperTaxonomy allows to see hidden terms in every queried taxonomy (its **Hidden Terms** visibility setting, via `htax_can_see_hidden_terms()`). Counting, ranking, and pagination all run in SQL against the full candidate set, so `total` is exact and every result is reachable by paging, at a per-request cost that does not grow with the number of matches on the PHP side.

**Pagination via `Link` headers.** The response carries an RFC 5988 `Link` header with `rel="self"`, `rel="first"`, and — where applicable — `rel="prev"`, `rel="next"`, and `rel="last"`, each pointing at this endpoint with the same `concept`/`operator`/`per_page` params and the appropriate `page`.

**External documents.** Local results are `schema:Article` objects. When **HyperTaxonomy** is active, wp-skos also appends external/peer-site `foaf:Document` results for each queried concept, sourced through HyperTaxonomy's generic term API:

```php
// For each (visible) concept term in the request:
// $args carries scope hints: platforms, foaf_depth, per_page.
$documents = htax_get_term_external_posts( $term_id, $args );
```

`htax_get_term_external_posts()` returns related posts contributed by any provider plugin (TermConnect populates it from configured peer sites and feeds — explicit per-term links plus shared-identifier/synonym auto-matching, and FOAF when requested). wp-skos converts each to a `foaf:Document` whose `dct:subject` references the matching concept(s), deduplicates by URL, and appends them to the first results page only.

External documents honor `operator` the same way local results do: with `operator=OR` the external set is the union across the queried concepts; with `operator=AND` (and two or more concepts) only documents that matched **every** concept are kept (intersection by URL). Because each peer source is queried per concept, a document present for all of them carries every concept in its `dct:subject`.

**Scope params.** `platforms` (CSV; default all supported, currently `wordpress` + `feed`) and `foaf_depth` (default `0` = direct peers only) control which external posts are gathered. These are passed to HyperTaxonomy opaquely — the endpoint deliberately **does not** let callers target specific peer sites (that's an editorial concern for `[tc_peer_posts]`, and exposing the peer topology over a public concept API isn't appropriate).

This keeps wp-skos decoupled from the provider: it reads posts from HyperTaxonomy without knowing they came from TermConnect, and TermConnect contributes them without knowing wp-skos consumes them. **HyperTaxonomy enforces term visibility** — documents are never returned for a concept hidden from the requesting user. External documents therefore require HyperTaxonomy; without it, only local `schema:Article` results are returned.
