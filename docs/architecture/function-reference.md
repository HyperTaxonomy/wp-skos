---
title: Function Reference
parent: Architecture
nav_order: 2
---

# Function Reference
{: .no_toc }

Functions and methods of WP-SKOS by file, with each one's docblock summary, chosen by one rule used across the suite's plugins. Listed: the public API (documented in the [Developer Reference](../developer-reference/)) and anything called from another file, which is what contributors reuse. Not listed: hook callbacks, of which the [Hook Reference](hook-reference.html) covers the registrations you can't guess from the code (search for the others by name); screen wiring (rendering, enqueueing, menu and column registration, constructors); overrides of a base class's method; and anything used only inside its own file or class, which is best read in the code. This page is generated from the code.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Includes

### AI agent discovery (`includes/class-agent-discovery.php`)

Tells AI agents and other API clients how to use this site's vocabularies.


**`WPSKOS_Agent_Discovery`**

- `WPSKOS_Agent_Discovery::request_path()` — The request path relative to the site's home URL, without a trailing slash.

### Concept URIs (`includes/class-concept-uri.php`)

Handles stable concept, scheme, and vocabulary property URIs.


**`WPSKOS_Concept_URI`**

- `WPSKOS_Concept_URI::add_rewrite_rules()` — Add rewrite rules for concept, collection, scheme and vocabulary property URIs.

### JSON-LD export (`includes/class-export.php`)

Serves a whole vocabulary as one JSON-LD file.


**`WPSKOS_Export`**

- `WPSKOS_Export::url( string $taxonomy )` — URL of a taxonomy's export.

### Settings page (`includes/class-settings.php`)

Admin settings page.


**`WPSKOS_Settings`**

- `WPSKOS_Settings::get_enabled_taxonomies()` — Taxonomies exposed as SKOS vocabularies.
- `WPSKOS_Settings::get_default_language()` — Language tag for labels when no multilingual plugin is active.
- `WPSKOS_Settings::is_taxonomy_enabled( string $taxonomy )` — Whether a taxonomy is exposed as a SKOS vocabulary.

### SKOS builder (`includes/class-skos-builder.php`)

Builds SKOS data structures from WordPress taxonomy and term data.


**`WPSKOS_Builder`**

- `WPSKOS_Builder::concept_uri( string $taxonomy, string $term_slug )` — URI of a term's concept.
- `WPSKOS_Builder::scheme_uri( string $taxonomy )` — URI of a taxonomy's concept scheme.
- `WPSKOS_Builder::cache_key_scheme( string $taxonomy )` — Transient key for a cached scheme document.
- `WPSKOS_Builder::get_pref_labels( WP_Term $term )` — Build prefLabel map keyed by BCP 47 language tag.
- `WPSKOS_Builder::is_hidden( int $term_id )` — Whether a term is hidden in HyperTaxonomy, and so left out of every output for every viewer, the same rule as HyperTaxonomy's REST relationship fields. Output is then the same for all viewers and safe to cache and share.
- `WPSKOS_Builder::build_concept( WP_Term $term )` — Build the full SKOS data array for a concept.
- `WPSKOS_Builder::concept_document( WP_Term $term )` — A concept's JSON-LD document, from the cache or freshly built and cached.
- `WPSKOS_Builder::inject_modified( array $response, WP_Term $term )` — Inject a live dct:modified into a (possibly cached) concept response.
- `WPSKOS_Builder::build_scheme( string $taxonomy )` — Build the SKOS ConceptScheme for a taxonomy.
- `WPSKOS_Builder::build_collection( WP_Term $group )` — Build the SKOS Collection for a term group.
- `WPSKOS_Builder::build_vocab_property( string $slug )` — Build the OWL property declaration for a vocabulary property.
- `WPSKOS_Builder::jsonld_context()` — Standard @context for concept/scheme JSON-LD responses.
- `WPSKOS_Builder::jsonld_response( array $data, int $status = 200 )` — Send a JSON-LD response and stop. Shared by the concept URI and Skosmos REST handlers.
- `WPSKOS_Builder::error_response( string $message, int $status = 404 )` — Send a `{"error": ...}` JSON response and stop. Shared by the concept URI and Skosmos REST handlers.

### Skosmos REST API (`includes/class-skosmos-api.php`)

Skosmos REST API implementation.


**`WPSKOS_Skosmos_API`**

- `WPSKOS_Skosmos_API::add_rewrite_rules()` — Route /skosmos/rest/v1/ requests to the API handler.
