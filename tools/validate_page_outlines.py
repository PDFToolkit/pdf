"""Independent emitted-byte oracle: ISO1 Tables30/152/153 and ISO2 Tables31/150/151."""
from __future__ import annotations

import argparse
import base64
import hashlib
import io
import json
from pathlib import Path
import subprocess

from pypdf import PdfReader
from pypdf.generic import IndirectObject, NumberObject, FloatObject


class PageOutlineOracle:
    def __init__(self) -> None:
        self.checks = 0
        self.documents: list[dict] = []

    def require(self, condition: bool, message: str) -> None:
        self.checks += 1
        if not condition:
            raise AssertionError(message)

    def identity(self, ref: IndirectObject) -> tuple[int, int]:
        self.require(isinstance(ref, IndirectObject), "Outline links must be indirect")
        return ref.idnum, ref.generation

    def children(self, parent: IndirectObject, seen: set) -> tuple[int, list[str]]:
        dictionary = parent.get_object()
        if "/First" not in dictionary:
            self.require("/Last" not in dictionary, "Paired empty child boundaries")
            return 0, []
        current = dictionary.raw_get("/First")
        previous = None
        visible = 0
        titles = []
        while current is not None:
            identity = self.identity(current)
            self.require(identity not in seen, "No shared or cyclic outline item")
            seen.add(identity)
            item = current.get_object()
            self.require(self.identity(item.raw_get("/Parent")) == self.identity(parent), "Actual outline parent")
            self.require(("/Prev" not in item) if previous is None else self.identity(item.raw_get("/Prev")) == previous, "Reciprocal sibling order")
            descendants, child_titles = self.children(current, seen)
            count = item.get("/Count", 0)
            self.require(isinstance(count, (int, NumberObject)) and not isinstance(count, FloatObject), "Integer item Count")
            self.require(abs(count) == descendants, "Count follows visibility of children, not all descendants")
            visible += 1 + (descendants if count > 0 else 0)
            titles += [str(item["/Title"]), *child_titles]
            if "/Next" not in item:
                self.require(identity == self.identity(dictionary.raw_get("/Last")), "Actual last child")
                break
            previous, current = identity, item.raw_get("/Next")
        return visible, titles

    def check(self, data: bytes, edition: str, updated: bool, label: str) -> None:
        reader = PdfReader(io.BytesIO(data), strict=True)
        self.require(len(reader.pages) == 1, "Fixture page count")
        page = reader.pages[0]
        self.require(isinstance(page["/Rotate"], NumberObject) and not isinstance(page["/Rotate"], FloatObject), "Integer rotation syntax")
        self.require(page["/Rotate"] == -90, "Negative quarter turn preserved")
        self.require(page["/UserUnit"] == (2.5 if updated else 1), "Actual positive unit before/after replacement")
        self.require(page["/Tabs"] == ("/A" if edition == "2.0" else "/R"), "Edition-specific tab order")
        self.require(list(page["/TrimBox"]) == [95, 95, 5, 5], "Opposite rectangle corners remain legal")
        reference = reader.trailer["/Root"].raw_get("/Outlines")
        seen: set = set()
        visible, titles = self.children(reference, seen)
        root = reference.get_object()
        self.require(root["/Count"] == visible == (2 if updated else 4), "Root visible count")
        self.require(len(seen) == 5, "Five actual items, including a hidden grandchild")
        self.require(titles == ["Open", "Child", "Closed", "Hidden", "Last"], "Exact frozen outline titles")
        first = root["/First"]
        self.require(first["/F"] == 3 and list(first["/C"]) == [0.25, 0, 1], "Native outline text style")
        self.documents.append({"name": label, "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()})

    def run(self, php: str) -> dict:
        fixture = Path(__file__).with_name("fixtures") / "page_outlines.php"
        result = subprocess.run([php, str(fixture)], check=True, capture_output=True, text=True)
        cases = json.loads(result.stdout)["cases"]
        for case in cases:
            initial = base64.b64decode(case["initial"], validate=True)
            updated = base64.b64decode(case["updated"], validate=True)
            self.require(updated.startswith(initial), "Incremental output preserves all earlier bytes")
            for suffix, data in [("initial", initial), ("updated", updated)]:
                self.check(data, case["edition"], suffix == "updated", f"{case['edition']}-{case['format']}-{suffix}")
        return {"status": "PASS", "pdfs": len(self.documents), "checks": self.checks, "documents": self.documents}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="php")
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    report = PageOutlineOracle().run(args.php)
    encoded = json.dumps(report, indent=2) + "\n"
    if args.report is not None:
        args.report.write_text(encoded)
    print(encoded, end="")


if __name__ == "__main__":
    main()
