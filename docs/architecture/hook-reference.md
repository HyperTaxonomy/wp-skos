---
title: Hook Reference
parent: Architecture
nav_order: 3
---

# Hook Reference

The hook registrations in WP-SKOS that you can't guess from a callback's name: priorities chosen to run before other code, the hooks that clear its cache, and a filter added for a single query. Routine registrations (rewrite rules, query vars, the settings screen) are left out; search the code for `add_action( 'hook_name'` to find any of them.

The filters WP-SKOS applies for other code are documented in [Filters](../developer-reference/filters.html).

## Request handling

WP-SKOS serves its URLs from `template_redirect`:

| Callback | Priority | Why |
|---|---|---|
| `WPSKOS_Agent_Discovery::dispatch` (llms.txt and the API catalog) | 1 | These paths have no rewrite rule, so WordPress treats them as a 404; this runs before `redirect_canonical` (priority 10) acts on it |
| `WPSKOS_Export::dispatch` (whole-vocabulary export) | 1 | The same |
| `WPSKOS_Concept_URI::dispatch` (concept, collection and scheme URIs) | 10 | Matched by rewrite rules |
| `WPSKOS_Skosmos_API::dispatch` (Skosmos REST API) | 10 | Matched by a rewrite rule |

## Cache invalidation

`WPSKOS_Builder::flush_cache` runs on every hook that can change a concept document, its own or a neighbor's:

- WordPress: `saved_term`, `delete_term`, `update_option_wpskos_settings`, and `update_option_htax_settings` (a HyperTaxonomy settings save, which covers its feature flags)
- HyperTaxonomy (these fire only when it is active): `htax_includes_changed`, `htax_containers_changed`, `htax_excludes_changed`, `htax_included_groups_changed`, `htax_container_groups_changed`, `htax_term_groups_changed`, `htax_custom_inclusions_changed`, `htax_relationship_saved`, `htax_relationship_deleted`, `htax_redirect_changed`, `htax_redirect_removed`, `htax_after_merge_terms`, `htax_term_visibility_changed`, `htax_term_uris_changed`, `htax_term_locations_changed`. `htax_after_merge_terms` fires after a merge is committed: the merge deletes its source terms inside a database transaction, so with a persistent object cache, the `delete_term` flush can be seen before the merged data is committed.

Changes no hook reports show up when the cache expires.

## Document search

`WPSKOS_Document_Search` builds the SQL for its candidate posts with `WP_Query` but doesn't run it: it adds a `posts_pre_query` filter at `PHP_INT_MAX` that returns an empty result for that one query object, takes the generated SQL, and removes the filter again. Counting, ranking and paging then run in SQL on top of it.
