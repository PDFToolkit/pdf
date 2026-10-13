"""Independent ISO 32000-1 colour/mesh oracle; no PHP implementation is imported.

Usage: python tools/validate_colour_patterns.py example.pdf mesh-vectors.json
The JSON comes from tools/fixtures/colour_meshes.php. Vertex byte alignment and
continuous patch records follow 8.7.4.5.5-8, physical PDF pages 198-209.
"""

from __future__ import annotations

import hashlib
import json
import sys
from pathlib import Path

from pypdf import PdfReader


class Bits:
    def __init__(self, data: bytes):
        self.data = data
        self.position = 0

    def read(self, count: int) -> int:
        assert self.position + count <= 8 * len(self.data), "truncated mesh field"
        value = 0
        for _ in range(count):
            value = (value << 1) | ((self.data[self.position // 8] >> (7 - self.position % 8)) & 1)
            self.position += 1
        return value

    def align(self) -> None:
        padding = (-self.position) % 8
        assert self.read(padding) == 0, "nonzero alignment padding"

    def finish(self) -> None:
        self.align()
        assert self.position == len(self.data) * 8, "unexpected trailing mesh records"


def patch_records(data: bytes, tensor: bool, flags: list[int], coordinate_bits: int = 1, component_bits: int = 1, components: int = 1, flag_bits: int = 2):
    bits = Bits(data)
    records = []
    for flag in flags:
        assert bits.read(flag_bits) == flag
        controls = (16 if tensor else 12) if flag == 0 else (12 if tensor else 8)
        points = [(bits.read(coordinate_bits), bits.read(coordinate_bits)) for _ in range(controls)]
        colours = [tuple(bits.read(component_bits) for _ in range(components)) for _ in range(4 if flag == 0 else 2)]
        records.append((points, colours))
    bits.finish()
    return records


def validate_vectors(path: Path) -> None:
    vectors = json.loads(path.read_text(encoding="utf-8-sig"))
    assert vectors["triangle"] == "0820187088"
    assert vectors["lattice"] == "208060c0"
    assert vectors["coons"] == "00000029000080"
    assert vectors["tensor"] == "000000002900000080"
    for name, tensor, flags in [("coons", False, [0, 1]), ("tensor", True, [0, 1]), ("coons_all_edges", False, [0, 1, 2, 3]), ("tensor_all_edges", True, [0, 1, 2, 3])]:
        records = patch_records(bytes.fromhex(vectors[name]), tensor, flags)
        for index, (points, colours) in enumerate(records):
            assert all(point == (0, 0) for point in points)
            assert colours == ([(1,), (0,), (1,), (0,)] if index == 0 else [(1,), (0,)])


def validate_pdf(path: Path) -> None:
    reader = PdfReader(path, strict=True)
    assert len(reader.pages) == 2
    resources = reader.pages[0]["/Resources"]
    spaces = resources["/ColorSpace"]
    expected = {"/CalGray", "/CalRGB", "/Lab", "/ICCBased", "/Indexed", "/Separation", "/DeviceN", "/Pattern", "/Stencil"}
    assert set(spaces) == expected
    for key in expected - {"/Stencil"}:
        assert spaces[key][0] == key
    assert spaces["/Stencil"] == ["/Pattern", "/DeviceRGB"]
    assert spaces["/Indexed"][1:3] == ["/DeviceRGB", 2]
    palette = spaces["/Indexed"][3]
    assert palette.original_bytes == bytes.fromhex("ef52524fa7864275cf")
    profile = spaces["/ICCBased"][1].get_object()
    assert profile["/N"] == 3 and profile["/Alternate"] == "/DeviceRGB"
    metadata = json.loads((Path(__file__).resolve().parents[1] / "tests/Fixtures/graphics/srgb.json").read_text(encoding="utf-8"))
    assert hashlib.sha256(profile.get_data()).hexdigest() == metadata["sha256"]
    assert profile.get_data()[16:20] == b"RGB " and profile.get_data()[36:40] == b"acsp"
    assert spaces["/Separation"][1] == "/Orange"
    assert spaces["/Separation"][3].get_object()["/C1"] == [1, 0.35, 0.05]
    assert spaces["/DeviceN"][1] == ["/MixA", "/MixB"]
    shadings = resources["/Shading"]
    assert len(shadings) == 7
    lengths = {4: 48, 5: 28, 6: 61, 7: 77}
    for kind in range(1, 8):
        shading = shadings[f"/Sh{kind}"].get_object()
        assert shading["/ShadingType"] == kind and shading["/ColorSpace"] == "/DeviceRGB"
        assert shading["/BBox"] == [0, 0, 1, 1]
        if kind <= 3:
            function = shading["/Function"].get_object()
            assert function["/FunctionType"] == (4 if kind == 1 else 2)
            assert function["/Range"] == [0, 1, 0, 1, 0, 1]
            if kind == 1:
                assert function.get_data() == b"{ 1 2 index sub }"
            continue
        data = shading.get_data()
        assert len(data) == lengths[kind]
        assert shading["/BitsPerCoordinate"] == 16 and shading["/BitsPerComponent"] == 8
        assert shading["/Decode"] == [0, 1] * 5
        if kind in (4, 5):
            bits = Bits(data)
            vertices = []
            for _ in range(6 if kind == 4 else 4):
                if kind == 4:
                    assert bits.read(8) == 0
                vertices.append(((bits.read(16), bits.read(16)), tuple(bits.read(8) for _ in range(3))))
                bits.align()
            bits.finish()
            assert vertices[0] == ((0, 0), (255, 46, 71))
            assert vertices[1] == ((65535, 0), (255, 204, 38))
            assert vertices[2] == ((0, 65535), (51, 102, 255))
            if kind == 5:
                assert shading["/VerticesPerRow"] == 2
                assert vertices[3] == ((65535, 65535), (26, 204, 140))
        else:
            records = patch_records(data, kind == 7, [0], 16, 8, 3, 8)
            points, colours = records[0]
            assert points[0] == (0, 0) and points[3] == (0, 65535) and points[6] == (65535, 65535) and points[9] == (65535, 0)
            assert colours == [(255, 46, 71), (26, 204, 140), (51, 102, 255), (255, 204, 38)]
            if kind == 7:
                assert points[12] == (39321, 21845)
    patterns = resources["/Pattern"]
    assert patterns["/ColouredTile"]["/PaintType"] == 1
    assert patterns["/StencilTile"]["/PaintType"] == 2
    assert b" rg" not in patterns["/StencilTile"].get_data()
    assert patterns["/GradientTile"]["/PatternType"] == 2
    assert patterns["/GradientTile"]["/Shading"]["/ShadingType"] == 2
    for page in reader.pages:
        text = page.extract_text()
        assert text and ("PDF" in text or "shading" in text)


if __name__ == "__main__":
    validate_pdf(Path(sys.argv[1]))
    validate_vectors(Path(sys.argv[2]))
    print("Independent colour/pattern oracle passed: 2 pages, 7 shadings, ICC payload and 6 mesh vectors.")
