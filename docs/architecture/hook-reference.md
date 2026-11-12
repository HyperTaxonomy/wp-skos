---
title: Hook Reference
parent: Architecture
nav_order: 3
---

# Hook Reference

Where WP-SKOS registers its callbacks, and the hooks it fires for other code. This page is generated from the code. Hook names that are built at runtime are shown as the PHP expression that builds them. For how to use the hooks WP-SKOS fires, see the endpoint pages, starting from the [documentation home](../).

## Callbacks registered

### Plugin root

**`wp-skos.php`**

| Hook | Callback | Priority |
|---|---|---|
| `init` | `*(anonymous function)*` | 10 |
| `plugins_loaded` | `wpskos_init` | 10 |
| `'plugin_action_links_' . plugin_basename( __FILE__ )` | `wpskos_action_links` | 10 |
| `redirect_canonical` | `wpskos_disable_canonical_redirect` | 10 |

### Includes

**`includes/class-agent-discovery.php`**

| Hook | Callback | Priority |
|---|---|---|
| `template_redirect` | `WP_SKOS_Agent_Discovery::dispatch` | 1 |

**`includes/class-concept-uri.php`**

| Hook | Callback | Priority |
|---|---|---|
| `init` | `WP_SKOS_Concept_URI::add_rewrite_rules` | 10 |
| `query_vars` | `WP_SKOS_Concept_URI::add_query_vars` | 10 |
| `template_redirect` | `WP_SKOS_Concept_URI::dispatch` | 10 |

**`includes/class-document-search.php`**

| Hook | Callback | Priority |
|---|---|---|
| `rest_api_init` | `WP_SKOS_Document_Search::register_route` | 10 |
| `posts_pre_query` | `$skip_query` | PHP_INT_MAX |
| `posts_pre_query` (remove filter) | `$skip_query` | PHP_INT_MAX |

**`includes/class-export.php`**

| Hook | Callback | Priority |
|---|---|---|
| `template_redirect` | `WP_SKOS_Export::dispatch` | 1 |

**`includes/class-settings.php`**

| Hook | Callback | Priority |
|---|---|---|
| `admin_menu` | `WP_SKOS_Settings::add_menu` | 10 |
| `admin_init` | `WP_SKOS_Settings::register_settings` | 10 |

**`includes/class-skos-builder.php`**

| Hook | Callback | Priority |
|---|---|---|
| `$wpskos_hook` | `WP_SKOS_Builder::flush_cache` | 10 |

**`includes/class-skosmos-api.php`**

| Hook | Callback | Priority |
|---|---|---|
| `init` | `WP_SKOS_Skosmos_API::add_rewrite_rules` | 10 |
| `query_vars` | `WP_SKOS_Skosmos_API::add_query_vars` | 10 |
| `template_redirect` | `WP_SKOS_Skosmos_API::dispatch` | 10 |

## Hooks fired

Actions and filters WP-SKOS fires for other code to use.

| Hook | Type | File | Description |
|---|---|---|---|
| `wpskos_serve_root_llms_txt` | filter | `includes/class-agent-discovery.php` | Filters whether the guide is also served at the site root, /llms.txt. |
| `wpskos_llms_txt` | filter | `includes/class-agent-discovery.php` | Filters the generated llms.txt guide. |
| `wpskos_allow_export` | filter | `includes/class-export.php` | Filters whether a vocabulary can be exported as one file. |
| `wpml_get_element_translations` | filter | `includes/class-skos-builder.php` | — |
