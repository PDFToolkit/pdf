#!/usr/bin/env python3
"""Native hybrid references: modern reader, classic-only fallback, complete required page/font graph.

qpdf is an independent test oracle, not a runtime dependency. Its classic-only
view checks visibility and graceful omission of hidden optional objects; this is
not a claim that all PDF1.7 content is renderable by a historical PDF1.4 program.
"""

import argparse
import json
from pathlib import Path
import subprocess
import tempfile

from pypdf import PdfReader
from pypdf.generic import IndirectObject, NullObject

from validate_linearization import validate as validate_linearization


def run(command):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
    return result.stdout


def visible_page_graph(reader):
    assert len(reader.pages) == 1
    page = reader.pages[0]
    assert page['/Contents'].get_data() == b'BT /F1 12 Tf 20 250 Td (Hybrid) Tj ET'
    resources = page['/Resources']
    font = resources['/Font']['/F1']
    assert font['/Type'] == '/Font' and font['/Subtype'] == '/Type1'
    assert font['/BaseFont'] == '/Helvetica'
    assert list(font['/Widths']) == [500] * 256
    assert font['/FontDescriptor']['/FontName'] == '/Helvetica'
    assert font['/FontDescriptor']['/Ascent'] == 718
    for owner, key in [(reader.trailer, '/Root'), (reader.trailer['/Root'], '/Pages'),
                       (page, '/Resources'), (resources['/Font'], '/F1'),
                       (font, '/Widths'), (font, '/FontDescriptor')]:
        reference = owner.raw_get(key)
        assert isinstance(reference, IndirectObject)
        assert reference.idnum not in reader.xref_objStm, (key, 'required graph is compressed')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-hybrid-') as temporary:
        directory = Path(temporary)
        run([args.php, root / 'tools/fixtures/hybrid_references.php', directory])
        cases = json.loads((directory / 'manifest.json').read_text())
        for case in cases:
            path = directory / case['file']
            if case['previous']:
                assert path.read_bytes().startswith((directory / case['previous']).read_bytes())
            modern = PdfReader(path, strict=True)
            visible_page_graph(modern)
            assert modern.trailer['/Root']['/Outlines']['/First']['/Title'] == 'Hybrid'
            if case['pieceValue'] is not None:
                assert modern.trailer['/Root']['/PieceInfo']['/Private'] == case['pieceValue']
            run([args.qpdf, '--suppress-recovery', '--check', path])
            if case['linearized']:
                validate_linearization(path)
                run([args.qpdf, '--check-linearization', path])
            if not case['linearized']:
                run([args.qpdf, '--ignore-xref-streams', '--suppress-recovery', '--check', path])
            legacy_path = directory / ('legacy-' + case['file'])
            run([args.qpdf, '--ignore-xref-streams', '--suppress-recovery', '--object-streams=disable', path, legacy_path])
            # qpdf --check also checks modern outline hints even when its
            # classic-only view omits that outline. Check the rebuilt ordinary
            # classic view instead; reading the source still forbids recovery.
            run([args.qpdf, '--check', legacy_path])
            legacy = PdfReader(legacy_path, strict=True)
            visible_page_graph(legacy)
            outlines = legacy.trailer['/Root'].get('/Outlines')
            if case['outlineHidden']:
                assert outlines is None or isinstance(outlines.get_object(), NullObject)
            else:
                assert outlines.get_object()['/First']['/Title'] == 'Hybrid'
            piece = legacy.trailer['/Root'].get('/PieceInfo')
            if case['pieceHidden']:
                assert piece is None or isinstance(piece.get_object(), NullObject)
            elif case['pieceValue'] is not None:
                assert piece.get_object()['/Private'] == case['pieceValue']
        print(len(cases), 'native hybrid initial/incremental/linearized files: unmodified modern and classic-only readers, complete page/font/descriptor/width graph, hidden optionals and preserved revision bytes verified')


if __name__ == '__main__':
    main()
