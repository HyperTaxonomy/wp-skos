---
title: Python Converter
nav_order: 6
---

# Python converter

Converts a SKOS file (Turtle, RDF/XML, or JSON-LD) to a HyperTaxonomy import JSON file.

```bash
pip install -r skos-converter/requirements.txt
python skos-converter/convert.py input.ttl --taxonomy post_tag --output ht-import.json
```

Other flags: `--lang en` (preferred label language) and `--report report.txt` (write the migration report to a file instead of stdout).

Optional YAML mapping config for custom property URIs and Collection → group slug mappings:

```yaml
relationships:
  "http://example.org/vocab/relatedTo": "related"
  "http://example.org/vocab/seeAlsoInternal": ~   # ~ (null) = skip silently instead of reporting as unmapped

collections:
  "http://example.org/collections/regions": "regions-group"
```

Pass the config with `--mapping mapping.yaml`. Mapping a custom property to `~` (YAML null) tells the converter to skip it silently — distinct from omitting the key, which reports the property as unmapped in the migration report. Property keys may be written as full URIs or as `skos:` / `owl:` / `rdfs:` / `dct:` CURIEs.

`skos:related` is a special case and is **not** affected by this mapping: it is always reported for manual review (see below) regardless of any entry in `relationships`, because HyperTaxonomy needs a direction decision that cannot be inferred from the symmetric SKOS property. Mapping `skos:related` in the config has no effect.

External identifiers are imported automatically: both `skos:exactMatch` and `skos:closeMatch` on a concept become HyperTaxonomy term links (`term_links`) on the corresponding term.

The converter prints a migration report to stdout (or writes it to a file with `--report report.txt`) listing:

- `skos:related` pairs that need a manual direction decision (A includes B, or B includes A)
- Custom property URIs with no mapping
- Collections with no `collections` entry

`skos:related` pairs are intentionally excluded from the import because HyperTaxonomy requires a direction decision that cannot be inferred from the symmetric SKOS property.
