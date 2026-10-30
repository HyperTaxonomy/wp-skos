#!/usr/bin/env python3
"""
SKOS → HyperTaxonomy import converter.

Parses a SKOS file (Turtle, RDF/XML, or JSON-LD) and produces:
  1. An HT import JSON file ready for HyperTaxonomy's import feature.
  2. A migration report listing items that need manual attention.

Usage:
  python convert.py input.ttl --taxonomy post_tag --output ht-import.json
  python convert.py input.ttl --mapping mapping.yaml --output ht-import.json --report report.txt

Mapping config (YAML, optional):
  relationships:
    https://example.com/vocab/co_cited_with: co-cited-with
    skos:related: ~          # null = not imported, reported for manual direction decision
  collections:
    https://example.com/groups/frontend: frontend-tools
"""

import argparse
import json
import re
import sys
import unicodedata
from pathlib import Path

try:
    from rdflib import Graph, Namespace, URIRef, Literal, RDF
    from rdflib.namespace import SKOS, OWL, RDFS, DC, DCTERMS
except ImportError:
    sys.exit("rdflib is required: pip install rdflib")

try:
    import yaml
    HAS_YAML = True
except ImportError:
    HAS_YAML = False

SKOS_NS  = Namespace("http://www.w3.org/2004/02/skos/core#")
OWL_NS   = Namespace("http://www.w3.org/2002/07/owl#")

# Sentinel distinguishing "key absent from mapping" from "key explicitly mapped
# to null" (the latter means skip silently rather than report as unmapped).
_MISSING = object()


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def slugify(text: str) -> str:
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode("ascii")
    text = re.sub(r"[^\w\s-]", "", text.lower())
    text = re.sub(r"[\s_-]+", "-", text).strip("-")
    return text or "term"


def best_label(graph: Graph, uri: URIRef, lang: str = "en") -> str:
    """Return the best skos:prefLabel for a URI, preferring the given language."""
    labels = {}
    for o in graph.objects(uri, SKOS_NS.prefLabel):
        if isinstance(o, Literal):
            labels[str(o.language or "")] = str(o)
    return labels.get(lang) or labels.get("") or next(iter(labels.values()), str(uri).split("/")[-1].split("#")[-1])


def _strip_quotes(s: str) -> str:
    """Strip one matching pair of surrounding single/double quotes, if present."""
    if len(s) >= 2 and s[0] == s[-1] and s[0] in ("'", '"'):
        return s[1:-1]
    return s


def uri_to_slug(uri: str, used: set) -> str:
    """Generate a unique slug from a URI."""
    fragment = uri.split("/")[-1].split("#")[-1]
    base = slugify(fragment) or slugify(uri.split("//")[-1].replace("/", "-"))
    slug = base
    n = 1
    while slug in used:
        slug = f"{base}-{n}"
        n += 1
    used.add(slug)
    return slug


# ---------------------------------------------------------------------------
# Converter
# ---------------------------------------------------------------------------

