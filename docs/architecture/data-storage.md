---
title: Data Storage
parent: Architecture
nav_order: 4
---

# Data Storage

WP-SKOS has no tables and writes no term or post meta. It stores its settings and caches in options and transients. Deleting the plugin removes all of them.

## Options

| Option | Value |
|---|---|
| `wpskos_settings` | `enabled_taxonomies` (the taxonomies exposed as vocabularies) and `default_language` (the language tag for labels when no multilingual plugin is active). |
| `wpskos_cache_generation` | A number that's part of every cache key. Raising it retires all cached documents at once, because one term's change can affect many documents. |
| `wpskos_export_lock_{md5(taxonomy)}` | Present while an export of that vocabulary is running, so only one runs at a time. Removed when the request ends, and treated as abandoned after 10 minutes. |

## Transients

| Key | Holds |
|---|---|
| `wpskos_cpt_{generation}_{term_id}` | A concept document. The same for every viewer, since hidden terms are left out for everyone. |
| `wpskos_sch_{generation}_{md5(taxonomy)}` | A concept scheme document. |

Both last one day.

## Meta that WP-SKOS reads

`_lang` term meta, when a multilingual plugin stores a term's language there, sets the language of the term's labels.
