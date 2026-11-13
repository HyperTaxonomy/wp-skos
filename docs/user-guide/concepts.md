---
title: Concepts & Data Model
parent: User Guide
nav_order: 1
---

# Concepts & Data Model

Each taxonomy you enable in **Settings → WP-SKOS** becomes a SKOS concept scheme, and each of its terms a concept with its own URI. This page shows what each part of a term becomes. For the exact responses, see [Concept URIs & JSON-LD](../developer-reference/concept-uris.html).

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
| Custom slug | `{site}/wp-skos/vocab/{slug}` |
| Custom slug, reverse direction | `{site}/wp-skos/vocab/{slug}_reverse` |

For custom types, `{site}` is this site, or the site a type was synced from through TermConnect. Built-in type URIs use the `https://hypertaxonomy.org/wp-skos/vocab/` namespace so they are consistent across sites.

**`skos:related` covers every HyperTaxonomy relationship, in both directions:** cross-branch inclusions, containers, synonyms and custom types. The typed sub-properties above say which relationship it is and which way it points. A client that understands only `skos:related` reads them all as undirected associations.

**External identifiers use `skos:closeMatch`, not `skos:exactMatch`,** because WP-SKOS can't verify how precise a mapping is. Link types range from encyclopedias to authority files, so `skos:closeMatch` makes the safer claim: close, but not necessarily exact.

## Concept URIs and slugs

URIs are built from the term's taxonomy and slug: `/wp-skos/concept/{taxonomy}/{slug}`. Changing a term's name leaves its URI alone, but changing its slug changes the URI, and the old one returns 404. WP-SKOS doesn't keep old URIs working across slug changes. The term archive URL (a WordPress permalink) is exposed as `rdfs:seeAlso` and is the appropriate value to present in search results or UI links.

If a term in vocabulary A has the same slug as a term in vocabulary B, they are distinct concepts with distinct URIs. The taxonomy is part of the concept URI, so each slug always resolves within its own taxonomy and same-slug terms in different taxonomies never collide.

## Multilingual labels

When a multilingual plugin is active, `skos:prefLabel` is an array with one entry per language:

```json
"skos:prefLabel": [
  { "@value": "Electric Vehicles", "@language": "en" },
  { "@value": "Véhicules électriques", "@language": "fr" }
]
```

WP-SKOS builds the label map in `get_pref_labels( WP_Term $term )`, which detects multilingual plugins automatically: **Polylang** (via `pll_get_term_translations()`) and **WPML** (via the `wpml_get_element_translations` filter). Sites with no multilingual plugin get a single-entry array using the language tag from Settings → WP-SKOS.
