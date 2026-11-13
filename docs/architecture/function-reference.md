---
title: Function Reference
parent: Architecture
nav_order: 2
---

# Function Reference
{: .no_toc }

WP-SKOS's functions and public and protected methods, by file, with each one's docblock summary. Private methods aren't listed. This page is generated from the code. For what other plugins and themes should call, see the endpoint pages, starting from the [documentation home](../); for hook registrations, see the [Hook Reference](hook-reference.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Plugin root

### Uninstall (`uninstall.php`)

Runs when the plugin is deleted via the WordPress admin.

- `wpskos_uninstall_cleanup()` — Clean up the current site.

### Main plugin file (`wp-skos.php`)

Main plugin file.

- `wpskos_action_links( array $links )` — Add a Settings link to the plugin's row on the Plugins screen.
- `wpskos_init()` — Bootstrap all WP-SKOS components.
- `wpskos_disable_canonical_redirect( $redirect_url )` — Keep WP-SKOS URLs exactly as requested.
- `wpskos_activate()` — Add the rewrite rules and flush them on activation.
- `wpskos_deactivate()` — Remove the concept/scheme/collection URI rewrite rules: the plugin's rules are still registered during this request, so flush_rewrite_rules() would rebuild them; dropping the stored rules makes WordPress rebuild them without this plugin on the next request.

## Includes

### AI agent discovery (`includes/class-agent-discovery.php`)

Tells AI agents and other API clients how to use this site's vocabularies.


**`WP_SKOS_Agent_Discovery`**

- `WP_SKOS_Agent_Discovery::register()` — Hook the llms.txt and API catalog handler.
- `WP_SKOS_Agent_Discovery::dispatch()` — Serve the guide or catalog when the request is for one of their paths.
- `WP_SKOS_Agent_Discovery::request_path()` — The request path relative to the site's home URL, without a trailing slash.
- `WP_SKOS_Agent_Discovery::build_guide()` — Build the llms.txt guide for this site's enabled vocabularies.
- `WP_SKOS_Agent_Discovery::build_catalog()` — Build the RFC 9727 API catalog, in Linkset format.

### Concept URIs (`includes/class-concept-uri.php`)

Handles stable concept, scheme, and vocabulary property URIs.


**`WP_SKOS_Concept_URI`**

- `WP_SKOS_Concept_URI::register()` — Hook the URI rewrite rules, query vars and request handler.
- `WP_SKOS_Concept_URI::add_rewrite_rules()` — Add rewrite rules for concept, collection, scheme and vocabulary property URIs.
- `WP_SKOS_Concept_URI::add_query_vars( array $vars )` — Register the query vars those rewrite rules set.
- `WP_SKOS_Concept_URI::dispatch()` — Serve the concept, collection, scheme or property document the request asks for.

### Document search (`includes/class-document-search.php`)

Document search endpoint.


**`WP_SKOS_Document_Search`**

- `WP_SKOS_Document_Search::register()` — Hook the REST route registration.
- `WP_SKOS_Document_Search::register_route()` — Register GET /wp-skos/v1/posts.
- `WP_SKOS_Document_Search::handle( WP_REST_Request $request )` — Run a document search and return the matching posts.

### JSON-LD export (`includes/class-export.php`)

Serves a whole vocabulary as one JSON-LD file.


**`WP_SKOS_Export`**

- `WP_SKOS_Export::register()` — Hook the export handler.
- `WP_SKOS_Export::url( string $taxonomy )` — URL of a taxonomy's export.
- `WP_SKOS_Export::dispatch()` — Serve the export when the request is for its path, and nothing else has claimed it.

### Settings page (`includes/class-settings.php`)

Admin settings page.


**`WP_SKOS_Settings`**

- `WP_SKOS_Settings::register()` — Hook the settings page and its settings.
- `WP_SKOS_Settings::add_menu()` — Add Settings → WP-SKOS.
- `WP_SKOS_Settings::register_settings()` — Register the settings and their fields.
- `WP_SKOS_Settings::field_enabled_taxonomies()` — Render the enabled taxonomies checkboxes.
- `WP_SKOS_Settings::field_default_language()` — Render the default language field.
- `WP_SKOS_Settings::render_page()` — Render the settings page.
- `WP_SKOS_Settings::sanitize( $raw )` — Sanitize the saved settings.
- `WP_SKOS_Settings::get_enabled_taxonomies()` — Taxonomies exposed as SKOS vocabularies.
- `WP_SKOS_Settings::get_default_language()` — Language tag for labels when no multilingual plugin is active.
- `WP_SKOS_Settings::is_taxonomy_enabled( string $taxonomy )` — Whether a taxonomy is exposed as a SKOS vocabulary.

