"""Independent pypdf graph and Python arithmetic oracle, ISO12.3/12.4."""

import base64
from io import BytesIO
import json
import math
from pathlib import Path
import sys
from pypdf import PdfReader
from pypdf.generic import IndirectObject


def identity(reference):
    assert isinstance(reference, IndirectObject)
    return reference.idnum, reference.generation


def validate_pdf(path):
    reader = PdfReader(path, strict=True)
    assert len(reader.pages) == 3
    root = reader.trailer["/Root"]
    assert reader.page_labels == ["i", "AA", "A-8"]
    ranges = root["/PageLabels"]["/Nums"]
    assert [int(ranges[i]) for i in range(0, len(ranges), 2)] == [0, 1, 2]
    assert ranges[3]["/S"] == "/A" and ranges[3]["/St"] == 27
    threads_reference = root.raw_get("/Threads")
    assert isinstance(threads_reference, IndirectObject)
    threads = threads_reference.get_object()
    assert len(threads) == 2
    pages = {identity(page.indirect_reference): i for i, page in enumerate(reader.pages)}
    rings = []
    seen_all = set()
    for reference in threads:
        thread = reference.get_object()
        first = thread.raw_get("/F")
        sequence = []
        cursor = first
        while not sequence or identity(cursor) != identity(first):
            assert identity(cursor) not in seen_all
            seen_all.add(identity(cursor))
            sequence.append(cursor)
            bead = cursor.get_object()
            assert bead["/Type"] == "/Bead" and len(bead["/R"]) == 4
            previous = bead.raw_get("/V").get_object()
            following = bead.raw_get("/N").get_object()
            assert identity(previous.raw_get("/N")) == identity(cursor)
            assert identity(following.raw_get("/V")) == identity(cursor)
            if "/T" in bead:
                assert identity(bead.raw_get("/T")) == identity(reference)
            owner = bead.raw_get("/P")
            assert identity(owner) in pages
            memberships = [(i, identity(member)) for i, page in enumerate(reader.pages) for member in page["/B"] if identity(member) == identity(cursor)]
            assert memberships == [(pages[identity(owner)], identity(cursor))]
            cursor = bead.raw_get("/N")
        assert identity(first.get_object().raw_get("/T")) == identity(reference)
        rings.append(sequence)
    assert [len(ring) for ring in rings] == [3, 1]
    assert [pages[identity(bead.get_object().raw_get("/P"))] for bead in rings[0]] == [0, 2, 1]
    assert [identity(bead) for bead in reader.pages[1]["/B"]] == [identity(rings[1][0]), identity(rings[0][2])]
    assert identity(rings[1][0].get_object().raw_get("/N")) == identity(rings[1][0])
    assert identity(rings[1][0].get_object().raw_get("/V")) == identity(rings[1][0])

    assert isinstance(root.raw_get("/Dests"), IndirectObject)
    legacy = root["/Dests"]["/details"]
    string_names = root["/Names"]["/Dests"]["/Names"]
    mapping = {str(string_names[i]): string_names[i + 1].get_object() for i in range(0, len(string_names), 2)}
    modern = mapping["details"]["/D"]
    assert identity(legacy[0]) == identity(reader.pages[1].indirect_reference)
    assert identity(modern[0]) == identity(reader.pages[2].indirect_reference)
    assert modern[1] == "/FitR" and list(modern[2:]) == [42, 420, 570, 620]
    assert mapping["lecture"][1] == "/XYZ" and mapping["lecture"][4] == 0

    page = reader.pages[0]
    assert page["/Dur"] == 5
    transition = page["/Trans"]
    assert transition["/S"] == "/Split" and transition["/D"] == 3.5
    assert transition["/Dm"] == "/V" and transition["/M"] == "/O"
    fly = reader.pages[1]["/Trans"]
    assert fly["/S"] == "/Fly" and fly["/Di"] == "/None" and fly["/SS"] == 0.25 and fly["/B"].value
    primary_ref = page.raw_get("/PresSteps")
    primary = primary_ref.get_object()
    first_ref = primary.raw_get("/Prev")
    last_ref = primary.raw_get("/Next")
    first = first_ref.get_object()
    last = last_ref.get_object()
    assert identity(first.raw_get("/Next")) == identity(primary_ref)
    assert identity(last.raw_get("/Prev")) == identity(primary_ref)
    assert "/Prev" not in first and "/Next" not in last
    assert primary["/Dur"] == 2 and primary["/NA"]["/D"] == "lecture"
    assert identity(last["/NA"]["/D"][0]) == identity(reader.pages[2].indirect_reference)
    assert len(reader.outline) == 3
    for i, page in enumerate(reader.pages):
        assert page.extract_text()
        assert ["Label i", "Label AA", "Label A-8"][i] in page.extract_text()


