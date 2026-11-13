---
title: Python Converter
parent: User Guide
nav_order: 3
---

# Python converter

Converts a SKOS file (Turtle, RDF/XML, or JSON-LD) into files you can import into HyperTaxonomy and TermConnect.

```bash
pip install -r skos-converter/requirements.txt
python skos-converter/convert.py input.ttl --taxonomy category --output ht-import.json
```

Other flags: `--lang en` (preferred label language), `--links-output file.json` (where to write the TermConnect file), and `--report report.txt` (write the migration report to a file instead of stdout).

## What it produces

| Output | Contains | Import it at |
|---|---|---|
| `ht-import.json` | Terms and hierarchy, redirects, group memberships, mapped relationships, and any custom relationship types they use | **Settings > HyperTaxonomy > Export / Import > Import** |
| `ht-import-termconnect.json` (only when there are identifiers) | External identifiers from `skos:exactMatch` and `skos:closeMatch` | **Settings > TermConnect > Export / Import**, *after* the HyperTaxonomy file, since the terms must exist first |
| Migration report | Everything that needs a decision (see below) | — |

| SKOS | Imported as |
|---|---|
| `skos:prefLabel`, `skos:definition` / `skos:scopeNote` | Term name, description |
| `skos:notation` (or the label) | Term slug |
| `skos:broader` | Parent term. The target taxonomy must be hierarchical; `post_tag` is not, so its hierarchy isn't imported |
| `owl:deprecated` + `dct:isReplacedBy` | Redirect. A chain of replacements points straight at its final target |
| `skos:Collection` with a `collections` mapping | Term group, with `skos:member` concepts as its members |
| Custom properties with a `relationships` mapping | Relationships of that type |
| `skos:exactMatch`, `skos:closeMatch` | TermConnect external identifiers |

Importing with **Update existing terms** checked replaces the name, description, and parent of any term or group whose slug already exists.

## Mapping config

Optional YAML, passed with `--mapping mapping.yaml`:

```yaml
relationships:
  "http://example.org/vocab/relatedTo": "related-to"
  "http://example.org/vocab/seeAlsoInternal": ~   # ~ (null) = skip silently instead of reporting as unmapped

collections:
  "http://example.org/collections/regions": "regions-group"

link_types:
  "https://example.com/entry/": "my-link-type"
```

**`relationships`** maps a property to a relationship type slug (lowercase letters, digits, and hyphens). A type that doesn't exist yet is created by the import. Mapping a property to `~` (or leaving its value blank) tells the converter to skip it silently — distinct from omitting the key, which reports the property as unmapped. Property keys may be written as full URIs or as `skos:` / `owl:` / `rdfs:` / `dct:` CURIEs.

`skos:related` is a special case and is **not** affected by this mapping: it is always reported for manual review, because HyperTaxonomy needs a direction decision (A includes B, or B includes A) that cannot be inferred from the symmetric SKOS property.

**`collections`** maps a `skos:Collection` to a term group slug. The group is created if it doesn't exist.

**`link_types`** maps a URI prefix to a TermConnect link type slug, for identifiers beyond TermConnect's built-in types, which are recognized automatically (including the `http://` and linked-data forms of their URIs, such as `http://www.wikidata.org/entity/Q42`). A custom link type must already exist in TermConnect.

To show imported identifiers, enable the link types in TermConnect and the taxonomy in its settings. A term holds one identifier per link type, so `skos:exactMatch` takes priority over `skos:closeMatch`.

## Migration report

The converter prints a report to stdout (or writes it with `--report`) listing what wasn't imported or needs a decision:

- `skos:related` pairs that need a manual direction decision
- Custom properties with no mapping, and Collections with no `collections` entry
- Identifiers matching no link type, or a second identifier for a link type the term already has
- Nested Collections, which must be set up by hand
- Concepts with several `skos:broader` targets (the first is used), `skos:broader` or redirect loops, and deprecated concepts with no replacement
- References to concepts the file never declares
