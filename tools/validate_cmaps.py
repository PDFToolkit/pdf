"""Inspect the non-Identity CMap example with independent pypdf and Poppler engines."""

from __future__ import annotations

import argparse
import ctypes
import os
from pathlib import Path
import re
import subprocess

from pypdf import PdfReader


def require(condition, message):
    if not condition:
        raise ValueError(message)


def poppler_glib_text(path: Path, directory: Path) -> str:
    """Use the documented C API when a Windows runtime has DLLs but no pdftotext.

    https://poppler.freedesktop.org/api/glib/PopplerDocument.html
    https://poppler.freedesktop.org/api/glib/poppler-Poppler-Page.html
    """
    dll_directory = os.add_dll_directory(str(directory.resolve()))
    try:
        library = ctypes.CDLL(str(directory.resolve() / "poppler-glib.dll"))
        glib = ctypes.CDLL(str(directory.resolve() / "glib-2.0-0.dll"))
        gobject = ctypes.CDLL(str(directory.resolve() / "gobject-2.0-0.dll"))
        library.poppler_document_new_from_file.argtypes = [ctypes.c_char_p, ctypes.c_char_p, ctypes.POINTER(ctypes.c_void_p)]
        library.poppler_document_new_from_file.restype = ctypes.c_void_p
        library.poppler_document_get_page.argtypes = [ctypes.c_void_p, ctypes.c_int]
        library.poppler_document_get_page.restype = ctypes.c_void_p
        library.poppler_page_get_text.argtypes = [ctypes.c_void_p]
        library.poppler_page_get_text.restype = ctypes.c_void_p
        gobject.g_object_unref.argtypes = [ctypes.c_void_p]
        gobject.g_object_unref.restype = None
        glib.g_free.argtypes = [ctypes.c_void_p]
        glib.g_free.restype = None
        glib.g_error_free.argtypes = [ctypes.c_void_p]
        glib.g_error_free.restype = None
        error = ctypes.c_void_p()
        document = library.poppler_document_new_from_file(path.resolve().as_uri().encode("utf-8"), None, ctypes.byref(error))
        if not document:
            if error.value:
                glib.g_error_free(error)
            raise ValueError("Poppler GLib could not open the generated CMap PDF")
        page = None
        pointer = None
        try:
            page = library.poppler_document_get_page(document, 0)
            require(page, "Poppler GLib could not resolve the example page")
            pointer = library.poppler_page_get_text(page)
            require(pointer, "Poppler GLib did not extract text")
            return ctypes.string_at(pointer).decode("utf-8")
        finally:
            if pointer:
                glib.g_free(pointer)
            if page:
                gobject.g_object_unref(page)
            gobject.g_object_unref(document)
    finally:
        dll_directory.close()


def validate(path: Path, pdftotext: str, poppler_glib_bin: Path | None):
    reader = PdfReader(path, strict=True)
    require(len(reader.pages) == 1, "CMap example must have one page")
    font = reader.pages[0]["/Resources"]["/Font"]["/F2"]
    require(font["/Subtype"] == "/Type0", "Expected a composite font")
    encoding = font["/Encoding"]
    require(encoding["/Type"] == "/CMap", "Encoding must be an embedded CMap")
    require(encoding["/CMapName"] == "/PDFToolkit-OneByte-Extended", "Unexpected encoding name")
    require(encoding["/WMode"] == 0, "Example uses horizontal writing")
    base = encoding["/UseCMap"]
    require(base["/CMapName"] == "/PDFToolkit-OneByte-Base", "Missing inherited encoding")
    require(base["/CIDSystemInfo"] == encoding["/CIDSystemInfo"], "CMap collections differ")
    require(b"usecmap" not in encoding.get_data(), "A stream base must not require an unavailable external PostScript resource")
    require(b"<00> <FF>" in base.get_data(), "Source codespace must contain one-byte codes")
    require(b"<41> <46> 1" in base.get_data(), "Missing inherited CID range")
    require(b"<A4> <A6> 8" in encoding.get_data(), "Missing additional CID range")
    require(b"beginnotdefrange" in base.get_data(), "Missing explicit notdef mapping")
    unicode_stream = font["/ToUnicode"]
    unicode = unicode_stream.get_data()
    require(unicode_stream["/Type"] == "/CMap", "ToUnicode Type must identify a CMap")
    require(("/CMapName " + unicode_stream["/CMapName"] + " def").encode("ascii") in unicode, "ToUnicode CMapName differs between dictionary and program")
    require(("/WMode " + str(unicode_stream["/WMode"]) + " def").encode("ascii") in unicode, "ToUnicode WMode differs between dictionary and program")
    collection = unicode_stream["/CIDSystemInfo"]
    require(collection["/Registry"] == "Adobe" and collection["/Ordering"] == "UCS" and collection["/Supplement"] == 0, "Default ToUnicode metadata differs")
    require(b"/Registry (Adobe)" in unicode and b"/Ordering (UCS)" in unicode and b"/Supplement 0" in unicode, "ToUnicode character-collection metadata differs from program")
    require(re.search(rb"<41>\s*<46>\s*<0041>", unicode), "Missing incremental Unicode bfrange")
    require(re.search(rb"<A4>\s*<A6>\s*\[\s*<00E9>\s*<03A9>\s*<00660069>\s*\]", unicode), "Missing array Unicode bfrange/ligature")
    require(b"<20> <0020>" in unicode, "Missing bfchar mapping")
    descendant = font["/DescendantFonts"][0].get_object()
    require(descendant["/Subtype"] == "/CIDFontType2", "Expected normalized embedded TrueType descendant")
    require("/FontFile2" in descendant["/FontDescriptor"], "Font program must be embedded")
    glyph_map = descendant["/CIDToGIDMap"].get_data()
    require(all(int.from_bytes(glyph_map[cid * 2:cid * 2 + 2], "big") > 0 for cid in range(1, 11)), "Declared CIDs require actual supplied glyphs")
    expected = "ABCDEF éΩ fi"
    require(expected in reader.pages[0].extract_text(), "pypdf did not extract the non-Identity codes and ligature")
    if poppler_glib_bin is not None:
        poppler_text = poppler_glib_text(path, poppler_glib_bin)
    else:
        result = subprocess.run([pdftotext, "-enc", "UTF-8", str(path), "-"], check=True, capture_output=True, timeout=60)
        poppler_text = result.stdout.decode("utf-8")
    require(expected in poppler_text, "Poppler did not extract the non-Identity codes and ligature")
    print("CMaps: embedded one-byte encoding, inherited CID/notdef mappings, bfchar/bfrange and ligature extraction validated with pypdf and Poppler.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("path", type=Path, help="cmap-font.pdf or its containing example directory")
    parser.add_argument("--pdftotext", default="pdftotext")
    parser.add_argument("--poppler-glib-bin", type=Path, help="Explicit Windows Poppler DLL directory, when pdftotext is unavailable")
    args = parser.parse_args()
    path = args.path / "cmap-font.pdf" if args.path.is_dir() else args.path
    validate(path, args.pdftotext, args.poppler_glib_bin)