class SKOSConverter:

    def __init__(self, input_path: str, taxonomy: str, mapping_config: dict, lang: str = "en"):
        self.taxonomy     = taxonomy
        self.lang         = lang
        self.rel_map      = mapping_config.get("relationships", {})
        self.coll_map     = mapping_config.get("collections", {})

        self.g = Graph()
        self.g.parse(input_path)

        self.report_lines: list[str] = []
        self._slug_pool: set          = set()
        self._uri_to_slug: dict       = {}
        # Populated in convert() once every skos:Concept URI is known; used by
        # _concept_slug_for() to detect dangling references.
        self._concept_uris: set       = set()

    def _slug_for(self, uri: URIRef) -> str:
        key = str(uri)
        if key not in self._uri_to_slug:
            self._uri_to_slug[key] = uri_to_slug(key, self._slug_pool)
        return self._uri_to_slug[key]

    def _concept_slug_for(self, uri: URIRef, *, context: str) -> "str | None":
        """Like _slug_for(), but only for a URI that must resolve to a declared
        skos:Concept (a broader/relationship/redirect/membership target).

        Returns None and records a dangling-reference warning in the migration
        report when the URI was never declared `rdf:type skos:Concept` in the
        source graph — e.g. a partial/incomplete SKOS export — instead of
        silently minting a slug for a reference the import would never resolve.
        """
        key = str(uri)
        if key not in self._concept_uris:
            self.report_lines.append(
                f"DANGLING REFERENCE: {context} refers to undeclared concept {key}"
            )
            return None
        return self._slug_for(uri)

    def convert(self) -> dict:
        g = self.g

        # Collect all skos:Concept URIs.
        concepts = list(g.subjects(RDF.type, SKOS_NS.Concept))
        self._concept_uris = set(str(c) for c in concepts)

        # First pass: assign slugs.
        for c in concepts:
            notation = next(g.objects(c, SKOS_NS.notation), None)
            if notation:
                slug = slugify(str(notation))
            else:
                label = best_label(g, c, self.lang)
                slug  = slugify(label)

            if not slug:
                # No notation/label to derive a slug from — fall back to the URI.
                self._slug_for(c)
                continue

            if slug not in self._slug_pool:
                self._slug_pool.add(slug)
                self._uri_to_slug[str(c)] = slug
            else:
                # Collision with another concept's intended slug: append a numeric
                # suffix to it (the same collision-handling uri_to_slug() uses for
                # its own base) instead of discarding the intended slug entirely
                # and deriving an unrelated one from the URI fragment.
                base = slug
                n = 1
                while slug in self._slug_pool:
                    slug = f"{base}-{n}"
                    n += 1
                self._slug_pool.add(slug)
                self._uri_to_slug[str(c)] = slug

        terms         = []
        relationships = []
        redirects     = []
        term_links    = []

        for c in concepts:
            slug  = self._slug_for(c)
            label = best_label(g, c, self.lang)

            description = ""
            for prop in (SKOS_NS.definition, SKOS_NS.scopeNote):
                defn = next(g.objects(c, prop), None)
                if defn:
                    description = str(defn)
                    break

            parent_slug = ""
            for broader in g.objects(c, SKOS_NS.broader):
                resolved = self._concept_slug_for(broader, context=f"{slug}'s skos:broader")
                if resolved is not None:
                    parent_slug = resolved
                    break  # HT supports one WP parent; use first broader that resolves

            term = {
                "taxonomy":    self.taxonomy,
                "name":        label,
                "slug":        slug,
                "description": description,
                "parent":      parent_slug,
            }

            # owl:deprecated + dct:isReplacedBy → redirect.
            if (c, OWL_NS.deprecated, Literal(True)) in g:
                replaced_by = next(g.objects(c, DCTERMS.isReplacedBy), None)
                if replaced_by:
                    to_slug = self._concept_slug_for(replaced_by, context=f"{slug}'s dct:isReplacedBy")
                    if to_slug is not None:
                        redirects.append({
                            "from": slug,
                            "to":   to_slug,
                        })
                        term["deprecated"] = True

            # skos:exactMatch → term links.
            for match in g.objects(c, SKOS_NS.exactMatch):
                term_links.append({
                    "term": slug,
                    "url":  str(match),
                })

            # skos:closeMatch → term links.
            for match in g.objects(c, SKOS_NS.closeMatch):
                term_links.append({
                    "term": slug,
                    "url":  str(match),
                })

            terms.append(term)

            # Relationships from mapping config.
            all_props = set(p for _, p, _ in g.triples((c, None, None)))
            for prop in all_props:
                prop_str = str(prop)
                prop_curie = self._to_curie(prop_str)

                # skos:related — always reported for manual direction decision.
                if prop in (SKOS_NS.related,):
                    for target in g.objects(c, prop):
                        target_slug = self._concept_slug_for(target, context=f"{slug}'s skos:related")
                        if target_slug is not None:
                            self.report_lines.append(
                                f"SKOS:RELATED (manual direction needed): {slug} ↔ {target_slug}"
                            )
                    continue

                # Custom properties that have a mapping. Use a sentinel so an
                # explicit null mapping (skip silently) is distinguished from an
                # absent key (report as unmapped).
                mapped = self.rel_map.get(prop_str, _MISSING)
                if mapped is _MISSING:
                    mapped = self.rel_map.get(prop_curie, _MISSING)

                if mapped is None:  # explicitly mapped to null — skip silently.
                    continue

                if mapped is _MISSING:
                    # Check if it's an unmapped custom property (not a built-in SKOS/OWL prop).
                    # Every property this tuple could exclude already lives in one of the
                    # six standard namespaces _is_custom_prop() excludes, so it can never
                    # actually be reached — _is_custom_prop(prop_str) is already False for
                    # any prop_str that would also match here.
                    if self._is_custom_prop(prop_str):
                        self.report_lines.append(
                            f"UNMAPPED PROPERTY: {prop_str} on term {slug} — add to mapping config to import"
                        )
                    continue

                for target in g.objects(c, prop):
                    if isinstance(target, URIRef):
                        to_slug = self._concept_slug_for(target, context=f"{slug}'s {prop_curie} relationship")
                        if to_slug is not None:
                            relationships.append({
                                "from": slug,
                                "to":   to_slug,
                                "type": str(mapped),
                            })

        # Collections → group memberships.
        group_memberships = []
        collections = list(g.subjects(RDF.type, SKOS_NS.Collection))
        collection_uris = set(str(coll) for coll in collections)
        for coll in collections:
            coll_str  = str(coll)
            group_slug = self.coll_map.get(coll_str)
            if group_slug is None:
                label = best_label(g, coll, self.lang)
                self.report_lines.append(
                    f"UNMAPPED COLLECTION: {coll_str} ({label!r}) — add to mapping config to import as group"
                )
                continue

            for member in g.objects(coll, SKOS_NS.member):
                if not isinstance(member, URIRef):
                    continue
                member_str = str(member)
                if member_str in collection_uris:
                    # Nested collection: the HT import format only supports flat
                    # term-to-group membership, with no way to express a
                    # group-of-groups relationship — flattening it would silently
                    # mis-import the sub-collection's own members as if they
                    # belonged directly to this group.
                    self.report_lines.append(
                        f"NESTED COLLECTION: {coll_str} contains sub-collection {member_str} — "
                        "not imported; nested groups must be set up manually in HyperTaxonomy"
                    )
                    continue
                member_slug = self._concept_slug_for(member, context=f"collection {coll_str}'s skos:member")
                if member_slug is not None:
                    group_memberships.append({
                        "term":  member_slug,
                        "group": str(group_slug),
                    })

        return {
            "version":          "1.0",
            "terms":            terms,
            "relationships":    relationships,
            "redirects":        redirects,
            "term_links":       term_links,
            "group_memberships": group_memberships,
        }

    def _to_curie(self, uri: str) -> str:
        prefixes = {
            "http://www.w3.org/2004/02/skos/core#": "skos:",
            "http://www.w3.org/2002/07/owl#":       "owl:",
            "http://www.w3.org/2000/01/rdf-schema#": "rdfs:",
            "http://purl.org/dc/terms/":            "dct:",
        }
        for ns, prefix in prefixes.items():
            if uri.startswith(ns):
                return prefix + uri[len(ns):]
        return uri

    def _is_custom_prop(self, uri: str) -> bool:
        standard_ns = (
            "http://www.w3.org/2004/02/skos/core#",
            "http://www.w3.org/2002/07/owl#",
            "http://www.w3.org/2000/01/rdf-schema#",
            "http://www.w3.org/1999/02/22-rdf-syntax-ns#",
            "http://purl.org/dc/terms/",
            "http://purl.org/dc/elements/1.1/",
        )
        return not any(uri.startswith(ns) for ns in standard_ns)


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def load_mapping(path: str) -> dict:
    if not HAS_YAML:
        with open(path) as f:
            # Minimal YAML subset: key: value lines; null represented as ~.
            out = {"relationships": {}, "collections": {}}
            section = None
            for line in f:
                line = line.rstrip()
                if line.strip().startswith("#") or not line.strip():
                    continue
                if line == "relationships:":
                    section = "relationships"
                elif line == "collections:":
                    section = "collections"
                elif section and line.startswith("  "):
                    k, _, v = line.strip().partition(": ")
                    # Strip surrounding quotes: the documented example mapping file
                    # quotes its keys (and may quote values), and without PyYAML the
                    # quote characters would otherwise become part of the stored key
                    # so lookups by prop_str/prop_curie would never match.
                    k = _strip_quotes(k.strip())
                    v = _strip_quotes(v.strip())
                    out[section][k] = None if v == "~" else v or None
            return out
    with open(path) as f:
        return yaml.safe_load(f) or {}


