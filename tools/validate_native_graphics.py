"""Independent ISO image/halftone/optional-content/blend oracle.

Usage: python tools/validate_native_graphics.py native-graphics.pdf vectors.json
No PHP implementation is imported. Float expectations follow Tables136/137 and
the basic compositing formula; sample expectations inspect actual stream octets.
"""

from __future__ import annotations

import json
import math
import struct
import sys
from pathlib import Path

from pypdf import PdfReader


def luminosity(colour):
    return 0.30 * colour[0] + 0.59 * colour[1] + 0.11 * colour[2]


def set_lum(colour, value):
    delta = value - luminosity(colour)
    colour = [c + delta for c in colour]
    lum, low, high = luminosity(colour), min(colour), max(colour)
    if low < 0:
        colour = [lum + (c - lum) * lum / (lum - low) for c in colour]
    if high > 1:
        colour = [lum + (c - lum) * (1 - lum) / (high - lum) for c in colour]
    return colour


def set_sat(colour, value):
    ordered = sorted(range(3), key=lambda i: colour[i])
    low, middle, high = ordered
    result = list(colour)
    if colour[high] > colour[low]:
        result[middle] = (colour[middle] - colour[low]) * value / (colour[high] - colour[low])
        result[high] = value
    else:
        result[middle] = result[high] = 0
    result[low] = 0
    return result


def scalar(mode, b, s):
    if mode in ("Normal", "Compatible"):
        return s
    if mode == "Multiply":
        return b * s
    if mode == "Screen":
        return 1 - (1 - b) * (1 - s)
    if mode == "Overlay":
        return scalar("HardLight", s, b)
    if mode == "Darken":
        return min(b, s)
    if mode == "Lighten":
        return max(b, s)
    if mode == "ColorDodge":
        return 1 if s == 1 else min(1, b / (1 - s))
    if mode == "ColorBurn":
        return 0 if s == 0 else 1 - min(1, (1 - b) / s)
    if mode == "HardLight":
        return b * (2 * s) if s <= .5 else 1 - (1 - b) * (2 - 2 * s)
    if mode == "SoftLight":
        if s <= .5:
            return b - (1 - 2 * s) * b * (1 - b)
        d = ((16 * b - 12) * b + 4) * b if b <= .25 else math.sqrt(b)
        return b + (2 * s - 1) * (d - b)
    if mode == "Difference":
        return abs(b - s)
    if mode == "Exclusion":
        return b + s - 2 * b * s
    raise AssertionError(mode)


def blend(mode, backdrop, source, subtractive):
    b = [1 - c for c in backdrop] if subtractive else backdrop
    s = [1 - c for c in source] if subtractive else source
    if mode in ("Hue", "Saturation", "Color", "Luminosity"):
        if mode == "Hue":
            result = set_lum(set_sat(s[:3], max(b[:3]) - min(b[:3])), luminosity(b))
        elif mode == "Saturation":
            result = set_lum(set_sat(b[:3], max(s[:3]) - min(s[:3])), luminosity(b))
        elif mode == "Color":
            result = set_lum(s[:3], luminosity(b))
        else:
            result = set_lum(b[:3], luminosity(s))
        if len(b) == 4:
            result.append(s[3] if mode == "Luminosity" else b[3])
    else:
        result = [scalar(mode, cb, cs) for cb, cs in zip(b, s)]
    return [1 - c for c in result] if subtractive else result


def validate_vectors(path):
    corpus = json.loads(path.read_text(encoding="utf-8-sig"))
    assert corpus["image_hex"] == {"1": "40a0", "2": "30cc", "4": "0f00f0f0", "8": "00ff00ff00ff", "16": "0000ffff0000ffff0000ffff"}
    assert len(corpus["blend_cases"]) == 34
    for case in corpus["blend_cases"]:
        expected = blend(case["mode"], case["backdrop"], case["source"], case["space"] == "cmyk")
        assert all(math.isclose(a, b, abs_tol=1e-12) for a, b in zip(expected, case["blend"]))
        # Independent premultiplied weighting: normalize once by alpha=.76.
        alpha = .4 + .6 - .4 * .6
        assert math.isclose(case["alpha"], alpha, abs_tol=1e-12)
        for i, actual in enumerate(case["composite"]):
            expected_component = (.4 * .4 * case["backdrop"][i] + .6 * .6 * case["source"][i] + .4 * .6 * expected[i]) / alpha
            assert math.isclose(actual, expected_component, abs_tol=1e-12)
    assert len(corpus["policies"]) == 16
    for case in corpus["policies"]:
        a, b = case["a"], case["b"]
        assert case["visible"] == {"AllOn": a and b, "AnyOn": a or b, "AnyOff": not a or not b, "AllOff": not a and not b}[case["policy"]]


