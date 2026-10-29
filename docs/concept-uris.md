---
title: Concept URIs
nav_order: 3
---

# Concept URIs

## Content negotiation

`GET /wp-skos/concept/{taxonomy}/{slug}` and `GET /wp-skos/scheme/{taxonomy}` inspect the `Accept` header:

- An `Accept` header containing `application/ld+json` → JSON-LD response
- An `Accept` header containing `text/html` but not `application/ld+json` → `303 See Other` redirect
- Any other `Accept` header, including a bare `*/*` or no header at all → JSON-LD response

This allows browsers that explicitly ask for HTML to navigate to a human-readable page, while semantic clients and generic HTTP tools — which typically send `Accept: */*` — get JSON-LD by default.

For a **concept** URI, the redirect target is the term's WordPress archive page (its permalink).

For a **scheme** URI, there is no single public "archive" for an entire taxonomy the way there is for one term. The redirect only happens for a visitor who can manage that taxonomy's terms (the `manage_categories` capability), sending them to the admin Terms screen; anyone else gets the JSON-LD response even when their `Accept` header requests HTML, since redirecting them into a wp-admin login wall would not be useful.

## Taxonomy-qualified URIs

URIs are built from the term's taxonomy and slug: `/wp-skos/concept/{taxonomy}/{slug}`. Renaming a term changes its URI — WP-SKOS does not maintain URI stability across slug changes. The term archive URL (a WordPress permalink) is exposed as `rdfs:seeAlso` and is the appropriate value to present in search results or UI links.

If a term in vocabulary A has the same slug as a term in vocabulary B, they are distinct concepts with distinct URIs. The taxonomy is part of the concept URI, so each slug always resolves within its own taxonomy and same-slug terms in different taxonomies never collide.

## OWL property declarations

`GET /wp-skos/vocab/{slug}` returns a minimal JSON-LD OWL property declaration for built-in and custom HyperTaxonomy relationship types:

```json
{
  "@context": { ... },
  "@graph": [
    {
      "@type":        "owl:ObjectProperty",
      "@id":          "https://hypertaxonomy.org/wp-skos/vocab/includes",
      "rdfs:subPropertyOf": { "@id": "http://www.w3.org/2004/02/skos/core#semanticRelation" },
      "rdfs:label":   "includes"
    }
  ]
}
```

## JSON-LD output

### Concept document

A fully populated concept document (HyperTaxonomy + TermConnect + ATCF active):

```json
{
  "@context": {
    "skos":   "http://www.w3.org/2004/02/skos/core#",
    "owl":    "http://www.w3.org/2002/07/owl#",
    "rdfs":   "http://www.w3.org/2000/01/rdf-schema#",
    "dct":    "http://purl.org/dc/terms/",
    "foaf":   "http://xmlns.com/foaf/0.1/",
    "schema": "https://schema.org/",
    "wpskos": "https://hypertaxonomy.org/wp-skos/vocab/"
  },
  "@graph": [
    {
      "@type": "skos:Concept",
      "@id":   "https://example.com/wp-skos/concept/post_tag/electric-vehicles",
      "skos:inScheme": { "@id": "https://example.com/wp-skos/scheme/post_tag" },
      "skos:prefLabel": [
        { "@value": "Electric Vehicles", "@language": "en" },
        { "@value": "Véhicules électriques", "@language": "fr" }
      ],
      "skos:definition": { "@value": "Road vehicles powered entirely by electric motors.", "@language": "en" },
      "skos:notation": "electric-vehicles",
      "skos:broader":  { "@id": "https://example.com/wp-skos/concept/post_tag/transportation" },
      "skos:narrower": [
        { "@id": "https://example.com/wp-skos/concept/post_tag/battery-ev" },
        { "@id": "https://example.com/wp-skos/concept/post_tag/fuel-cell-ev" }
      ],
      "skos:related": [
        { "@id": "https://example.com/wp-skos/concept/post_tag/charging-infrastructure" }
      ],
      "wpskos:includes": [
        { "@id": "https://example.com/wp-skos/concept/post_tag/charging-infrastructure" }
      ],
      "skos:closeMatch": [
        { "@id": "http://www.wikidata.org/entity/Q193692" },
        { "@id": "http://id.loc.gov/authorities/subjects/sh85045070" }
      ],
      "rdfs:seeAlso": [
        { "@id": "https://en.wikipedia.org/wiki/Electric_vehicle", "@type": "foaf:Document", "rdfs:label": "Electric vehicle" },
        { "@id": "https://example.com/tag/electric-vehicles/", "@type": "foaf:Document" }
      ],
      "_links": {
        "posts": "https://example.com/wp-json/wp-skos/v1/posts?concept=https%3A%2F%2Fexample.com%2Fwp-skos%2Fconcept%2Fpost_tag%2Felectric-vehicles"
      },
      "schema:location": [
        {
          "@type": "schema:Place",
          "schema:name": "Showroom",
          "schema:address": {
            "@type": "schema:PostalAddress",
            "schema:streetAddress": "100 Innovation Dr",
            "schema:addressLocality": "Portland",
            "schema:addressRegion": "OR",
            "schema:postalCode": "97201",
            "schema:addressCountry": "US"
          },
          "schema:geo": {
            "@type": "schema:GeoCoordinates",
            "schema:latitude": 45.512,
            "schema:longitude": -122.678
          }
        }
      ]
    }
  ]
}
```

