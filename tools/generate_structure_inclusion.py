#!/usr/bin/env python3
"""Extract TS 32005 table 5 by its printed column coordinates; never execute PDF data."""
import argparse
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "resources/iso32000-2-bundle/documents/ISO-TS-32005-2023-sponsored.pdf"
TARGET = ROOT / "resources/iso-ts-32005/inclusion.json"
PHP = ROOT / "src/Document/Structure/StructureInclusionTable.php"


def extract(source):
    import pdfplumber
    rules = {}
    with pdfplumber.open(source) as pdf:
        current = None
        for number in range(13, 46):
            words = pdf.pages[number].extract_words()
            children = next(w for w in words if w["text"] == "Children")
            parents = next(w for w in words if w["text"] == "Parents")
            x = children["x0"]
            lines = {}
            for word in words:
                if word["top"] <= children["top"] + 25 or word["top"] > 799:
                    continue
                line = lines.setdefault(round(word["top"]), [[], [], [], [], []])
                offset = word["x0"] - x
                col = 0 if offset < -2 else 1 if offset < 27 else 2 if word["x0"] < parents["x0"] - 2 else 3 if word["x0"] < parents["x0"] + 28 else 4
                line[col].append(word)
            for row in lines.values():
                cells = [" ".join(w["text"] for w in sorted(cell, key=lambda w: w["x0"])) for cell in row]
                if cells[0]:
                    if cells[0].startswith(("Table 5", "©", "Sold", "Single")):
                        continue
                    current = cells[0]
                    if current in rules:
                        raise ValueError(f"Repeated type {current} on page {number + 1}")
                    rules[current] = {"page": number + 1, "children": {}, "parents": {}}
                if current is None:
                    raise ValueError("Missing structure row")
                for occurrence, role, direction in [(cells[1], cells[2], "children"), (cells[3], cells[4], "parents")]:
                    if not role and not occurrence:
                        continue
                    occurrence = occurrence.replace("∅", "ø")
                    if occurrence not in {"1", "ø", "ø*", "0..n", "1..n", "0..1", "‡", "[a]", "[b]", "c"}:
                        raise ValueError(f"Unexpected occurrence {occurrence!r}, role={role!r}, page={number + 1}")
                    if not role:
                        raise ValueError(f"Missing role for occurrence {occurrence!r}")
                    rules[current][direction][role] = occurrence
    return {"document": source.name, "sha256": hashlib.sha256(source.read_bytes()).hexdigest(), "table": "5", "rules": rules}


def php(data):
    def literal(value):
        if isinstance(value, dict):
            return "[" + ", ".join(literal(k) + " => " + literal(v) for k, v in value.items()) + "]"
        if isinstance(value, int):
            return str(value)
        return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"
    rows = "\n".join("        " + literal(role) + " => " + literal(rule) + "," for role, rule in data["rules"].items())
    return "<?php\n\ndeclare(strict_types=1);\n\nnamespace PDF\\Document\\Structure;\n\n/** Generated from ISO-TS-32005-2023-sponsored.pdf, table 5, physical PDF pages 14–46. */\nfinal readonly class StructureInclusionTable\n{\n    public const string SOURCE_SHA256 = '" + data["sha256"] + "';\n    /** @var array<string, array{page: int, children: array<string, string>, parents: array<string, string>}> */\n    public const array RULES = [\n" + rows + "\n    ];\n}\n"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", type=Path, default=SOURCE)
    parser.add_argument("--extract", action="store_true", help="Re-extract the reviewed PDF catalogue (development dependency: pdfplumber)")
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    data = extract(args.source) if args.extract else json.loads(TARGET.read_text(encoding="utf-8"))
    if data["sha256"] != hashlib.sha256(args.source.read_bytes()).hexdigest():
        raise SystemExit("The inclusion catalogue must identify the exact retained source PDF")
    if len(data["rules"]) != 53 or data["rules"]["Hn"]["children"].get("content item") != "0..n":
        raise SystemExit("The reviewed inclusion catalogue is incomplete")
    outputs = {TARGET: json.dumps(data, indent=2, ensure_ascii=False) + "\n", PHP: php(data)}
    for path, content in outputs.items():
        if args.check:
            if not path.exists() or path.read_text(encoding="utf-8") != content:
                raise SystemExit(f"Regenerate {path.relative_to(ROOT)}")
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content, encoding="utf-8", newline="\n")
    print(f"Verified {len(data['rules'])} structure types from table 5")


if __name__ == "__main__":
    main()
