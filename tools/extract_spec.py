#!/usr/bin/env python3
"""Rebuild the ISO 32000-1 inventory from the PDF committed to this repository.

The inventory is source metadata, not an assertion of semantic conformance.
Requires pypdf and pdfplumber; neither is a dependency of the PHP library.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path

import pdfplumber
from pypdf import PdfReader


CAPTION = re.compile(r"^Table ([A-Z]\.\d+|\d+)\s*[–−-]\s*(.+)$")
SECTION = re.compile(r"^(\d+(?:\.\d+)*|[A-Z](?:\.\d+)*)(?:\s+|$)(.*)$")


def clean(value: str | None) -> str:
    return re.sub(r"\s+", " ", value or "").strip()


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def outline_entries(reader: PdfReader) -> list[dict]:
    entries = []

    def visit(outline, depth=0):
        for entry in outline:
            if isinstance(entry, list):
                visit(entry, depth + 1)
                continue
            title = clean(entry.title)
            match = SECTION.match(title)
            entries.append({
                "id": match.group(1) if match else None,
                "title": match.group(2) if match else title,
                "pdf_page": reader.get_destination_page_number(entry) + 1,
                "depth": depth,
            })

    visit(reader.outline)
    return entries


def refresh_constraints(dictionaries: list[dict], corrections_path: Path) -> None:
    # An adjacent later heading must not move a continued table into another
    # normative clause. Physical PDF pages and original table prose stay intact.
    source_clauses = {'229': '12.7.4.3', '249': '12.7.7.3.3', '250': '12.7.7.3.3',
                      '251': '12.7.7.3.4', '349': '14.8.5.7', '159': '12.4.2'}
    for dictionary in dictionaries:
        if dictionary['id'] in source_clauses:
            if dictionary['section'] != source_clauses[dictionary['id']]:
                dictionary['extraction_section'] = dictionary['section']
            dictionary['section'] = source_clauses[dictionary['id']]
    corrections = json.loads(corrections_path.read_text(encoding='utf-8')) if corrections_path.is_file() else []
    overrides = {(correction['table'], correction['entry']): correction for correction in corrections}
    for dictionary in dictionaries:
        for entry in [*dictionary['entries'], *dictionary['variable_entries']]:
            qualifier = entry['qualifier'] or ''
            entry['inheritable'] = bool(re.search(r'\binheritable\b', qualifier, re.I) and not re.search(r'\b(?:not|non)[\s-]+inheritable\b', qualifier, re.I))
            if 'type' in entry.get('source_constraints', {}):
                entry['type'] = entry['source_constraints']['type']
            direct = dictionary['id'] == '17' or bool(re.search(r'(?:shall|must) not be (?:an? )?indirect reference|(?:shall|must) be (?:a )?direct object', qualifier, re.I))
            indirect = not direct and bool(re.search(r'(?:shall|must) be (?:an? )?indirect reference', qualifier, re.I))
            entry['direct'] = direct
            entry['indirect'] = indirect
            entry['array_elements_direct'] = dictionary['id'] == '17' and entry['name'] in ['Index', 'W']
            entry['source_constraints'] = {key: entry[key] for key in ['direct', 'indirect', 'array_elements_direct']}
            override = overrides.get((dictionary['id'], entry['name']))
            if override:
                if 'type' in override['constraints']:
                    entry['source_constraints']['type'] = entry['type']
                entry['implementation_correction'] = override
                entry.update(override['constraints'])
            else:
                entry.pop('implementation_correction', None)


def extract(source: Path, destination: Path, corrections_path: Path) -> None:
    destination.mkdir(parents=True, exist_ok=True)
    reader = PdfReader(source)
    sections = outline_entries(reader)
    tables: dict[str, dict] = {}
    plain_pages = []
    for index, page in enumerate(reader.pages):
        text = page.extract_text()
        plain_pages.append(text)
        for line in text.splitlines():
            match = CAPTION.match(clean(line))
            if match is None:
                continue
            identifier, title = match.groups()
            title = re.sub(r"\s*\(continued\)$", "", title).strip()
            if identifier not in tables:
                preceding = [entry for entry in sections if entry["id"] and entry["pdf_page"] <= index + 1]
                section = preceding[-1]["id"] if preceding else None
                tables[identifier] = {
                    "id": identifier, "title": title, "section": section,
                    "pdf_pages": [], "grids": [],
                }
            tables[identifier]["pdf_pages"].append(index + 1)

    missing = [str(number) for number in range(1, 371) if str(number) not in tables]
    if missing:
        raise ValueError(f"Incomplete main table inventory: {missing}")

    issues = []
    with pdfplumber.open(source) as pdf:
        for index, page in enumerate(pdf.pages):
            expected = [table for table in tables.values() if index + 1 in table["pdf_pages"]]
            if not expected:
                continue
            captions = []
            lines = page.extract_text_lines(return_chars=False)
            for line in lines:
                match = CAPTION.match(clean(line["text"]))
                if match:
                    captions.append((line["top"], match.group(1)))
                    identifier, title = match.groups()
                    if identifier in tables and "(continued)" not in title:
                        tables[identifier]["title"] = clean(title)
            found = set()
            for grid in page.find_tables():
                rows = [[clean(cell) if cell is not None else None for cell in row] for row in grid.extract()]
                if not rows or not any(cell for row in rows for cell in row):
                    continue
                candidates = [caption for caption in captions if caption[0] < grid.bbox[1]]
                if not candidates:
                    continue
                identifier = candidates[-1][1]
                if identifier not in tables:
                    continue
                tables[identifier]["grids"].append({"pdf_page": index + 1, "rows": rows})
                caption_top = candidates[-1][0]
                caption_lines = []
                previous_top = caption_top
                for line in lines:
                    if line['top'] < caption_top:
                        continue
                    line_text = clean(line['text'])
                    if line['top'] >= grid.bbox[1] or line['top'] - previous_top > 14.5 or line_text.lower().startswith(('key ', 'parameter ', 'operands ')):
                        break
                    caption_lines.append(line_text)
                    previous_top = line['top']
                if caption_lines and "(continued)" not in caption_lines[0]:
                    caption_text = CAPTION.match(" ".join(caption_lines))
                    if caption_text:
                        tables[identifier]["title"] = clean(caption_text.group(2))
                found.add(identifier)
            for table in expected:
                if table["id"] not in found:
                    issues.append({"kind": "grid_not_extracted", "table": table["id"], "pdf_page": index + 1})
            page.close()
            if (index + 1) % 50 == 0:
                print(f"Scanned {index + 1}/{len(pdf.pages)} pages", flush=True)

    dictionaries = []
    operators = []
    for table in tables.values():
        entries = []
        variable_entries = []
        is_dictionary_table = False
        for grid in table["grids"]:
            rows = grid["rows"]
            header = rows[0]
            normalized_header = [(cell or '').lower() for cell in header]
            dictionary_grid = len(header) in [3, 4] and normalized_header[:2] == ['key', 'type'] and normalized_header[-1] in ['value', 'description']
            if table['id'] == 'F.1' and normalized_header == ['parameter', 'type', 'value']:
                dictionary_grid = True
            if dictionary_grid:
                is_dictionary_table = True
                for raw_row in rows[1:]:
                    row = [raw_row[0], raw_row[1], raw_row[-1]] if len(raw_row) == 4 else raw_row
                    if table['id'] == '370' and len(raw_row) == 4 and not row[0] and raw_row[2] in ['%%IncludedImageDimensions', '%%IncludedImageQuality']:
                        # These two long PDF names overflow their narrow key cell
                        # in the source; the matching OPI comment confirms them.
                        row[0] = raw_row[2].lstrip('%')
                    if len(row) == 3 and not row[0] and not row[1]:
                        # A description cell continued from the preceding page.
                        if entries:
                            entries[-1]["description"] += " " + (row[2] or "")
                        continue
                    if len(row) == 3 and row[0] and not row[1] and not row[2]:
                        # A merged footnote cell is not an entry.
                        continue
                    if len(row) != 3 or not row[0] or not row[1]:
                        issues.append({"kind": "dictionary_row_needs_review", "table": table["id"], "pdf_page": grid["pdf_page"], "row": row})
                        continue
                    key, value_type, description = row
                    variable = key.lower() in ["other stream attributes", "other keys", "any colorant name", "any conforming product name or well- known data type", "version number"]
                    if not variable and not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", key):
                        if key in ["PrintingOrd er", "ParentTreeNext Key"]:
                            key = key.replace(" ", "")
                        else:
                            issues.append({"kind": "dictionary_row_needs_review", "table": table["id"], "pdf_page": grid["pdf_page"], "row": row})
                            continue
                    requirement = re.match(r"^\(([^)]+)\)", description or "")
                    qualifier = requirement.group(1) if requirement else None
                    version = re.search(r"PDF\s+(1\.\d)", qualifier or "")
                    fixed_name = None
                    if key == 'Type' and value_type.lower() == 'name':
                        literal = re.search(r'\bshall be ([A-Z0-9][A-Za-z0-9]*)\b', description or '')
                        if literal:
                            fixed_name = literal.group(1)
                    entry = {
                        "name": key,
                        "type": value_type,
                        "description": description,
                        "fixed_name": fixed_name,
                        "direct": table['id'] == '17',
                        "array_elements_direct": table['id'] == '17' and key in ['Index', 'W'],
                        "qualifier": qualifier,
                        "required": bool(qualifier and re.match(r"Required(?:;|$)", qualifier, re.I)),
                        "conditional": bool(qualifier and re.search(r"\bif\b|otherwise", qualifier, re.I)),
                        "inheritable": bool(qualifier and re.search(r'\binheritable\b', qualifier, re.I) and not re.search(r'\b(?:not|non)[\s-]+inheritable\b', qualifier, re.I)),
                        "indirect": bool(qualifier and "shall be an indirect reference" in qualifier.lower()),
                        "introduced_in": version.group(1) if version else None,
                        "pdf_page": grid["pdf_page"],
                    }
                    (variable_entries if variable else entries).append(entry)
            if table["id"] == "A.1" and header == ["Operator", "PostScript Equivalent", "Description", "Table"]:
                for row in rows[1:]:
                    if len(row) != 4 or not row[0]:
                        continue
                    token, _, description, reference_table = row
                    operators.append({
                        "token": token,
                        "description": description,
                        "table": reference_table or "105",
                        "pdf_page": grid["pdf_page"],
                    })
        if is_dictionary_table:
            dictionaries.append({key: table[key] for key in ["id", "title", "section", "pdf_pages"]} | {"entries": entries, "variable_entries": variable_entries, "implementation": "object_type_validation; unconditional_required_keys; indirect_reference_constraints"})

    # Source table 105 is the text state parameter table. Appendix A omits its
    # reference in the Tc/Tf/TL/Tr/Ts/Tw/Tz rows; do not treat blanks as no table.
    if len(operators) != 73:
        raise ValueError(f"Expected all 73 operator tokens, found {len(operators)}")
    refresh_constraints(dictionaries, corrections_path)
    metadata = {
        "standard": "ISO 32000-1:2008",
        "pdf_version": "1.7",
        "source": source.name,
        "source_path": "resources/reference-documents/" + source.name,
        "sha256": hashlib.sha256(source.read_bytes()).hexdigest(),
        "pdf_pages": len(reader.pages),
        "outline_entries": len(sections),
        "table_count": len(tables),
        "dictionary_table_count": len(dictionaries),
        "dictionary_entry_count": sum(len(dictionary["entries"]) for dictionary in dictionaries),
        "variable_entry_count": sum(len(dictionary["variable_entries"]) for dictionary in dictionaries),
        "operator_count": len(operators),
        "interpretation": "Mechanical source inventory. Qualifiers are preserved; conditional requirements, enum values, inheritance, algorithms and conformance require additional semantic implementation.",
    }
    write_json(destination / "metadata.json", metadata)
    write_json(destination / "sections.json", sections)
    write_json(destination / "tables.json", [{key: table[key] for key in ["id", "title", "section", "pdf_pages"]} for table in tables.values()])
    write_json(destination / "dictionaries.json", dictionaries)
    write_json(destination / "operators.json", operators)
    write_json(destination / "extraction-issues.json", issues)
    print(json.dumps(metadata, ensure_ascii=False), flush=True)
    print(f"Review issues: {len(issues)}", flush=True)


if __name__ == "__main__":
    root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, default=root / "resources/reference-documents/PDF32000_2008.pdf")
    parser.add_argument("--output", type=Path, default=root / "resources" / "iso32000-1")
    parser.add_argument('--corrections', type=Path, default=root / 'resources' / 'iso32000-1' / 'corrections.json')
    parser.add_argument('--refresh-constraints', action='store_true', help='Refresh interpreted constraints and explicit corrections without re-extracting PDF table text')
    arguments = parser.parse_args()
    if arguments.refresh_constraints:
        catalogue = arguments.output / 'dictionaries.json'
        dictionaries = json.loads(catalogue.read_text(encoding='utf-8'))
        refresh_constraints(dictionaries, arguments.corrections)
        write_json(catalogue, dictionaries)
        print('Refreshed source constraints and explicit implementation corrections.')
    else:
        extract(arguments.source, arguments.output, arguments.corrections)
