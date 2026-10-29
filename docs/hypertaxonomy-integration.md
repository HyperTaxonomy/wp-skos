---
title: HyperTaxonomy Integration
nav_order: 5
---

# HyperTaxonomy integration

WP-SKOS calls HyperTaxonomy through function-existence-guarded wrappers. It has no direct knowledge of TermConnect, As the Crow Flies, or any other plugin — all integrations are accessed through HyperTaxonomy public API.

## Functions called

| HyperTaxonomy function | Purpose |
|---|---|
| `htax_is_term_hidden( $term_id )` | Gate hidden terms: return 404 for unauthorized users; also filters hidden groups, child groups, and member concepts from Collections |
| `htax_get_included_terms( $term )` | Inclusions → `skos:related` and the `wpskos:includes` sub-property |
| `htax_get_container_terms( $term )` | Containers (reverse inclusions) → `skos:related` and the `wpskos:container` sub-property |
| `htax_get_synonym_terms( $term )` | Mutual inclusions → `skos:related` and the `wpskos:synonym` sub-property |
| `htax_get_related_terms( $term_id, $type )` | Custom relationship types (forward) → `skos:related` and typed sub-properties |
| `htax_get_reverse_related_terms( $term_id, $type )` | Custom relationship types (reverse) → typed `_reverse` sub-properties |
| `htax_get_registered_relationship_types( $include_custom )` | Enumerate custom relationship types and read their metadata (label, symmetry, source site) |
| `htax_get_redirect_target( $term_id )` | Detect redirect terms for `owl:deprecated` + `dct:isReplacedBy` |
| `htax_get_term_uris( $term_id )` | External URIs → `skos:closeMatch` (TermConnect populates via `htax_term_uris` filter) |
| `htax_get_term_locations( $term_id )` | Raw location rows → formatted to `schema:Place` objects inside wp-skos (ATCF provides the underlying data) |
| `htax_taxonomy_has_feature( $taxonomy, 'share_locations' )` | Gate `schema:location` on the per-taxonomy **Share list of locations** flag |
| `htax_get_expanded_term_ids( $term_id, true )` | Concept → expanded post set for the document search tax_query. The `true` flag also folds in terms that redirect to the concept, so a target concept's documents include those tagged with its redirecting (deprecated) terms — matching the relevance index |
| `htax_get_term_external_posts( $term_id, $args )` | Related peer-site documents → `foaf:Document` results in document search; `$args` carries scope hints (platforms, foaf_depth, per_page) (TermConnect populates via `htax_term_external_posts`; HT gates visibility, so hidden concepts contribute nothing) |
| `htax_is_term_hidden( $term_id )` / `htax_can_see_hidden_terms( $taxonomy )` | Skip hidden concepts when resolving a document-search request (delegating the visibility decision to HT) |
| `htax_get_all_term_groups()` | Gate Collection endpoint availability (presence check) |

Relevance ordering in the document search is **not** produced by a HyperTaxonomy function — wp-skos runs a direct SQL query against the `wp_htax_search_terms` index table to sum each candidate post's `strength` over the expanded term set. The index stores strength per scoring model, so the query restricts rows to the model HyperTaxonomy uses for search (`htax_strength_model_sql()`). It also gates `relationship_type` per the concept's taxonomy enabled features (mirroring HyperTaxonomy's own scoring), and combines rows sharing a `(post, term)` pair via noisy-OR (`1 − Π(1 − strength)`, regardless of `source_term` or `relationship_type`) before summing across terms — matching HyperTaxonomy's term-strength scorers exactly: a term reached by only one path is unaffected, while a term reached by several distinct source terms scores higher without the combined value ever reaching the ceiling a single direct row can hit.

Collection membership (`WP_SKOS_Builder::build_collection()`) is the other direct SQL exception: it queries the `wp_htax_term_relationships` table for `member_of` rows pointing at the group, since HyperTaxonomy exposes no public function for "all terms that declare membership in this group." Both direct queries are guarded with `SHOW TABLES LIKE` before running, so a HyperTaxonomy schema change degrades to an empty result (no relevance ordering / no Collection members) rather than a fatal error.

## Feature flags checked

| Flag | Effect when disabled |
|---|---|
| `share_locations` | `schema:location` omitted from concept documents |

## Cache invalidation hooks

| Hook | Fired by | What WP-SKOS invalidates |
|---|---|---|
| `saved_term` | WordPress core | Both concept variants + scheme for that taxonomy |
| `delete_term` | WordPress core | Both concept variants + scheme for that taxonomy |
| `htax_includes_changed` | HyperTaxonomy | Both concept variants for the affected term |
| `htax_term_uris_changed` | HyperTaxonomy (relayed from TC) | Both concept variants for the affected term |
| `htax_term_locations_changed` | HyperTaxonomy (relayed from ATCF) | Both concept variants for the affected term |

WP-SKOS does not depend on TermConnect or As the Crow Flies directly. HyperTaxonomy listens for `tc_term_uris_changed` and `atcf_term_location_changed` and re-fires them under its own namespace so downstream plugins only need to depend on HyperTaxonomy.

## Cache

Concept documents are stored as WordPress transients with TTL 0 (persist until explicitly deleted):

| Key pattern | Content |
|---|---|
| `wpskos_cpt_{term_id}_0` | Concept JSON-LD, unauthenticated view |
| `wpskos_cpt_{term_id}_1` | Concept JSON-LD, authenticated view (may include hidden linked concepts) |
| `wpskos_sch_{md5(taxonomy)}` | ConceptScheme JSON-LD |

The two concept variants exist because hidden linked terms appear in `skos:narrower` / `skos:related` for privileged users but are suppressed for public visitors.

All transients and the `wpskos_settings` option are deleted when the plugin is uninstalled via the WordPress Plugins screen.

## Multilingual labels

When a multilingual plugin is active, `skos:prefLabel` is an array with one entry per language:

```json
"skos:prefLabel": [
  { "@value": "Electric Vehicles", "@language": "en" },
  { "@value": "Véhicules électriques", "@language": "fr" }
]
```

WP-SKOS builds the label map in `get_pref_labels( WP_Term $term )`, which detects multilingual plugins automatically: **Polylang** (via `pll_get_term_translations()`) and **WPML** (via the `wpml_get_element_translations` filter). Sites with no multilingual plugin get a single-entry array using the language tag from Settings → WP-SKOS.
