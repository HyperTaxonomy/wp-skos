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
    return labels.get(lang) or labels.get("") or next(iter(labels.values()), str(uri).split("/")[-1])


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

    def _slug_for(self, uri: URIRef) -> str:
        key = str(uri)
        if key not in self._uri_to_slug:
            self._uri_to_slug[key] = uri_to_slug(key, self._slug_pool)
        return self._uri_to_slug[key]

    def convert(self) -> dict:
        g = self.g

        # Collect all skos:Concept URIs.
        concepts = list(g.subjects(RDF.type, SKOS_NS.Concept))

        # First pass: assign slugs.
        for c in concepts:
            notation = next(g.objects(c, SKOS_NS.notation), None)
            if notation:
                slug = slugify(str(notation))
            else:
                label = best_label(g, c, self.lang)
                slug  = slugify(label)
            if slug and slug not in self._slug_pool:
                self._slug_pool.add(slug)
                self._uri_to_slug[str(c)] = slug
            else:
                self._slug_for(c)  # generate unique slug

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
                parent_slug = self._slug_for(broader)
                break  # HT supports one WP parent; use first broader

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
                    redirects.append({
                        "from": slug,
                        "to":   self._slug_for(replaced_by),
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
                        self.report_lines.append(
                            f"SKOS:RELATED (manual direction needed): {slug} ↔ {self._slug_for(target)}"
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
                    if self._is_custom_prop(prop_str) and prop not in (
                        SKOS_NS.broader, SKOS_NS.narrower, SKOS_NS.broaderTransitive,
                        SKOS_NS.narrowerTransitive, SKOS_NS.inScheme, SKOS_NS.topConceptOf,
                        SKOS_NS.member, SKOS_NS.memberList, SKOS_NS.notation,
                        SKOS_NS.prefLabel, SKOS_NS.altLabel, SKOS_NS.hiddenLabel,
                        SKOS_NS.definition, SKOS_NS.scopeNote, SKOS_NS.editorialNote,
                        SKOS_NS.changeNote, SKOS_NS.historyNote, SKOS_NS.example,
                        SKOS_NS.exactMatch, SKOS_NS.closeMatch, SKOS_NS.broadMatch,
                        SKOS_NS.narrowMatch, SKOS_NS.relatedMatch, SKOS_NS.related,
                        RDF.type, RDFS.label, RDFS.comment, RDFS.seeAlso,
                        OWL_NS.deprecated, DCTERMS.isReplacedBy,
                    ):
                        self.report_lines.append(
                            f"UNMAPPED PROPERTY: {prop_str} on term {slug} — add to mapping config to import"
                        )
                    continue

                for target in g.objects(c, prop):
                    if isinstance(target, URIRef):
                        relationships.append({
                            "from": slug,
                            "to":   self._slug_for(target),
                            "type": str(mapped),
                        })

        # Collections → group memberships.
        group_memberships = []
        collections = list(g.subjects(RDF.type, SKOS_NS.Collection))
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
                if isinstance(member, URIRef):
                    group_memberships.append({
                        "term":  self._slug_for(member),
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
                    out[section][k.strip()] = None if v.strip() == "~" else v.strip() or None
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
