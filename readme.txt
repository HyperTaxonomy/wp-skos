=== WP-SKOS ===
Tags: skos, linked data, taxonomy, vocabulary, semantic web, skosmos, rdf, json-ld
Contributors: eugenevv
Author: Eugene Weinstein
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes WordPress taxonomies as SKOS concept schemes via a Skosmos-compatible REST API and stable concept URIs.

== Description ==

WP-SKOS turns any WordPress taxonomy into a machine-readable SKOS vocabulary. It implements the Skosmos REST API so that Skosmos — and any other SKOS-aware tool — can browse, search, and dereference your taxonomies as concept schemes without any configuration on the client side.

Each term becomes an addressable `skos:Concept` at a stable URI (`/wp-skos/concept/{taxonomy}/{slug}`). Each taxonomy becomes a `skos:ConceptScheme` at `/wp-skos/scheme/{taxonomy}`. WordPress's native parent/child hierarchy maps to `skos:broader` / `skos:narrower`. A document search endpoint (`/wp-json/wp-skos/v1/posts`) accepts concept URIs and returns relevance-scored posts as JSON-LD, making the vocabulary queryable by RAG pipelines, AI agents, and any linked data client.

**Works standalone.** With only WordPress active, WP-SKOS exposes labels, hierarchy, notations, definitions (plain-text term descriptions map to `skos:definition`; descriptions containing HTML map to `skos:scopeNote`), and top concepts — the full read-only SKOS model for native WP taxonomy data.

**Enhanced with HyperTaxonomy.** When HyperTaxonomy is active, the full SKOS relationship model is populated:

* `skos:Collection` for term groups at `/wp-skos/collection/{group-slug}`, with `skos:member` listing member concepts and nested child-group collections (hidden groups return 404)
* `skos:broaderTransitive` / `skos:narrowerTransitive` listing all ancestor / descendant concepts
* `wpskos:includes` / `wpskos:container` / `wpskos:synonym` typed sub-properties for direction-aware clients
* `owl:deprecated` + `dct:isReplacedBy` for redirect terms
* `skos:related` for cross-branch inclusions, synonyms, and custom semantic relationship types
* `skos:closeMatch` for external vocabulary identifiers (Wikidata, GeoNames, LCSH, and any other link type defined in TermConnect)
* `schema:location` — physical locations as `schema:Place` objects (street address, coordinates) when As the Crow Flies is active
* Custom relationship types registered in HyperTaxonomy become OWL sub-properties with stable URIs
* Hidden term filtering — unauthorized users receive 404 for hidden concepts, mirroring HyperTaxonomy's REST behavior

**Multilingual labels.** When Polylang or WPML is active, translation-linked terms contribute `skos:prefLabel` values in their respective languages.

= Skosmos REST API =

The following endpoints are available at `/skosmos/rest/v1/`:

* `GET /vocabularies` — list all enabled vocabularies
* `GET /{vocab}/` — vocabulary metadata and concept scheme info
* `GET /{vocab}/topConcepts` — root-level concepts (no WP parent)
* `GET /{vocab}/lookup?label=X&lang=en` — find concepts by label
* `GET /{vocab}/data?uri=X` — full JSON-LD for a concept or scheme URI
* `GET /search?query=X&vocab={v}&lang=en` — label search across one or all vocabularies

`{vocab}` is a WP taxonomy slug. Only taxonomies explicitly enabled in Settings → WP-SKOS are exposed.

= Document Search Endpoint =

`GET /wp-json/wp-skos/v1/posts?concept=URI&concept=URI&operator=AND|OR&page=1&per_page=20`

Accepts SKOS concept URIs from this site, resolves them to term IDs, applies HyperTaxonomy expansion (inclusions, hierarchy, group-based) via `htax_get_expanded_term_ids()` if available, and returns matching posts as JSON-LD (`schema:Article`). When HyperTaxonomy's search index is present, results are ordered by descending relevance strength; otherwise by date. Responses carry RFC 5988 `Link` pagination headers (self/first/prev/next/last). When HyperTaxonomy is active, wp-skos appends external peer-site `foaf:Document` results sourced through HyperTaxonomy's `htax_get_term_external_posts()` (any provider, e.g. TermConnect, populates it via the `htax_term_external_posts` filter).