def validate_pdf(path):
    reader = PdfReader(path, strict=True)
    assert len(reader.pages) == 2
    resources = reader.pages[0]["/Resources"]
    objects = resources["/XObject"]
    assert set(objects) == {"/Stencil1", "/Indexed2", "/Gray4", "/Rgb16", "/ColourKey", "/SoftMask", "/Matte", "/Multiply", "/Screen", "/Luminosity"}
    mask = objects["/Stencil1"].get_object()
    assert mask["/ImageMask"] and "/ColorSpace" not in mask and mask["/BitsPerComponent"] == 1
    assert mask.get_data()[:4] == bytes.fromhex("5555aaaa")
    indexed = objects["/Indexed2"].get_object()
    assert indexed["/ColorSpace"][0] == "/Indexed" and indexed["/BitsPerComponent"] == 2
    assert indexed.get_data()[:4] == bytes.fromhex("0055aaff")
    gray = objects["/Gray4"].get_object()
    assert gray["/BitsPerComponent"] == 4 and gray.get_data()[:8] == bytes.fromhex("0123456789abcdef")
    rgb = objects["/Rgb16"].get_object()
    assert rgb["/BitsPerComponent"] == 16 and rgb["/ColorSpace"] == "/DeviceRGB"
    assert len(rgb.get_data()) == 1536
    assert struct.unpack(">HHH", rgb.get_data()[:6]) == (0, 0, 32768)
    assert struct.unpack(">HHH", rgb.get_data()[-6:]) == (65535, 65535, 32768)
    key = objects["/ColourKey"].get_object()
    assert key["/Mask"] == [255] * 6 and key.get_data()[:3] == b"\xff\xff\xff"
    assert key.get_data()[3 * (16 * 8 + 8):3 * (16 * 8 + 8) + 3] == bytes([240, 70, 85])
    soft = objects["/SoftMask"]["/SMask"].get_object()
    assert (soft["/Width"], soft["/Height"]) == (4, 1) and soft.get_data() == bytes([0, 85, 170, 255])
    matte = objects["/Matte"].get_object()
    assert matte["/SMask"]["/Matte"] == [1, 1, 1]
    assert matte["/SMask"]["/Width"] == matte["/Width"] == 16
    assert matte.get_data()[:3] == b"\xff\xff\xff" and matte.get_data()[45:48] == bytes([20, 130, 230])
    for name, mode in [("/Multiply", "/Multiply"), ("/Screen", "/Screen")]:
        form = objects[name].get_object()
        assert form["/Subtype"] == "/Form" and form["/Group"]["/S"] == "/Transparency"
        assert form["/Group"]["/I"] and form["/Group"]["/CS"] == "/DeviceRGB"
        state = form["/Resources"]["/ExtGState"][mode].get_object()
        assert state["/BM"] == mode and state["/ca"] == .75
    luminosity = objects["/Luminosity"]["/Resources"]["/ExtGState"]["/Mask"]["/SMask"]
    assert luminosity["/S"] == "/Luminosity" and luminosity["/G"]["/Group"]["/CS"] == "/DeviceGray"
    assert luminosity["/G"]["/Group"]["/I"]
    states = resources["/ExtGState"]
    eight = states["/Halftone8"]["/HT"]
    sixteen = states["/Halftone16"]["/HT"]
    assert eight["/HalftoneType"] == 6 and eight.get_data() == bytes.fromhex("0055aaff")
    assert sixteen["/HalftoneType"] == 16 and sixteen.get_data() == bytes.fromhex("1234ffff")
    props = reader.trailer["/Root"]["/OCProperties"]
    assert len(props["/OCGs"]) == 2 and props["/D"]["/BaseState"] == "/ON"
    assert props["/D"]["/Intent"] == "/View" and len(props["/D"]["/OFF"]) == 1
    assert props["/D"]["/OFF"][0].get_object()["/Name"] == "Couche masquee"
    membership = resources["/Properties"]["/Condition"]
    assert membership["/Type"] == "/OCMD" and membership["/VE"][0] == "/And" and membership["/VE"][2][0] == "/Not"
    for page in reader.pages:
        assert page.extract_text()


if __name__ == "__main__":
    validate_pdf(Path(sys.argv[1]))
    validate_vectors(Path(sys.argv[2]))
    print("Independent native-graphics oracle passed: 2 pages, 7 images, forms/masks, threshold streams, OCG/VE, 34 blend/composite vectors, 16 policies.")
