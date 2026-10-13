"""Rebuild the original MIT-licensed font fixture with fontTools 4.62.0.

This development tool is not a library or test-suite dependency. The committed
font is deliberately tiny: known metrics, aliases and a supplementary character.
"""

from pathlib import Path

from fontTools.fontBuilder import FontBuilder
from fontTools.pens.ttGlyphPen import TTGlyphPen


def rectangle(left, bottom, right, top):
    pen = TTGlyphPen(None)
    pen.moveTo((left, bottom))
    pen.lineTo((right, bottom))
    pen.lineTo((right, top))
    pen.lineTo((left, top))
    pen.closePath()
    return pen.glyph()


font = FontBuilder(2048, isTTF=True)
order = [".notdef", "space", "A", "H", "smile", "B"]
font.setupGlyphOrder(order)
characters = {32: "space", 65: "A", 66: "B", 72: "H", 0x391: "A", 0x1F642: "smile"}
characters.update({point: "A" for point in range(0x100, 0x180)})
font.setupCharacterMap(characters)
font.setupGlyf({
    ".notdef": rectangle(80, 0, 900, 1400),
    "space": TTGlyphPen(None).glyph(),
    "A": rectangle(80, 0, 960, 1500),
    "H": rectangle(80, 0, 1360, 1500),
    "smile": rectangle(80, -100, 1200, 1200),
    "B": rectangle(80, 0, 1200, 1500),
})
font.setupHorizontalMetrics({".notdef": (1024, 80), "space": (512, 0), "A": (1024, 80), "H": (1440, 80), "smile": (1280, 80), "B": (1280, 80)})
font.setupHorizontalHeader(ascent=1600, descent=-400)
font.setupNameTable({"familyName": "PDF Fixture", "styleName": "Regular", "uniqueFontIdentifier": "PDFFixture-Regular-1", "fullName": "PDF Fixture Regular", "psName": "PDFFixture-Regular", "version": "Version 1.0"})
font.setupOS2(version=2, sTypoAscender=1600, sTypoDescender=-400, usWinAscent=1600, usWinDescent=400, sCapHeight=1500, sxHeight=1000, fsType=0)
font.setupPost()
font.setupMaxp()
font.font["head"].created = 2082844800
font.font["head"].modified = 2082844800
font.font.recalcTimestamp = False
font.save(Path(__file__).with_name("PDFFixture-Regular.ttf"))
