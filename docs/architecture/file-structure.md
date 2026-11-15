---
title: File Structure & Loading
parent: Architecture
nav_order: 1
---

# File Structure & Loading

## File Structure

```
wp-skos/
├── .distignore                                 # Files left out of the WordPress.org deploy
├── .gitignore                                  # Files left out of the repository
├── LICENSE                                     # GPLv2
├── README.md                                   # Overview and documentation links
├── readme.txt                                  # WordPress.org readme
├── uninstall.php                               # Uninstall handler
├── wp-skos.php                                 # Main plugin file: header, constants and bootstrap
├── .wordpress-org/                             # WordPress.org listing assets: icons, banners, screenshots (not in the zip)
├── docs/                                       # This documentation (Just-the-Docs)
├── includes/                                   
│   ├── class-agent-discovery.php               # Tells AI agents and other API clients how to use this site's vocabularies.
│   ├── class-concept-uri.php                   # Handles stable concept, scheme, and vocabulary property URIs.
│   ├── class-document-search.php               # Document search endpoint.
│   ├── class-export.php                        # Serves a whole vocabulary as one JSON-LD file.
│   ├── class-settings.php                      # Admin settings page.
│   ├── class-skos-builder.php                  # Builds SKOS data structures from WordPress taxonomy and term data.
│   └── class-skosmos-api.php                   # Skosmos REST API implementation.
├── languages/                                  # Translation template and translations; .mo and .json are build output
├── skos-converter/                             
│   ├── convert.py                              # SKOS → HyperTaxonomy import converter.
│   └── requirements.txt                        # Python dependencies
└── utils/                                      # Packaging, translation and local deploy scripts (not in the zip)
```

## Loading Sequence

### Main file (`wp-skos.php`)

1. Define `WPSKOS_VERSION`, `WPSKOS_DIR` and `WPSKOS_URL`, plus `WPSKOS_VOCAB_NS`, the namespace for the built-in relationship properties, and `WPSKOS_SKOSMOS_PREFIX`, the Skosmos API path.
2. Load translations on `init`.
3. Require the seven classes in `includes/`.
4. Call `wpskos_init()` on `plugins_loaded`, which calls `register()` on each class.
5. Filter `redirect_canonical`, so WordPress doesn't redirect concept URIs and API paths to a version with a trailing slash.

### How requests reach WP-SKOS

- **Rewrite rules** route concept, scheme, collection and vocabulary property URIs and the Skosmos API to handlers on `template_redirect`.
- **REST API:** document search is a REST route, `wp-skos/v1/posts`.
- **Requests WordPress would answer with a 404:** `llms.txt`, the API catalog and the vocabulary export are served on `template_redirect` only in that case, so a real file or another plugin's response takes priority.

### Activation and deactivation

- **Activation** (`wpskos_activate()`) adds the rewrite rules and flushes them.
- **Deactivation** (`wpskos_deactivate()`) deletes the stored rewrite rules, so WordPress rebuilds them without WP-SKOS's on the next request.
