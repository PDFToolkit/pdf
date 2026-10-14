"""Independent PDF2 namespace/parent-tree/XMP/font-byte checks, not WTPDF certification."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import struct
import subprocess
import tempfile
import xml.etree.ElementTree as ET

from pypdf import PdfReader
from pypdf.generic import IndirectObject

from validate_cmaps import poppler_glib_text, require

ROOT = Path(__file__).resolve().parents[1]
PDF20 = "http://iso.org/pdf2/ssn"
PDF17 = "http://iso.org/pdf/ssn"


def command(arguments):
    result = subprocess.run(list(map(str, arguments)), cwd=ROOT, capture_output=True)
    require(result.returncode == 0, f"Command failed: {arguments}\n{result.stderr.decode('utf-8', errors='replace')}")
    return result


def widths(values):
    result = {}
    cursor = 0
    while cursor < len(values):
        first = int(values[cursor])
        second = values[cursor + 1]
        cursor += 2
        if isinstance(second, list):
            for offset, width in enumerate(second):
                result[first + offset] = float(width)
        else:
            last, width = int(second), float(values[cursor])
            cursor += 1
            for cid in range(first, last + 1):
                result[cid] = width
    return result


def sfnt_metrics(program):
    require(program[:4] == b"\x00\x01\x00\x00", "Example embeds a TrueType-outline SFNT")
    count = struct.unpack_from(">H", program, 4)[0]
    tables = {}
    for index in range(count):
        tag, checksum, start, size = struct.unpack_from(">4sIII", program, 12 + 16 * index)
        require(start + size <= len(program), "Embedded font table lies outside the program")
        tables[tag] = program[start:start + size]
    upem = struct.unpack_from(">H", tables[b"head"], 18)[0]
    glyph_count = struct.unpack_from(">H", tables[b"maxp"], 4)[0]
    metric_count = struct.unpack_from(">H", tables[b"hhea"], 34)[0]
    advances = [struct.unpack_from(">H", tables[b"hmtx"], 4 * index)[0] for index in range(metric_count)]
    advances.extend([advances[-1]] * (glyph_count - metric_count))
    return upem, advances


def validate(path, qpdf, poppler_glib_bin=None, pdftotext=None):
    command([qpdf, "--check", path])
    reader = PdfReader(path, strict=True)
    require(reader.pdf_header == "%PDF-2.0" and len(reader.pages) == 1, "Expected one PDF2 page")
    catalog = reader.trailer["/Root"]
    require(catalog["/MarkInfo"]["/Marked"].value is True and catalog["/Lang"] == "en-US", "Tagged status/language differs")
    root = catalog["/StructTreeRoot"]
    namespaces = {str(reference.get_object()["/NS"]): reference for reference in root["/Namespaces"]}
    require(set(namespaces) == {PDF20, PDF17, "urn:pdftoolkit:example:report"}, "Namespace set differs")
    for namespace in namespaces.values():
        require(isinstance(namespace, IndirectObject), "Namespaces are canonical indirect objects")
        require(namespace.get_object()["/Type"] == "/Namespace", "Missing namespace type")
    mapping = namespaces["urn:pdftoolkit:example:report"].get_object()["/RoleMapNS"]["/ReportParagraph"]
    require(mapping[0] == "/P" and mapping[1] == namespaces[PDF20], "Custom role does not target canonical PDF2 P")
    document = root["/K"][0].get_object()
    require(document["/S"] == "/Document" and document.raw_get("/NS") == namespaces[PDF20], "Root Document requires PDF2 namespace")
    children = [reference.get_object() for reference in document["/K"]]
    require([element["/S"] for element in children] == ["/H1", "/ReportParagraph", "/TOC", "/P", "/P"], "Logical content order differs")
    toc = children[2]
    item = toc["/K"]
    reference = item["/K"]
    require(item["/S"] == "/TOCI" and reference["/S"] == "/Reference", "Legacy content-entry semantics differ")
    for element in [toc, item, reference]:
        require(element.raw_get("/NS") == namespaces[PDF17], "Unique PDF1.7 types require the PDF1.7 namespace")
    require(reference["/Ref"][0].get_object().indirect_reference == children[0].indirect_reference, "TOC Ref must identify the heading")
    content_elements = [children[0], children[1], reference, children[3], children[4]]
    parents = root["/ParentTree"]["/Nums"]
    require(int(parents[0]) == 0 and len(parents) == 2, "Unexpected parent-tree keys")
    parent_array = parents[1].get_object()
    require(len(parent_array) == 5, "Every content item requires its structural parent")
    for index, element in enumerate(content_elements):
        require(int(element["/K"]) == index, "Wrong MCID in structure order")
        require(parent_array[index].get_object().indirect_reference == element.indirect_reference, "ParentTree does not reciprocate the MCID owner")
        require(element["/Pg"].indirect_reference == reader.pages[0].indirect_reference, "Content belongs to a different page")
    page = reader.pages[0]
    operations = page.get_contents().operations
    require([int(operands[1]["/MCID"]) for operands, symbol in operations if symbol == b"BDC"] == list(range(5)), "Physical marked-content order differs")
    xml = ET.fromstring(catalog["/Metadata"].get_data())
    title = xml.find(".//{http://purl.org/dc/elements/1.1/}title")
    require(title is not None and title.find("{http://www.w3.org/1999/02/22-rdf-syntax-ns#}Alt") is not None, "dc:title requires RDF Alt")
    declarations = {node.text for node in xml.findall(".//{http://pdfa.org/declarations/}conformsTo")}
    require(declarations == {"https://pdfa.org/declarations#iso32005", "http://pdfa.org/declarations/wtpdf/#reuse1.0"}, "Declaration identifiers differ from supplied sources")
    font = page["/Resources"]["/Font"]["/F1"]
    cidfont = font["/DescendantFonts"][0].get_object()
    program = cidfont["/FontDescriptor"]["/FontFile2"].get_data()
    upem, advances = sfnt_metrics(program)
    pdf_widths = widths(cidfont["/W"])
    gids = cidfont["/CIDToGIDMap"].get_data()
    unicode_map = {int(source, 16): bytes.fromhex(destination.decode()).decode("utf-16-be") for source, destination in re.findall(rb"<([0-9A-Fa-f]{4})>\s+<([0-9A-Fa-f]{4,})>", font["/ToUnicode"].get_data())}
    glyphs_checked = 0
    for operands, symbol in operations:
        if symbol != b"Tj":
            continue
        data = operands[0].original_bytes
        require(len(data) % 2 == 0, "Identity-H text uses complete CID code units")
        for offset in range(0, len(data), 2):
            cid = int.from_bytes(data[offset:offset + 2], "big")
            gid = int.from_bytes(gids[cid * 2:cid * 2 + 2], "big")
            require(0 < gid < len(advances), "A shown character resolves to .notdef or an absent glyph")
            require(cid in unicode_map, "A shown CID lacks a Unicode mapping")
            require(all(ord(character) not in {0, 0xFEFF, 0xFFFE} for character in unicode_map[cid]), "ToUnicode contains a forbidden scalar")
            width = pdf_widths.get(cid, float(cidfont.get("/DW", 1000)))
            require(abs(width - advances[gid] * 1000 / upem) <= 1, "PDF and embedded-program glyph metrics disagree")
            glyphs_checked += 1
    expected = ["PDF 2.0: explicit structure namespaces", "A custom report paragraph resolves", "Contents: explicit structure namespaces", "The table of contents uses TOC", "Mechanical checks support review"]
    text = page.extract_text()
    for fragment in expected:
        require(fragment in text, f"pypdf text extraction lost {fragment!r}")
    if poppler_glib_bin:
        independent = poppler_glib_text(path, poppler_glib_bin)
    elif pdftotext:
        independent = command([pdftotext, path, "-"]).stdout.decode("utf-8")
    else:
        independent = None
    if independent is not None:
        for fragment in expected:
            require(fragment in independent, f"Poppler text extraction lost {fragment!r}")
    return glyphs_checked


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php")
    parser.add_argument("--qpdf", default="qpdf")
    parser.add_argument("--example", type=Path)
    parser.add_argument("--poppler-glib-bin", type=Path)
    parser.add_argument("--pdftotext")
    args = parser.parse_args()
    count = 0
    glyphs = 0
    if args.example:
        glyphs += validate(args.example.resolve(), args.qpdf, args.poppler_glib_bin, args.pdftotext)
        count += 1
    if args.php:
        with tempfile.TemporaryDirectory(prefix="pdftoolkit-tagging-") as directory:
            for name, flags in [("classic", []), ("xref-stream", ["--xref-stream"]), ("hybrid", ["--hybrid"])]:
                path = Path(directory) / (name + ".pdf")
                result = command([args.php, ROOT / "examples/pdf20-tagged-namespaces.php", path, "--audit-json", *flags])
                audit = json.loads(result.stderr)
                require(audit["detectedFailures"] == 0 and len(audit["pendingReviews"]) >= 5, "Mechanical audit must retain its human/external review boundaries")
                glyphs += validate(path, args.qpdf, args.poppler_glib_bin, args.pdftotext)
                count += 1
    require(count > 0, "Select --php or --example")
    print(f"Verified {count} PDF2 namespace files, reciprocal structure/MCID/Ref links, XMP and {glyphs} shown glyph mappings/embedded metrics; WTPDF certification is not inferred.")


if __name__ == "__main__":
    main()
