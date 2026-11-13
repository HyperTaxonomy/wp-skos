---
title: Filters
parent: Developer Reference
nav_order: 4
---

# Filters

Filters WP-SKOS applies, for changing the [AI agent guide and export](../user-guide/ai-agents.html).

| Filter | Purpose |
|---|---|
| `wpskos_llms_txt` | Change the generated guide's text. |
| `wpskos_serve_root_llms_txt` | Return `false` to stop serving `/llms.txt`, leaving only `/wp-skos/llms.txt`. |
| `wpskos_allow_export` | Return `false` to turn off the whole-vocabulary export, for all taxonomies or for one. This may suit a very large taxonomy on modest hosting. |

```php
add_filter( 'wpskos_llms_txt', function ( $guide ) {
	return $guide . "\n## Licensing\n\n- [Reuse terms](https://example.com/license/): How this vocabulary may be reused.\n";
} );
```