Fields appear only when data is present. A standalone WordPress site with no optional plugins produces only `@type`, `@id`, `skos:inScheme`, `skos:prefLabel`, `skos:notation`, optionally `skos:definition` (plain-text descriptions) or `skos:scopeNote` (descriptions containing HTML), `skos:broader`, `skos:narrower`, `skos:broaderTransitive`, `skos:narrowerTransitive`, `_links.posts`, and `rdfs:seeAlso` (term archive URL only).

`skos:broaderTransitive` lists every ancestor and `skos:narrowerTransitive` every descendant — additive to the direct `skos:broader` / `skos:narrower`. Both are omitted when empty.

Every concept document carries a `_links.posts` discovery key whose value is the document-search URL for that concept (this endpoint pre-filled with the concept's own URI). It lets a client follow from a concept to its documents without constructing the query itself.

`dct:modified` is the most recent modification time of any published post in the concept's archive — i.e. `MAX(post_modified)` over the posts assigned to the term, plus its HyperTaxonomy expansion (descendants and inclusions) when HyperTaxonomy is active. It is emitted as ISO 8601 (GMT) and computed live on each request (so it stays current as posts are edited, independent of the concept document cache). The triple is absent when the archive contains no published posts.

### Redirect (deprecated) concept

```json
{
  "@type": "skos:Concept",
  "@id":   "https://example.com/wp-skos/concept/post_tag/old-name",
  "owl:deprecated": true,
  "dct:isReplacedBy": { "@id": "https://example.com/wp-skos/concept/post_tag/new-name" }
}
```

### ConceptScheme document

```json
{
  "@context": { ... },
  "@graph": [
    {
      "@type": "skos:ConceptScheme",
      "@id":   "https://example.com/wp-skos/scheme/post_tag",
      "skos:prefLabel": { "@value": "Tags", "@language": "en" },
      "skos:hasTopConcept": [
        { "@id": "https://example.com/wp-skos/concept/post_tag/transportation" }
      ]
    }
  ]
}
```

### Collection document

When HyperTaxonomy is active, each term group is a `skos:Collection` at `/wp-skos/collection/{group-slug}`. Groups live only in the `group` taxonomy, so the slug alone identifies the collection. `skos:member` combines direct member concepts (terms with a `member_of` relationship to the group) and direct child groups (each a nested `skos:Collection`). The group hierarchy is **not** flattened — child groups appear as nested Collections rather than having their members hoisted up.

```json
{
  "@context": { ... },
  "@graph": [
    {
      "@type": "skos:Collection",
      "@id":   "https://example.com/wp-skos/collection/regions",
      "skos:prefLabel": [{ "@value": "Regions", "@language": "en" }],
      "skos:member": [
        { "@id": "https://example.com/wp-skos/collection/north-america" },
        { "@id": "https://example.com/wp-skos/concept/post_tag/portland" }
      ]
    }
  ]
}
```

Collections are always served as JSON-LD (groups have no public archive, so there is no 303 redirect) and are built on demand without caching, since membership changes are not reliably invalidated. A hidden group returns `404` for unauthenticated requests, as do hidden child groups and member concepts (filtered out of `skos:member`). Member concepts are included only when their taxonomy is enabled in Settings → WP-SKOS. The `skos:member` key is omitted entirely when there are no visible members. Without HyperTaxonomy, the endpoint returns `404`.
