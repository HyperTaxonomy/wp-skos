---
title: Home
nav_order: 1
---

# WP-SKOS

*Your taxonomies, as linked data*
{: .fs-6 .fw-300 }

Exposes WordPress taxonomies as SKOS concept schemes through concept URIs and a REST API that follows Skosmos's. Integrates with HyperTaxonomy for the full SKOS relationship model.

Part of the [HyperTaxonomy Suite](https://hypertaxonomy.org) of interoperable WordPress taxonomy plugins.

## Documentation map

- **[Concepts & Data Model](concept-uris.html)** — how terms and taxonomies map to SKOS; concept, scheme and collection URIs; content negotiation; OWL property declarations; and the full JSON-LD output gallery.
- **[Document Search](document-search.html)** — the concept-queryable document search endpoint, relevance ordering, pagination, and external peer-site documents (sourced via HyperTaxonomy).
- **[AI Agents & Export](ai-agents.html)** — the generated `llms.txt` guide and API catalog for agents and other clients, the whole-vocabulary export, and loading it into a triple store.
- **[Skosmos REST API](skosmos-rest-api.html)** — all endpoints at `/skosmos/rest/v1/`, for software written against Skosmos's API and other SKOS clients.
- **[HyperTaxonomy Integration](hypertaxonomy-integration.html)** — functions used, feature-flag gates, cache invalidation, and multilingual labels.
- **[Python Converter](converter.html)** — the SKOS → HyperTaxonomy import converter and its mapping config.

## Getting started

| Item | Version |
|------|---------|
| WordPress | 5.0+ |
| PHP | 7.4+ |
| Permalinks | Any structure other than plain |

1. Install from the [WordPress.org plugin directory](https://wordpress.org/plugins/wp-skos/), or download the zip from [GitHub Releases](https://github.com/HyperTaxonomy/wp-skos/releases), and activate it.
2. Go to **Settings → WP-SKOS** and check the taxonomies you want to expose. Only those are available through the API and concept URIs.
3. Set the **Default language tag**, used for labels when no multilingual plugin is active.

If concept URIs return 404 after uploading the files by hand, go to **Settings → Permalinks** and click **Save** to refresh the rewrite rules.

4. Try it: open `/wp-skos/scheme/{taxonomy}` for a scheme and its top concepts, `/skosmos/rest/v1/search?query={word}` to find a concept, or `/wp-skos/export/{taxonomy}.jsonld` for the whole vocabulary. All return JSON.

Deleting the plugin from the **Plugins** screen removes its settings and cached documents.

## License

GPLv2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
