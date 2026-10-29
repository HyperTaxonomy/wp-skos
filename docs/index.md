---
title: Home
nav_order: 1
---

# WP-SKOS

Exposes WordPress taxonomies as SKOS concept schemes via a Skosmos-compatible REST API and stable concept URIs. Integrates with HyperTaxonomy for the full SKOS relationship model.

## Part of the HyperTaxonomy suite

One of five interoperable WordPress plugins for richer, connected taxonomies. Each works on its own; together they share a common term model. Learn more at [hypertaxonomy.org](https://hypertaxonomy.org).

- **[HyperTaxonomy](https://hypertaxonomy.org/hypertaxonomy/)** — the core: term groups, inclusions, synonyms, redirects, icons, relevance-scored search, and enhanced archives.
- **[TermConnect](https://hypertaxonomy.org/termconnect/)** — link terms to knowledge-base identifiers (Wikidata, GeoNames, LCSH…) and peer sites; delegated identity, shared vocabularies, and cross-site related content.
- **[Social Hashtags & Mentions](https://hypertaxonomy.org/social-hashtags-mentions/)** — inline #hashtags and @mentions as taxonomy terms, linked to the social networks you enable (built on TermConnect).
- **[As the Crow Flies](https://hypertaxonomy.org/as-the-crow-flies/)** — attach physical locations to terms and filter content by distance/proximity.
- **WP-SKOS** (this plugin) — expose your taxonomies as SKOS linked data via a Skosmos-compatible REST API.

## Documentation map

- **[Skosmos REST API](skosmos-rest-api.html)** — all endpoints at `/skosmos/rest/v1/` for Skosmos and any SKOS client.
- **[Concept URIs](concept-uris.html)** — concept / scheme / collection URIs, content negotiation, OWL property declarations, and the full JSON-LD output gallery.
- **[Document Search](document-search.html)** — the concept-queryable document search endpoint, relevance ordering, pagination, and external peer-site documents (sourced via HyperTaxonomy).
- **[HyperTaxonomy Integration](hypertaxonomy-integration.html)** — functions used, feature-flag gates, cache invalidation, and multilingual labels.
- **[Python Converter](converter.html)** — the SKOS → HyperTaxonomy import converter and its mapping config.

## Data model

| WordPress | SKOS |
|---|---|
| Taxonomy | `skos:ConceptScheme` at `/wp-skos/scheme/{taxonomy}` |
| Term | `skos:Concept` at `/wp-skos/concept/{taxonomy}/{slug}` |
| Term name | `skos:prefLabel` |
| Term description (plain text) | `skos:definition` |
| Term description (contains HTML) | `skos:scopeNote` |
| Term slug | `skos:notation` |
| Parent term | `skos:broader` |
| Child terms | `skos:narrower` |
| All ancestor terms | `skos:broaderTransitive` |
| All descendant terms | `skos:narrowerTransitive` |
| HyperTaxonomy inclusion / synonym / custom rel | `skos:related` (+ typed sub-property) |
| HyperTaxonomy redirect | `owl:deprecated` + `dct:isReplacedBy` |
| HyperTaxonomy term group | `skos:Collection` at `/wp-skos/collection/{group-slug}` |
| Most recent post-modified time in the concept's archive | `dct:modified` |
| TermConnect external URI | `skos:closeMatch` |
| ATCF location row | `schema:location` → `schema:Place` |
| Term archive URL | `rdfs:seeAlso` |

HyperTaxonomy relationship types that produce `skos:related` also produce a typed sub-property:

| Relationship type | Sub-property URI |
|---|---|
| `includes` | `https://hypertaxonomy.org/wp-skos/vocab/includes` |
| `member_of` (container) | `https://hypertaxonomy.org/wp-skos/vocab/container` |
| Synonym relationship | `https://hypertaxonomy.org/wp-skos/vocab/synonym` |
| Custom slug | `{home_url}/wp-skos/vocab/{slug}` |

Custom type URIs use `home_url()` so they are site-local. Built-in type URIs use the stable `https://hypertaxonomy.org/wp-skos/vocab/` namespace so they are consistent across sites.

## Concept document example

A fully populated concept document (HyperTaxonomy + TermConnect + ATCF active). Fields appear only when data is present; a standalone WordPress site produces a much smaller document. See [Concept URIs](concept-uris.html) for the complete output gallery and field-by-field notes.

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
      "skos:definition": [
        { "@value": "Road vehicles powered entirely by electric motors.", "@language": "en" }
      ],
      "skos:notation": "electric-vehicles",
      "skos:broader":  [{ "@id": "https://example.com/wp-skos/concept/post_tag/transportation" }],
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
        { "@id": "https://en.wikipedia.org/wiki/Electric_vehicle", "@type": "foaf:Document" },
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

## License

GPLv2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
