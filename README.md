# WP-SKOS

Exposes WordPress taxonomies as SKOS concept schemes via a Skosmos-compatible REST API and stable concept URIs. Integrates with HyperTaxonomy for the full SKOS relationship model.

## Features

- Skosmos REST API at `/skosmos/rest/v1/` — drop-in remote vocabulary support for Skosmos and any SKOS client
- Stable concept URIs at `/wp-skos/concept/{taxonomy}/{slug}` with content negotiation (JSON-LD or 303 → term archive)
- ConceptScheme URIs at `/wp-skos/scheme/{taxonomy}` and OWL property declarations at `/wp-skos/vocab/{slug}`
- Collection URIs at `/wp-skos/collection/{group-slug}` — HyperTaxonomy term groups as `skos:Collection`
- Document search endpoint — accepts concept URIs, returns relevance-ordered JSON-LD `schema:Article` results with `Link` pagination headers
- HyperTaxonomy integration: `skos:related`, `skos:broaderTransitive` / `skos:narrowerTransitive`, `skos:closeMatch`, `schema:location`, deprecated terms, term groups (`skos:Collection`), hidden term filtering, custom typed sub-properties
- Multilingual `skos:prefLabel` via Polylang or WPML
- Transient cache keyed by term ID + auth state; auto-invalidated on term save/delete and HyperTaxonomy relationship changes
- Python SKOS → HyperTaxonomy import converter

## Requirements

- WordPress 5.0+
- PHP 7.4+
- Pretty permalinks enabled

Optional:

- **HyperTaxonomy** — `skos:related`, `skos:closeMatch`, `schema:location`, deprecated terms, term groups, hidden terms, custom sub-properties
- **TermConnect** — external identifier data exposed as `skos:closeMatch`, and external peer-site posts contributed to the document search as `foaf:Document` (requires HyperTaxonomy)
- **As the Crow Flies** — physical location data exposed as `schema:location` (requires HyperTaxonomy)
- **Polylang** or **WPML** — multilingual `skos:prefLabel`

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

For the full data model — including the typed sub-property URIs — see the [documentation](docs/).

## Concept example

A trimmed concept document. Fields appear only when data is present; a standalone WordPress site produces a smaller document. See the [Concept URIs](docs/concept-uris.md) page for the complete output gallery.

```json
{
  "@context": {
    "skos":   "http://www.w3.org/2004/02/skos/core#",
    "owl":    "http://www.w3.org/2002/07/owl#",
    "rdfs":   "http://www.w3.org/2000/01/rdf-schema#",
    "schema": "https://schema.org/",
    "wpskos": "https://hypertaxonomy.org/wp-skos/vocab/"
  },
  "@graph": [
    {
      "@type": "skos:Concept",
      "@id":   "https://example.com/wp-skos/concept/post_tag/electric-vehicles",
      "skos:inScheme": { "@id": "https://example.com/wp-skos/scheme/post_tag" },
      "skos:prefLabel": [
        { "@value": "Electric Vehicles", "@language": "en" }
      ],
      "skos:notation": "electric-vehicles",
      "skos:broader":  [{ "@id": "https://example.com/wp-skos/concept/post_tag/transportation" }],
      "skos:narrower": [
        { "@id": "https://example.com/wp-skos/concept/post_tag/battery-ev" }
      ],
      "skos:related": [
        { "@id": "https://example.com/wp-skos/concept/post_tag/charging-infrastructure" }
      ],
      "wpskos:includes": [
        { "@id": "https://example.com/wp-skos/concept/post_tag/charging-infrastructure" }
      ],
      "skos:closeMatch": [
        { "@id": "http://www.wikidata.org/entity/Q193692" }
      ],
      "rdfs:seeAlso": [
        { "@id": "https://example.com/tag/electric-vehicles/", "@type": "foaf:Document" }
      ]
    }
  ]
}
```

## Documentation

- **[Skosmos REST API](docs/skosmos-rest-api.md)** — all endpoints at `/skosmos/rest/v1/`.
- **[Concept URIs](docs/concept-uris.md)** — concept / scheme / collection URIs, content negotiation, OWL property declarations, and the full JSON-LD output gallery.
- **[Document Search](docs/document-search.md)** — the document-search endpoint, relevance ordering, pagination, and the external-documents filter.
- **[HyperTaxonomy Integration](docs/hypertaxonomy-integration.md)** — functions used, feature-flag gates, cache invalidation, and multilingual labels.
- **[Python Converter](docs/converter.md)** — the SKOS → HyperTaxonomy import converter and its mapping config.

## Translations

wp-skos is translation-ready and ships with a complete translation template: `languages/wp-skos.pot`.

To contribute a translation: copy the template to `languages/wp-skos-<locale>.po`, translate it, and open a pull request — the build compiles it automatically.

To regenerate `languages/wp-skos.pot` after adding or changing translatable strings, run `wp i18n make-pot . languages/wp-skos.pot` from the plugin root (requires [WP-CLI](https://wp-cli.org/)).

## License

GPLv2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