def main():
    # Console output can hit a narrower codepage than UTF-8 (e.g. cp1252 on
    # Windows), which can't encode report characters like "↔" and would
    # otherwise crash after the import JSON was already written successfully.
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")

    parser = argparse.ArgumentParser(description="Convert SKOS vocabulary to HyperTaxonomy import JSON.")
    parser.add_argument("input",          help="SKOS input file (Turtle, RDF/XML, or JSON-LD)")
    parser.add_argument("--taxonomy",     default="post_tag", help="Target WP taxonomy slug (default: post_tag)")
    parser.add_argument("--lang",         default="en",       help="Preferred language for labels (default: en)")
    parser.add_argument("--mapping",      default="",         help="Mapping config file (YAML)")
    parser.add_argument("--output",       default="ht-import.json", help="Output HT import JSON file")
    parser.add_argument("--report",       default="",         help="Migration report output file (default: stdout)")
    args = parser.parse_args()

    mapping = load_mapping(args.mapping) if args.mapping else {}

    converter = SKOSConverter(args.input, args.taxonomy, mapping, args.lang)
    result    = converter.convert()

    with open(args.output, "w", encoding="utf-8") as f:
        json.dump(result, f, ensure_ascii=False, indent=2)

    print(f"Wrote {len(result['terms'])} terms to {args.output}")

    report_text = "\n".join(converter.report_lines) if converter.report_lines else "No issues found."

    if args.report:
        with open(args.report, "w", encoding="utf-8") as f:
            f.write(report_text + "\n")
        print(f"Report written to {args.report}")
    else:
        if converter.report_lines:
            print("\n--- Migration Report ---")
            print(report_text)


if __name__ == "__main__":
    main()
