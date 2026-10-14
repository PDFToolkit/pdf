#!/usr/bin/env python3
"""Compile reviewed PDF2 table grids and explicit corrections into the source catalogue."""
from __future__ import annotations

import argparse
import copy
import hashlib
import json
from pathlib import Path
import re

from generate_specification import class_name, value_classes

ROOT = Path(__file__).resolve().parents[1]


def write(path, data):
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def compile_catalogue(extraction, root, check=False):
    directory = root / "resources/iso32000-2-2020"
    metadata = json.loads((directory / "metadata.json").read_text(encoding="utf-8"))
    if extraction is None:
        evidence = json.loads((directory / "source-extraction.json").read_text(encoding="utf-8"))
    else:
        evidence = {"source": json.loads((extraction / "metadata.json").read_text(encoding="utf-8")), "dictionaries": json.loads((extraction / "dictionaries.json").read_text(encoding="utf-8")), "tables": json.loads((extraction / "tables.json").read_text(encoding="utf-8")), "issues": json.loads((extraction / "extraction-issues.json").read_text(encoding="utf-8"))}
    base = copy.deepcopy(evidence["dictionaries"])
    tables = evidence["tables"]
    overrides = json.loads((directory / "reviewed-overrides.json").read_text(encoding="utf-8"))["tables"]
    reviews = json.loads((directory / "extraction-review.json").read_text(encoding="utf-8"))
    if reviews["sha256"] != metadata["source"]["sha256"]:
        raise ValueError("The explicit extraction review belongs to a different source")
    resolved = [{key: value for key, value in issue.items() if key != "resolution"} for issue in reviews["resolved_extraction_anomalies"]]
    if evidence["issues"] != resolved:
        raise ValueError("Extraction anomalies changed and require a new explicit review")
    source = root / metadata["source"]["path"]
    if hashlib.sha256(source.read_bytes()).hexdigest() != metadata["source"]["sha256"]:
        raise ValueError("The catalogue source must identify the retained canonical PDF")
    source_inventory = evidence["source"]
    if source_inventory["sha256"] != metadata["source"]["sha256"]:
        raise ValueError("Review input does not identify the canonical retained source")
    by_id = {table["id"]: table for table in base}
    for patch in reviews["table_patches"]:
        if patch.get("dictionary_table") and patch["table"] not in by_id:
            original = next(t for t in tables if t["id"] == patch["table"])
            table = {key: copy.deepcopy(original[key]) for key in ["id", "title", "section", "pdf_pages"]}
            table |= {"entries": [], "variable_entries": []}
            base.append(table)
            by_id[table["id"]] = table
        table = by_id[patch["table"]]
        if "pdf_pages" in patch:
            table["pdf_pages"] = patch["pdf_pages"]
        if "section" in patch:
            table["section"] = patch["section"]
        for key in ["entries", "variable_entries"]:
            for entry in patch.get(key, []):
                table[key] = [value for value in table[key] if value["name"] != entry["name"]] + [copy.deepcopy(entry)]
        for entry_name in patch.get("remove_entries", []):
            table["entries"] = [entry for entry in table["entries"] if entry["name"] != entry_name]
        if "entry_errata" in patch:
            for entry in table["entries"]:
                entry["errata"] = copy.deepcopy(patch["entry_errata"])
        for name, changes in patch.get("entry_patches", {}).items():
            entry = next(entry for entry in table["entries"] if entry["name"] == name)
            entry.update(copy.deepcopy(changes))
    for table in base:
        table["name"] = class_name(table["title"], table["id"])
        table["common_tables"] = reviews["common_tables"].get(table["id"], [])
        for entry in [*table["entries"], *table.get("variable_entries", [])]:
            entry["type"] = entry["type"].lower().replace("id (byte string)", "byte string")
            entry["type"] = entry["type"].replace(" (array is deprecated in pdf 2.0)", "")
            if table["id"] == "57" and entry["name"] == "BM":
                entry["deprecated_in"] = None
                entry["deprecated_type_members"] = [{"type": "array", "pdf_version": "2.0"}]
            entry["type"] = re.sub(r"\s+", " ", entry["type"])
            entry["description_origin"] = "normative-source"
            entry["description_language"] = "en"
            scope = entry.get("qualifier") or ""
            entry["conditional"] = bool(re.search(r"\bif\b|otherwise|except|unless|\bwhen\b|\bfor\b|\bonly\b|\brequired in\b", scope, re.I))
            entry["inheritable"] = bool(re.search(r"\binheritable\b", scope, re.I) and not re.search(r"\b(?:not|non)[\s-]+inheritable\b", scope, re.I))
            if table["id"] == "17":
                entry["direct"] = True
                entry["indirect"] = False
                entry["array_elements_direct"] = entry["name"] in {"Index", "W"}
            # A first "shall be X" can describe another key or one branch of
            # an alternative. Never infer a singleton from prose automatically.
            entry["fixed_name"] = reviews["fixed_name_constraints"].get(table["id"], {}).get(entry["name"])
            if names := reviews["name_alternatives"].get(table["id"], {}).get(entry["name"]):
                entry["allowed_names"] = names
            if entry["type"] == "array of file specifications":
                entry["array_member_type"] = "file specification"
            if entry.get("introduced_in") == entry.get("deprecated_in"):
                entry["introduced_in"] = None
            value_classes(entry)
    for reviewed in overrides:
        table = by_id.get(reviewed["id"])
        if table is None:
            table = {key: copy.deepcopy(value) for key, value in reviewed.items() if key != "entries"}
            table["entries"] = []
            table["variable_entries"] = []
            table["common_tables"] = []
            base.append(table)
            by_id[table["id"]] = table
        table["name"] = reviewed["name"]
        table["title"] = reviewed["title"]
        table["section"] = reviewed["section"]
        for patch in reviewed["entries"]:
            replacement = copy.deepcopy(patch)
            replacement["description_origin"] = "project-authored"
            replacement["description_language"] = "en"
            existing = next((index for index, entry in enumerate(table["entries"]) if entry["name"] == replacement["name"]), None)
            if existing is None:
                table["entries"].append(replacement)
            else:
                table["entries"][existing] = replacement
    base.sort(key=lambda table: (not table["id"][0].isdigit(), int(re.search(r"\d+", table["id"])[0]), table["id"]))
    if len({table["name"].lower() for table in base}) != len(base):
        raise ValueError("Edition-specific dictionary class names must be unique")
    for table in base:
        for entry in [*table["entries"], *table.get("variable_entries", [])]:
            value_classes(entry)
    for table in base:
        for ancestor in table["common_tables"]:
            if ancestor not in by_id:
                raise ValueError(f"Unknown ISO 32000-2 common table {ancestor}")
    differences = []
    def save(name, data):
        path = directory / name
        expected = (json.dumps(data, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
        if check:
            if not path.is_file() or path.read_bytes() != expected:
                differences.append(str(path))
        else:
            path.write_bytes(expected)
    save("dictionaries.json", base)
    save("tables.json", [{key: (by_id[table["id"]] if table["id"] in by_id else table)[key] for key in ["id", "title", "section", "pdf_pages"]} | {"dictionary": table["id"] in by_id} for table in tables])
    save("source-extraction.json", evidence)
    metadata["schema_version"] = 2
    metadata["dictionary_catalogue"] = {"status": "complete-base-table-inventory-with-explicit-reviewed-overrides", "table_count": len(tables), "dictionary_tables": len(base), "entry_count": sum(len(table["entries"]) + len(table.get("variable_entries", [])) for table in base), "variable_entry_count": sum(len(table.get("variable_entries", [])) for table in base), "ec3_semantic_review": "partial-explicit-overrides-only"}
    metadata["interpretation"] = "Edition-specific dictionary inventory, fixed and variable entry objects, base type/required/indirect constraints and explicitly reviewed EC3 overlays. A complete table inventory does not establish complete conditional, algorithmic or document conformance."
    save("metadata.json", metadata)
    if differences:
        raise SystemExit("\n".join(differences))
    print(json.dumps(metadata["dictionary_catalogue"]))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--extraction", type=Path, help="Replace retained raw evidence from a fresh extraction")
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--root", type=Path, default=ROOT)
    args = parser.parse_args()
    compile_catalogue(args.extraction, args.root, args.check)