= Concept URIs =

* `/wp-skos/concept/{taxonomy}/{term-slug}` — `skos:Concept` (JSON-LD, or 303 redirect to term archive for browsers)
* `/wp-skos/scheme/{taxonomy-slug}` — `skos:ConceptScheme` JSON-LD
* `/wp-skos/collection/{group-slug}` — `skos:Collection` for a HyperTaxonomy term group (JSON-LD only; requires HyperTaxonomy)
* `/wp-skos/vocab/{slug}` — OWL property declaration for built-in and custom relationship types

If a term is renamed, its URI changes and callers holding the old URI receive a 404. The term's WordPress archive URL is exposed as `rdfs:seeAlso` and is not the authoritative concept URI.

= SKOS → HyperTaxonomy Converter =

A Python script (`skos-converter/convert.py`) is included for one-time vocabulary migrations. It parses a SKOS file (Turtle, RDF/XML, or JSON-LD) and produces a HyperTaxonomy import JSON file plus a migration report.

`python convert.py input.ttl --taxonomy post_tag --output ht-import.json`

The report lists every `skos:related` pair that needs a manual inclusion direction decision, every custom property URI without a mapping, and every Collection without a target group slug. A YAML mapping config maps custom property URIs to HyperTaxonomy relationship type slugs and Collection URIs to HyperTaxonomy term group slugs.

= Why SKOS on WordPress? =

WordPress powers roughly 43% of the web. Its taxonomy system is already a controlled vocabulary — terms, hierarchy, labels, and descriptions are all there. WP-SKOS adds the standard interface that makes that vocabulary interoperable with the broader knowledge graph ecosystem: Skosmos vocabulary browsers, SKOS-aware applications, and AI tooling that benefits from stable concept URIs, machine-readable semantic relationships, and a concept-queryable document search endpoint.

HyperTaxonomy's relationship model — inclusions, synonyms, custom types, external identifiers — maps cleanly onto SKOS because SKOS was designed for exactly this kind of practical vocabulary management. WP-SKOS is the bridge between the two ecosystems.

== Installation ==

1. Upload the `wp-skos` folder to `/wp-content/plugins/`.
2. Activate the plugin from the Plugins screen.
3. Go to **Settings → WP-SKOS** and check the taxonomies you want to expose.
4. Save. The Skosmos API and concept URIs are immediately available.

Rewrite rules are flushed automatically on activation and deactivation. If concept URIs return 404 after manual file uploads, go to **Settings → Permalinks** and click Save to flush rewrite rules.

= Requirements =

* WordPress 5.0 or later
* PHP 7.4 or later
* Pretty permalinks enabled (any structure other than plain)

= Optional =

