---
title: Skosmos REST API
nav_order: 2
---

# Skosmos REST API

These endpoints follow the REST API that [Skosmos](https://skosmos.org) offers, so software written against that API can read your vocabularies. A Skosmos installation reads its vocabularies from a SPARQL endpoint rather than this API; to use one with your vocabularies, see [Exporting to a triple store](#exporting-to-a-triple-store).

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

## Exporting to a triple store

Each enabled vocabulary is available as one JSON-LD file:

```
GET /wp-skos/export/{taxonomy}.jsonld
```

The file holds the concept scheme and every concept visible to the requester, so request it without logging in to leave hidden terms out. It's standard RDF, so it can be loaded into a triple store and used by anything that reads one, including a Skosmos installation.

{: .note }
> The file has been checked with an RDF parser, but this route hasn't yet been tested against a live Skosmos installation. The Skosmos and Fuseki settings below are a starting point, so check them against the documentation for your versions.

**1. Load the file into Apache Jena Fuseki**, using one named graph per vocabulary:

```bash
curl -o post_tag.jsonld https://example.com/wp-skos/export/post_tag.jsonld

curl -X PUT -H "Content-Type: application/ld+json" \
  --data-binary @post_tag.jsonld \
  "http://localhost:3030/skosmos/data?graph=https://example.com/wp-skos/scheme/post_tag"
```

**2. Add the vocabulary to Skosmos's `config.ttl`:**

```turtle
:post_tag a skosmos:Vocabulary, void:Dataset ;
    dc:title "Tags"@en ;
    skosmos:shortName "Tags" ;
    skosmos:language "en" ;
    skosmos:defaultLanguage "en" ;
    skosmos:showTopConcepts "true" ;
    void:uriSpace "https://example.com/wp-skos/concept/post_tag/" ;
    skosmos:mainConceptScheme <https://example.com/wp-skos/scheme/post_tag> ;
    skosmos:sparqlGraph <https://example.com/wp-skos/scheme/post_tag> ;
    void:dataDump <https://example.com/wp-skos/export/post_tag.jsonld> ;
    void:sparqlEndpoint <http://localhost:3030/skosmos/sparql> .
```

**3. Repeat step 1 on a schedule** to pick up changes, since the triple store holds a copy. Concept URIs in the copy still point at your site.

Term groups (`skos:Collection`) aren't part of the export, because a group can span taxonomies. They remain available at their own URIs.
