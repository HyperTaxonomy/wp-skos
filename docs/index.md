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

- **[User Guide](user-guide/)** — for site owners: what WP-SKOS publishes, sharing vocabularies with AI agents and triple stores, and importing SKOS files.
- **[Developer Reference](developer-reference/)** — for integrators: concept URIs and JSON-LD, the document search and Skosmos APIs, filters, and HyperTaxonomy integration.
- **[Architecture](architecture/)** — for contributors: file structure and loading, function and hook references, and data storage.

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