* **HyperTaxonomy** — enables `skos:related`, `skos:closeMatch`, `schema:location`, deprecated terms, term groups, hidden term filtering, and custom relationship type sub-properties
* **TermConnect** — populates external identifier data consumed by `skos:closeMatch`, and contributes external peer-site posts to the document search as `foaf:Document` (routed through HyperTaxonomy's external-posts API; requires HyperTaxonomy)
* **As the Crow Flies** — populates physical location data consumed by `schema:location` (requires HyperTaxonomy with the **Share list of locations** feature flag enabled per taxonomy)
* **Polylang** or **WPML** — enables multilingual `skos:prefLabel` values

= Python Converter =

Requires Python 3.8+ and `rdflib`. Install dependencies:

`pip install -r skos-converter/requirements.txt`

PyYAML is optional; the converter includes a minimal YAML fallback for mapping config files.

== Frequently Asked Questions ==

= Which response format does the `data` endpoint return? =

JSON-LD (`application/ld+json`). All other Skosmos API endpoints return Skosmos-native JSON (`application/json`). Concept and scheme URI handlers also return JSON-LD, with a 303 redirect to the term archive when the request prefers `text/html`.

= How do I point Skosmos at my site? =

In your Skosmos `config.ttl`, declare a vocabulary with `rest:remoteVocab` pointing to `https://yoursite.com/skosmos/rest/v1/{taxonomy-slug}/`. The WP-SKOS endpoints implement the Skosmos REST API format that Skosmos expects for remote vocabularies.

= Does this plugin write anything to the database? =

No custom tables. It reads from WP core taxonomy tables and HyperTaxonomy tables when present. Concept responses are cached as WordPress transients (keyed by term ID and authentication state) and invalidated automatically when terms change. One option row (`wpskos_settings`) stores the plugin settings. Both the settings option and all transients are deleted automatically when the plugin is uninstalled via the Plugins screen.

= What happens to concept URIs when a term is renamed or deleted? =

URIs are based on the term slug. Renaming a term changes its URI; the old URI returns 404. Deleting a term returns 404. WP-SKOS does not mint persistent opaque URIs — slug-based URIs are human-readable and conventional in linked data contexts.

= What does `skos:related` mean in a WP-SKOS concept document? =

It covers all HyperTaxonomy semantic relationships in both directions: cross-branch inclusions, containers, synonyms, and custom relationship types. Direction and type are recoverable via typed sub-properties (`wpskos:includes`, `wpskos:container`, `wpskos:synonym`, and custom `{home_url}/wp-skos/vocab/{slug}` properties). Clients that only understand `skos:related` treat all of these as undirected semantic associations.

= When does `dct:modified` appear on a concept? =

It is the most recent modification time of any published post in the concept's archive (the posts assigned to the term, plus its HyperTaxonomy expansion when HyperTaxonomy is active), emitted as ISO 8601. This reflects how fresh the concept's content is. The triple is absent when the archive has no published posts.

= How are document search results ordered? =

When HyperTaxonomy is active and its search index table exists, results are ordered by descending summed relevance strength from the index over the expanded concept term set; posts absent from the index follow in date order. Without the index, results are ordered by `post_date DESC`. Pagination uses RFC 5988 `Link` headers, and the body also reports `total`, `page`, `per_page`, and `total_pages`.

= What is `schema:location` and when does it appear? =

When HyperTaxonomy and As the Crow Flies are both active, and the **Share list of locations** per-taxonomy feature flag is enabled in HyperTaxonomy settings, concept documents include a `schema:location` array of `schema:Place` objects. Each place carries a `schema:name`, a `schema:PostalAddress` (street, city, region, postal code, country), and a `schema:GeoCoordinates` (latitude and longitude) when the data is available. The flag controls which taxonomies expose their location data externally — ATCF's own per-taxonomy enablement controls where admins can enter location data.

= How does the Wikidata URI work? =

TermConnect can store Wikidata links using the document URL (`https://www.wikidata.org/wiki/Q…`). WP-SKOS emits the RDF entity URI (`http://www.wikidata.org/entity/Q…`) as the `skos:closeMatch` value and retains the document URL as `rdfs:seeAlso foaf:Document`. This follows Wikidata's own linked data practice.

= Why `skos:closeMatch` and not `skos:exactMatch` for external identifiers? =

HyperTaxonomy cannot verify mapping quality, and custom link types include encyclopedias, databases, and authority files of varying precision. `skos:closeMatch` is the conservative, correct choice; it signals a close but not necessarily exact mapping. Sites with high-confidence authority file alignments can strengthen individual mappings using their own vocabulary.

= Can I use the converter for a one-time import into an existing taxonomy? =

Yes. The converter produces a HyperTaxonomy import JSON file that maps SKOS concepts to WP terms, with hierarchy, relationships, external identifiers, redirects, and group memberships. `skos:related` pairs are intentionally not imported because HyperTaxonomy requires a direction decision (A includes B, or B includes A) that cannot be inferred from the symmetric SKOS property alone — these are listed in the migration report for manual review.

== Screenshots ==

1. Settings — selecting which taxonomies are exposed as SKOS concept schemes and setting the default label language
2. A term returned as JSON-LD SKOS data at its concept URI
3. The Skosmos REST API returning a concept scheme with broader/narrower navigation
4. Concept-aware document search results — local articles and peer documents for a concept URI

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
