#!/usr/bin/env python3
"""Inspect PDF2 simple-font metrics independently in actual generated examples."""
from __future__ import annotations

import argparse
import json
import math
from pathlib import Path
import tempfile

from pypdf import PdfReader, PdfWriter
from pypdf.generic import ArrayObject, DictionaryObject, FloatObject, IndirectObject, NameObject, NumberObject


def inspect(path: Path) -> int:
    reader = PdfReader(path, strict=True)
    if reader.is_encrypted:
        return 0  # Encrypted variants have their own credential-aware oracles.
    catalog = reader.trailer['/Root']
    version = max(reader.pdf_header.removeprefix('%PDF-'), str(catalog.get('/Version', '/1.0')).lstrip('/'))
    if version != '2.0':
        return 0
    queue, visited, fonts = [catalog], set(), 0
    while queue:
        value = queue.pop()
        if isinstance(value, IndirectObject):
            key = (value.idnum, value.generation)
            if key in visited:
                continue
            visited.add(key)
            value = value.get_object()
        if isinstance(value, ArrayObject):
            queue.extend(value)
        elif isinstance(value, DictionaryObject):
            queue.extend(value.values())
            if value.get('/Type') != '/Font' or value.get('/Subtype') not in ('/Type1', '/MMType1', '/TrueType'):
                continue
            first, last = value.get('/FirstChar'), value.get('/LastChar')
            first = first.get_object() if isinstance(first, IndirectObject) else first
            last = last.get_object() if isinstance(last, IndirectObject) else last
            if not isinstance(first, NumberObject) or not isinstance(last, NumberObject) or not 0 <= first <= last <= 255:
                raise ValueError(f'{path.name}: invalid or missing PDF2 simple-font range')
            widths = value.get('/Widths')
            widths = widths.get_object() if isinstance(widths, IndirectObject) else widths
            if not isinstance(widths, ArrayObject) or len(widths) != last - first + 1:
                raise ValueError(f'{path.name}: Widths does not cover FirstChar through LastChar')
            numbers = [width.get_object() if isinstance(width, IndirectObject) else width for width in widths]
            if any(not isinstance(width, (NumberObject, FloatObject)) or not math.isfinite(float(width)) for width in numbers):
                raise ValueError(f'{path.name}: nonnumeric or nonfinite width')
            descriptor = value.get('/FontDescriptor')
            if not isinstance(descriptor, IndirectObject):
                raise ValueError(f'{path.name}: FontDescriptor must be indirect')
            descriptor = descriptor.get_object()
            if not isinstance(descriptor, DictionaryObject) or descriptor.get('/Type') != '/FontDescriptor' or descriptor.get('/FontName') != value.get('/BaseFont'):
                raise ValueError(f'{path.name}: descriptor identity does not match the font')
            required = ('/Flags', '/FontBBox', '/ItalicAngle', '/Ascent', '/Descent', '/StemV')
            latin_standard = ('/Courier', '/Courier-Bold', '/Courier-Oblique', '/Courier-BoldOblique',
                              '/Helvetica', '/Helvetica-Bold', '/Helvetica-Oblique', '/Helvetica-BoldOblique',
                              '/Times-Roman', '/Times-Bold', '/Times-Italic', '/Times-BoldItalic')
            if value.get('/BaseFont') in latin_standard:
                required += ('/CapHeight',)
            if any(key not in descriptor for key in required):
                raise ValueError(f'{path.name}: incomplete FontDescriptor')
            fonts += 1
    return fonts


def controls() -> int:
    """Prove that this independent observer rejects omitted metrics and direct descriptors."""
    with tempfile.TemporaryDirectory(prefix='pdf20-font-controls-') as temporary:
        for defect in (None, 'missing-widths', 'wrong-range', 'direct-descriptor'):
            writer = PdfWriter()
            writer.pdf_header = '%PDF-2.0'
            page = writer.add_blank_page(width=200, height=200)
            descriptor = DictionaryObject({NameObject(key): value for key, value in {
                '/Type': NameObject('/FontDescriptor'), '/FontName': NameObject('/Courier'),
                '/Flags': NumberObject(33), '/FontBBox': ArrayObject([NumberObject(x) for x in (-23, -250, 715, 805)]),
                '/ItalicAngle': NumberObject(0), '/Ascent': NumberObject(629), '/Descent': NumberObject(-157),
                '/CapHeight': NumberObject(562), '/StemV': NumberObject(51),
            }.items()})
            font = DictionaryObject({NameObject(key): value for key, value in {
                '/Type': NameObject('/Font'), '/Subtype': NameObject('/Type1'), '/BaseFont': NameObject('/Courier'),
                '/FirstChar': NumberObject(32), '/LastChar': NumberObject(33 if defect == 'wrong-range' else 32),
                '/Widths': ArrayObject([NumberObject(600)]),
                '/FontDescriptor': descriptor if defect == 'direct-descriptor' else writer._add_object(descriptor),
            }.items()})
            if defect == 'missing-widths':
                del font['/Widths']
            page[NameObject('/Resources')] = DictionaryObject({NameObject('/Font'): DictionaryObject({NameObject('/F1'): writer._add_object(font)})})
            path = Path(temporary) / f'{defect or "valid"}.pdf'
            writer.write(path)
            try:
                count = inspect(path)
            except ValueError:
                if defect is None:
                    raise
            else:
                if defect is not None or count != 1:
                    raise ValueError(f'Independent font control failed: {defect}')
    return 3


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    args = parser.parse_args()
    results = {path.name: inspect(path) for path in sorted(args.directory.glob('*.pdf'))}
    if sum(results.values()) == 0:
        raise SystemExit('No PDF2 simple fonts were exercised.')
    print(json.dumps({'simple_fonts': sum(results.values()), 'pdfs_with_simple_fonts': sum(count > 0 for count in results.values()), 'rejected_negative_controls': controls()}))
