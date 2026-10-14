#!/usr/bin/env python3
"""Extract physical PDF2 tables, including continued captions, without executing PDF data.

This development tool requires pypdf/pdfplumber. Its raw grids and review issues
are evidence for the separately reviewed catalogue, never runtime dependencies.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re

import pdfplumber
from pypdf import PdfReader

from extract_spec import clean, outline_entries, write_json

CAPTION = re.compile(r"^Table\s+([A-Z]\.\d+|\d+[a-z]?)(?:\s*[—–−-]\s*(.*)|\s*\(continued\))$")


def constraint(key, kind, description, page):
    qualifier_match = re.match(r"^\(([^)]+)\)", description or "")
    qualifier = qualifier_match.group(1) if qualifier_match else None
    scope = qualifier or ""
    introduced = re.search(r"\bPDF\s+([12]\.\d)\b", scope)
    deprecated = re.search(r"deprecated\s+(?:in\s+)?PDF\s+([12]\.\d)", scope, re.I)
    direct = bool(re.search(r"(?:shall|must) (?:not be (?:an? )?indirect reference|be (?:a )?direct (?:object|dictionary))", scope, re.I))
    indirect = not direct and bool(re.search(r"(?:shall|must) be (?:an? )?indirect reference", scope, re.I))
    fixed = None
    if key == "Type" and kind.lower() == "name":
        literal = re.search(r"\bshall be\s+([A-Z][A-Za-z0-9]*)\b", description or "")
        fixed = literal.group(1) if literal else None
    return {"name": key, "type": kind.lower(), "description": description or "", "qualifier": qualifier,
            "required": bool(re.match(r"Required(?:\b|;)", scope, re.I)),
            "conditional": bool(re.search(r"\bif\b|otherwise|except|unless", scope, re.I)),
            "inheritable": bool(re.search(r"\binheritable\b", scope, re.I) and not re.search(r"\b(?:not|non)[\s-]+inheritable\b", scope, re.I)),
            "introduced_in": introduced.group(1) if introduced else None,
            "deprecated_in": deprecated.group(1) if deprecated else None,
            "direct": direct, "indirect": indirect, "array_elements_direct": False,
            "fixed_name": fixed, "pdf_page": page, "errata": None}


def extract(source, destination):
    destination.mkdir(parents=True, exist_ok=True)
    reader = PdfReader(source)
    sections = outline_entries(reader)
    tables = {}
    issues = []
    previous_table = None
    with pdfplumber.open(source) as pdf:
        for index, page in enumerate(pdf.pages):
            lines = page.extract_text_lines(return_chars=True)
            captions = []
            for line in lines:
                match = CAPTION.match(clean(line["text"]))
                if match is None or not any("Bold" in char["fontname"] for char in line["chars"][:12]):
                    continue
                identifier, title = match.groups()
                continued = "(continued)" in clean(line["text"])
                title = re.sub(r"\s*\(continued\)$", "", title or "").strip()
                preceding = [entry for entry in sections if entry["id"] and entry["pdf_page"] < index + 1]
                same_page = [re.match(r"^(\d+(?:\.\d+)+|[A-Z](?:\.\d+)+)\s+", clean(candidate["text"])) for candidate in lines if candidate["top"] < line["top"] and any("Bold" in char["fontname"] for char in candidate["chars"][:12])]
                same_page = [heading.group(1) for heading in same_page if heading]
                section = same_page[-1] if same_page else preceding[-1]["id"] if preceding else None
                if identifier not in tables:
                    tables[identifier] = {"id": identifier, "title": title, "section": section, "pdf_pages": [], "grids": []}
                table = tables[identifier]
                if title and not continued and not table["title"]:
                    table["title"] = title
                if index + 1 not in table["pdf_pages"]:
                    table["pdf_pages"].append(index + 1)
                captions.append((line["top"], identifier, continued))
            found = set()
            last_assigned = None
            if captions or previous_table is not None:
                for grid in page.find_tables():
                    candidates = [caption for caption in captions if caption[0] < grid.bbox[1]]
                    rows = [[clean(cell) if cell is not None else None for cell in row] for row in grid.extract()]
                    while rows and not any(rows[0]):
                        rows.pop(0)
                    if not rows or not any(cell for row in rows for cell in row):
                        continue
                    if candidates:
                        top, identifier, continued = candidates[-1]
                    elif previous_table is not None and grid.bbox[1] < 150 and tables[previous_table]["grids"] and rows[0] == tables[previous_table]["grids"][-1]["rows"][0]:
                        top, identifier, continued = 0, previous_table, True
                    else:
                        continue
                    tables[identifier]["grids"].append({"pdf_page": index + 1, "bbox": list(grid.bbox), "rows": rows})
                    if index + 1 not in tables[identifier]["pdf_pages"]:
                        tables[identifier]["pdf_pages"].append(index + 1)
                    found.add(identifier)
                    last_assigned = identifier
                    if not continued:
                        title_lines = []
                        last = top
                        for line in lines:
                            if line["top"] < top:
                                continue
                            if line["top"] >= grid.bbox[1] or line["top"] - last > 14.5 or not any("Bold" in char["fontname"] for char in line["chars"]):
                                break
                            title_lines.append(clean(line["text"]))
                            last = line["top"]
                        caption = CAPTION.match(" ".join(title_lines))
                        if caption and caption.group(2):
                            tables[identifier]["title"] = caption.group(2)
                for top, identifier, continued in captions:
                    if identifier not in found:
                        issues.append({"kind": "grid_not_extracted", "table": identifier, "pdf_page": index + 1})
            previous_table = last_assigned
            page.close()
            if (index + 1) % 50 == 0:
                print(f"Scanned {index + 1}/{len(pdf.pages)} pages", flush=True)
    dictionaries = []
    for table in tables.values():
        entries = []
        variable_entries = []
        last_entry = None
        dictionary_table = False
        for grid in table["grids"]:
            rows = grid["rows"]
            header = [(cell or "").lower() for cell in rows[0]]
            is_dictionary = len(header) in {3, 4} and header[:2] == ["key", "type"] and header[-1] in {"value", "description"}
            is_dictionary = is_dictionary or (table["id"] == "F.1" and header == ["parameter", "type", "value"])
            if not is_dictionary:
                continue
            dictionary_table = True
            for raw_row in rows[1:]:
                row = [raw_row[0], raw_row[1], raw_row[-1]]
                if not row[0] and not row[1]:
                    if last_entry is not None:
                        last_entry["description"] += " " + (row[2] or "")
                    continue
                if row[0] and not row[1] and not row[2]:
                    continue
                if not row[0] or not row[1] or not row[2]:
                    issues.append({"kind": "dictionary_row_needs_review", "table": table["id"], "pdf_page": grid["pdf_page"], "row": row})
                    continue
                key, kind, description = row
                variable = bool(re.search(r"^(?:other |any |version number$|prefix name$)", key, re.I))
                if not variable and not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", key):
                    key = key.replace("ParentTreeNext Key", "ParentTreeNextKey").replace("PrintingOrd er", "PrintingOrder")
                    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", key):
                        issues.append({"kind": "dictionary_row_needs_review", "table": table["id"], "pdf_page": grid["pdf_page"], "row": row})
                        continue
                entry = constraint(key, kind, description, grid["pdf_page"])
                target = variable_entries if variable else entries
                existing = next((item for item in target if item["name"] == key), None)
                if existing:
                    issues.append({"kind": "repeated_entry", "table": table["id"], "entry": key, "pdf_page": grid["pdf_page"]})
                else:
                    target.append(entry)
                    last_entry = entry
        if dictionary_table:
            dictionaries.append({key: table[key] for key in ["id", "title", "section", "pdf_pages"]} | {"entries": entries, "variable_entries": variable_entries})
    metadata = {"standard": "ISO 32000-2:2020", "source": source.name, "sha256": hashlib.sha256(source.read_bytes()).hexdigest(), "physical_pdf_pages": len(reader.pages), "table_count": len(tables), "dictionary_table_count": len(dictionaries), "dictionary_entry_count": sum(len(table["entries"]) for table in dictionaries), "variable_entry_count": sum(len(table["variable_entries"]) for table in dictionaries), "review_issue_count": len(issues), "status": "unreviewed-source-extraction"}
    write_json(destination / "tables.json", list(tables.values()))
    write_json(destination / "dictionaries.json", dictionaries)
    write_json(destination / "sections.json", sections)
    write_json(destination / "extraction-issues.json", issues)
    write_json(destination / "metadata.json", metadata)
    print(json.dumps(metadata), flush=True)


if __name__ == "__main__":
    root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, default=root / "ISO_32000-2_sponsored_EC3.pdf")
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    extract(args.source, args.output)
