"""Independently inspect Type3, simple OpenType/glyf and vertical CID construction."""

from __future__ import annotations

import argparse
from pathlib import Path
import re
import struct
import subprocess
import xml.etree.ElementTree as ET

from pypdf import PdfReader

from validate_cmaps import poppler_glib_text, require


def sfnt_tables(data: bytes) -> set[str]:
    require(data[:4] == b"\0\1\0\0", "Expected a supplied TrueType-outline sfnt program")
    count = struct.unpack_from(">H", data, 4)[0]
    require(len(data) >= 12 + 16 * count, "Incomplete sfnt table directory")
    tables = set()
    for index in range(count):
        tag, checksum, offset, length = struct.unpack_from(">4sIII", data, 12 + 16 * index)
        require(offset + length <= len(data), "Font table extends beyond the embedded program")
        tables.add(tag.decode("ascii"))
    return tables


def validate(path: Path, pdftotext: str, poppler_glib_bin: Path | None):
    reader = PdfReader(path, strict=True)
    require(len(reader.pages) == 1, "Native fonts example has one page")
    page = reader.pages[0]
    fonts = page["/Resources"]["/Font"]
    shapes = fonts["/F2"]
    require(shapes["/Subtype"] == "/Type3", "Expected an actual Type3 font")
    require(shapes["/FirstChar"] == 65 and shapes["/LastChar"] == 66, "Type3 byte-code range differs")
    require(list(shapes["/Widths"]) == [600, 600], "Type3 widths differ from d1 displacement")
    require(list(shapes["/FontMatrix"]) == [0.001, 0, 0, 0.001, 0, 0], "Type3 glyph-space transformation differs")
    require(list(shapes["/Encoding"]["/Differences"]) == [65, "/square", "/cross"], "Type3 encoding is incomplete")
    for name in ["/square", "/cross"]:
        glyph = shapes["/CharProcs"][name].get_data()
        require(re.match(rb"600\s+0\s+0\s+0\s+500\s+500\s+d1\b", glyph), "CharProc lacks its uncolored width/box declaration")
        require(b" re\n" in glyph and b"f\n" in glyph, "The Type3 glyph must actually paint paths")
    require(b"<41> <25A0>" in shapes["/ToUnicode"].get_data(), "Missing square extraction mapping")
    require(b"<42> <002B>" in shapes["/ToUnicode"].get_data(), "Missing cross extraction mapping")
    simple = fonts["/F3"]
    require(simple["/Subtype"] == "/TrueType" and simple["/Encoding"] == "/WinAnsiEncoding", "Expected explicitly encoded simple TrueType")
    require(simple["/LastChar"] - simple["/FirstChar"] + 1 == len(simple["/Widths"]) == 6, "Simple width cardinality differs")
    descriptor = simple["/FontDescriptor"]
    require(descriptor["/FontName"] == simple["/BaseFont"], "Simple descriptor has a different font name")
    program = descriptor["/FontFile3"]
    require(program["/Subtype"] == "/OpenType", "Expected explicit OpenType stream binding")
    require(program["/Length1"] == len(program.get_data()), "OpenType TrueType-outline Length1 is the decoded program length")
    require("/Length2" not in program and "/Length3" not in program, "OpenType must not use Type1 encrypted/fixed segments")
    require({"cmap", "glyf", "head", "hhea", "hmtx", "loca", "maxp"} <= sfnt_tables(program.get_data()), "Simple normalized program lacks required tables")
    require(program["/Metadata"]["/Type"] == "/Metadata" and program["/Metadata"]["/Subtype"] == "/XML", "Missing font-program Metadata graph")
    ET.fromstring(program["/Metadata"].get_data())
    composite = fonts["/F4"]
    require(composite["/Encoding"] == "/Identity-V", "Vertical writing must come from the encoding CMap")
    cid = composite["/DescendantFonts"][0].get_object()
    require(cid["/Subtype"] == "/CIDFontType2", "Expected embedded Type2 CID descendant")
    require(list(cid["/DW2"]) == [880, -1000], "DW2 position/displacement order differs")
    require(list(cid["/W2"][1]) == [-1200, 300, 800, -1000, 300, 800], "W2 triple order differs")
    require(cid["/W2"][0] == 1, "W2 first CID differs")
    glyphs = cid["/CIDToGIDMap"].get_data()
    require(glyphs[:2] == b"\0\0" and all(int.from_bytes(glyphs[2 * value:2 * value + 2], "big") > 0 for value in range(1, 7)), "CID mapping lacks actual supplied glyphs")
    cid_program = cid["/FontDescriptor"]["/FontFile2"]
    require(cid_program["/Length1"] == len(cid_program.get_data()), "TrueType Length1 is the decoded program length")
    require("cmap" not in sfnt_tables(cid_program.get_data()), "Normalized CID Type2 program must not use a simple-font cmap")
    require(b"<0001> <0041>" in composite["/ToUnicode"].get_data(), "Vertical source codes must remain two-byte CIDs")
    if poppler_glib_bin is not None:
        poppler_text = poppler_glib_text(path, poppler_glib_bin)
    else:
        result = subprocess.run([pdftotext, "-enc", "UTF-8", str(path), "-"], check=True, capture_output=True, timeout=60)
        poppler_text = result.stdout.decode("utf-8")
    for engine, text in [("pypdf", page.extract_text()), ("Poppler", poppler_text)]:
        require("■+" in re.sub(r"\s+", "", text), engine + " did not extract the actual Type3 glyph meanings")
        require("ABCDEF" in text, engine + " did not extract the actual simple embedded glyphs")
        require(re.search(r"A\s*B\s*C", text), engine + " did not extract the vertical glyph sequence")
    print("Native fonts: Type3 drawing/encoding, simple OpenType/glyf embedding, actual CID glyph map and vertical DW2/W2 validated with pypdf and Poppler.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("path", type=Path, help="native-fonts.pdf or its containing example directory")
    parser.add_argument("--pdftotext", default="pdftotext")
    parser.add_argument("--poppler-glib-bin", type=Path, help="Explicit Windows Poppler DLL directory")
    args = parser.parse_args()
    path = args.path / "native-fonts.pdf" if args.path.is_dir() else args.path
    validate(path, args.pdftotext, args.poppler_glib_bin)