def roman(number):
    # Independent subtractive algorithm: build digit groups, not the PHP token loop.
    units = ["", "I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX"]
    tens = ["", "X", "XX", "XXX", "XL", "L", "LX", "LXX", "LXXX", "XC"]
    hundreds = ["", "C", "CC", "CCC", "CD", "D", "DC", "DCC", "DCCC", "CM"]
    return "M" * (number // 1000) + hundreds[number // 100 % 10] + tens[number // 10 % 10] + units[number % 10]


def validate_vectors(path):
    vectors = json.loads(path.read_text(encoding="utf-8"))
    for case in vectors["labels"]:
        style, number = case["style"], case["number"]
        if style == "D":
            expected = str(number)
        elif style in ("R", "r"):
            expected = roman(number)
            if style == "r":
                expected = expected.lower()
        else:
            cycle, index = divmod(number - 1, 26)
            expected = chr((65 if style == "A" else 97) + index) * (cycle + 1)
        assert case["label"] == expected
    width, height = vectors["viewport"]
    current = vectors["current"]
    page_box = vectors["page_box"]
    content = vectors["content_bounds"]
    cropped = [max(page_box[0], content[0]), max(page_box[1], content[1]), min(page_box[2], content[2]), min(page_box[3], content[3])]
    for case in vectors["views"]:
        mode, coordinates = case["mode"], case["coordinates"]
        if mode == "XYZ":
            expected = [current[i] if value is None or (i == 2 and value == 0) else value for i, value in enumerate(coordinates)]
        else:
            rectangle = cropped if mode.startswith("FitB") else page_box
            if mode == "FitR":
                rectangle = coordinates
            left, bottom, right, top = rectangle
            w, h = right - left, top - bottom
            if mode in ("FitH", "FitBH"):
                expected = [left, current[1] if coordinates[0] is None else coordinates[0], width / w]
            elif mode in ("FitV", "FitBV"):
                expected = [current[0] if coordinates[0] is None else coordinates[0], top, height / h]
            else:
                zoom = 1 / max(w / width, h / height)
                expected = [(left + right - width / zoom) / 2, (bottom + top + height / zoom) / 2, zoom]
        assert all(math.isclose(actual, target, abs_tol=1e-10, rel_tol=1e-10) for actual, target in zip(case["view"], expected))
    assert len(vectors["labels"]) == 55 and len(vectors["views"]) == 10
    validate_revisions(vectors["revisions"])
    validate_closed_outlines(vectors["closed_outlines"])


def validate_closed_outlines(encoded):
    # Read emitted dictionaries independently of the PHP builder's expanded state.
    assert set(encoded) == {"Table", "Stream", "Hybrid"}
    for format_name, value in encoded.items():
        payload = base64.b64decode(value, validate=True)
        reader = PdfReader(BytesIO(payload), strict=True)
        assert len(reader.pages) == 1
        xref_offset = int(payload.rsplit(b"startxref\n", 1)[1].splitlines()[0])
        assert payload[xref_offset:].startswith(b"xref\n") == (format_name != "Stream")
        assert ("/XRefStm" in reader.trailer) == (format_name == "Hybrid")
        outline_reference = reader.trailer["/Root"].raw_get("/Outlines")
        outline = outline_reference.get_object()
        assert outline["/Type"] == "/Outlines" and "/Count" not in outline
        first_reference, last_reference = outline.raw_get("/First"), outline.raw_get("/Last")
        first, last = first_reference.get_object(), last_reference.get_object()
        assert first["/Title"] == "first closed" and last["/Title"] == "last closed"
        assert identity(first_reference) != identity(last_reference)
        assert identity(first.raw_get("/Parent")) == identity(outline_reference)
        assert identity(last.raw_get("/Parent")) == identity(outline_reference)
        assert identity(first.raw_get("/Next")) == identity(last_reference)
        assert identity(last.raw_get("/Prev")) == identity(first_reference)
        assert "/Prev" not in first and "/Next" not in last
        assert first["/Count"] == -2 and last["/Count"] == -1
        for item in (first, last):
            assert identity(item["/Dest"][0]) == identity(reader.pages[0].indirect_reference)
            assert item["/Dest"][1] == "/Fit"

        hidden_reference = first.raw_get("/First")
        hidden = hidden_reference.get_object()
        assert identity(first.raw_get("/Last")) == identity(hidden_reference)
        assert identity(hidden.raw_get("/Parent")) == identity(first_reference)
        assert hidden["/Title"] == "hidden open" and hidden["/Count"] == 1
        assert "/Prev" not in hidden and "/Next" not in hidden
        grandchild_reference = hidden.raw_get("/First")
        grandchild = grandchild_reference.get_object()
        assert identity(hidden.raw_get("/Last")) == identity(grandchild_reference)
        assert identity(grandchild.raw_get("/Parent")) == identity(hidden_reference)
        assert grandchild["/Title"] == "hidden grandchild" and "/Count" not in grandchild
        last_child_reference = last.raw_get("/First")
        last_child = last_child_reference.get_object()
        assert identity(last.raw_get("/Last")) == identity(last_child_reference)
        assert identity(last_child.raw_get("/Parent")) == identity(last_reference)
        assert last_child["/Title"] == "another hidden child" and "/Count" not in last_child


def validate_revisions(encoded):
    payloads = {name: base64.b64decode(value, validate=True) for name, value in encoded.items()}
    assert payloads["replacement"].startswith(payloads["base"])
    assert payloads["removal"].startswith(payloads["replacement"])
    readers = {name: PdfReader(BytesIO(data), strict=True) for name, data in payloads.items()}
    base, replacement, removal = (readers[name] for name in ("base", "replacement", "removal"))
    assert all(len(reader.pages) == 3 for reader in readers.values())
    assert base.get_destination_page_number(base.named_destinations["target"]) == 1
    assert replacement.get_destination_page_number(replacement.named_destinations["target"]) == 2
    assert removal.get_destination_page_number(removal.named_destinations["target"]) == 2
    assert identity(base.pages[1].indirect_reference) == identity(replacement.pages[1].indirect_reference)
    assert replacement.pages[1]["/Dur"] == removal.pages[1]["/Dur"] == 3
    root = replacement.trailer["/Root"]
    assert root["/OpenAction"]["/D"] == "target"
    thread_reference = root["/Threads"][0]
    first_reference = thread_reference.get_object().raw_get("/F")
    first = first_reference.get_object()
    last_reference = first.raw_get("/N")
    last = last_reference.get_object()
    assert identity(last.raw_get("/N")) == identity(first_reference)
    assert identity(first.raw_get("/V")) == identity(last_reference)
    assert identity(last.raw_get("/P")) == identity(replacement.pages[1].indirect_reference)
    assert identity(replacement.pages[1]["/B"][0]) == identity(last_reference)
    removed = removal.trailer["/Root"]
    assert "/Threads" not in removed
    assert "/B" not in removal.pages[0] and "/B" not in removal.pages[1]
    assert "/PresSteps" not in removal.pages[0]
    # Unreachable historical dictionaries still exist; removing active edges does
    # not silently free or rewrite the old thread and navigation records.
    historical_thread = IndirectObject(*identity(thread_reference), removal).get_object()
    assert identity(historical_thread.raw_get("/F")) == identity(first_reference)
    assert "/Prev" in replacement.trailer and "/Prev" in removal.trailer


if __name__ == "__main__":
    validate_pdf(Path(sys.argv[1]))
    validate_vectors(Path(sys.argv[2]))
    print("Independent navigation oracle passed: 3 pages, labels, two article rings, named/explicit destinations, presentation links, 55 labels, 10 view calculations, 3 incremental revisions and closed outline roots in table/stream/hybrid files.")