### SKOS builder (`includes/class-skos-builder.php`)

Builds SKOS data structures from WordPress taxonomy and term data.


**`WP_SKOS_Builder`**

- `WP_SKOS_Builder::concept_uri( string $taxonomy, string $term_slug )` — URI of a term's concept.
- `WP_SKOS_Builder::scheme_uri( string $taxonomy )` — URI of a taxonomy's concept scheme.
- `WP_SKOS_Builder::collection_uri( string $group_slug )` — URI for a term-group collection.
- `WP_SKOS_Builder::vocab_property_uri( string $slug, string $source_site = '' )` — URI for a vocabulary property declaration. Built-in types (includes, container, synonym) use the stable cross-site namespace. Local custom types use home_url(). TC-delegated types use the source site's URL.
- `WP_SKOS_Builder::cache_key_concept( int $term_id, bool $authed )` — Transient key for a cached concept document, separate for viewers who can see hidden terms.
- `WP_SKOS_Builder::cache_key_scheme( string $taxonomy )` — Transient key for a cached scheme document.
- `WP_SKOS_Builder::flush_cache()` — Retire every cached concept and scheme document.
- `WP_SKOS_Builder::get_pref_labels( WP_Term $term )` — Build prefLabel map keyed by BCP 47 language tag.
- `WP_SKOS_Builder::get_related_terms( WP_Term $term, bool $authed = false, ?array $rel_data = null )` — All terms with any semantic relationship to $term, keyed by term_id. Excludes parent/child (WP hierarchy) — those are skos:broader/narrower.
- `WP_SKOS_Builder::get_typed_relations( WP_Term $term, bool $authed = false, ?array $rel_data = null )` — Relations organized by typed sub-property, for emitting wpskos:* triples.
- `WP_SKOS_Builder::get_close_matches( int $term_id )` — External KB URIs for skos:closeMatch.
- `WP_SKOS_Builder::get_locations( WP_Term $term )` — Physical locations for a concept, formatted as schema:Place JSON-LD objects.
- `WP_SKOS_Builder::build_concept( WP_Term $term, bool $authed = false )` — Build the full SKOS data array for a concept.
- `WP_SKOS_Builder::get_modified_date( WP_Term $term )` — Most recent post-modified time across the concept's archive, as ISO 8601 (GMT), or null when the archive has no published posts.
- `WP_SKOS_Builder::inject_modified( array $response, WP_Term $term )` — Inject a live dct:modified into a (possibly cached) concept response.
- `WP_SKOS_Builder::build_scheme( string $taxonomy )` — Build the SKOS ConceptScheme for a taxonomy.
- `WP_SKOS_Builder::build_collection( WP_Term $group, bool $authed = false )` — Build the SKOS Collection for a term group.
- `WP_SKOS_Builder::build_vocab_property( string $slug )` — Build the OWL property declaration for a vocabulary property.
- `WP_SKOS_Builder::jsonld_context()` — Standard @context for concept/scheme JSON-LD responses.
- `WP_SKOS_Builder::labels_to_jsonld( array $labels )` — Convert a [ lang => label ] map to JSON-LD language-tagged literal array.

### Skosmos REST API (`includes/class-skosmos-api.php`)

Skosmos REST API implementation.


**`WP_SKOS_Skosmos_API`**

- `WP_SKOS_Skosmos_API::register()` — Hook the API's rewrite rule, query var and request handler.
- `WP_SKOS_Skosmos_API::add_rewrite_rules()` — Route /skosmos/rest/v1/ requests to the API handler.
- `WP_SKOS_Skosmos_API::add_query_vars( array $vars )` — Register the query var that holds the API path.
- `WP_SKOS_Skosmos_API::dispatch()` — Route the request to the appropriate handler.
