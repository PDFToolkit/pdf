"""Check selected Type 3 CharProcs, auxiliary embedding and actual rendered modes."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

from pypdf import PdfReader
from pypdf.generic import ContentStream


def run(*arguments):
    result = subprocess.run(list(map(str, arguments)), capture_output=True)
    if result.returncode:
        raise AssertionError(result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace'))


def ppm(path):
    data = path.read_bytes()
    header = re.match(rb'P6\s+(\d+)\s+(\d+)\s+255\s', data)
    assert header
    width, height = int(header[1]), int(header[2])
    data = data[header.end():]
    assert len(data) == width * height * 3

    def pixel(x, y):
        offset = ((height - 1 - y) * width + x) * 3
        return tuple(data[offset:offset + 3])

    return width, height, pixel


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--qpdf', required=True)
    parser.add_argument('--pdftoppm', default='pdftoppm')
    parser.add_argument('--work-dir', required=True, type=Path)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    coverage = json.loads((root / 'resources/wtpdf-1.0/type3-usage.json').read_text())
    for source in coverage['sources']:
        assert hashlib.sha256((root / source['path']).read_bytes()).hexdigest() == source['sha256']
    for path in coverage['implementation'] + coverage['evidence']:
        assert (root / path).is_file(), path
    work = args.work_dir.resolve()
    work.mkdir(parents=True, exist_ok=True)
    run(args.php, root / 'tools/fixtures/wtpdf-type3-usage.php', work)
    native = json.loads((work / 'native-findings.json').read_text())
    cases = {
        'a-visible': (b'A', 0, (0, 255, 0), ['8.4.5.5.1']),
        'b-visible': (b'B', 0, (0, 0, 255), []),
        'a-invisible': (b'A', 3, (255, 255, 255), []),
        'a-clip-only': (b'A', 7, (255, 255, 255), []),
        'empty': (b'', 0, (255, 255, 255), []),
    }
    assert set(native) == set(cases)
    observations = []
    normative_pixel_checks = 0
    mode7_rendered_by_poppler = False
    for name, (text, mode, colour, failures) in cases.items():
        path = work / (name + '.pdf')
        run(args.qpdf, '--check', path)
        pdf = PdfReader(path, strict=True)
        assert pdf.pdf_header == '%PDF-2.0' and len(pdf.pages) == 1
        page = pdf.pages[0]
        operations = ContentStream(page['/Contents'], pdf).operations
        assert [int(values[0]) for values, operator in operations if operator == b'Tr'] == [mode]
        assert [values[0].original_bytes for values, operator in operations if operator == b'Tj'] == [text]
        font = page['/Resources']['/Font']['/Outer']
        assert font['/Subtype'] == '/Type3'
        assert list(font['/Encoding']['/Differences']) == [65, '/A', '/B']
        assert list(font['/FontMatrix']) == [0.001, 0, 0, 0.001, 0, 0]
        auxiliary = font['/Resources']['/Font']['/Aux']
        assert auxiliary['/Subtype'] == '/Type1' and auxiliary['/BaseFont'] == '/Courier'
        assert not any(key in auxiliary['/FontDescriptor'] for key in ['/FontFile', '/FontFile2', '/FontFile3'])
        glyphs = font['/CharProcs']
        first = ContentStream(glyphs['/A'], pdf).operations
        second = ContentStream(glyphs['/B'], pdf).operations
        assert [str(values[0]) for values, operator in first if operator == b'Tf'] == ['/Aux']
        assert not any(operator in [b'Tf', b'Tj'] for _, operator in second)
        assert native[name]['textHex'] == text.hex() and native[name]['mode'] == mode
        assert native[name]['failureClauses'] == failures
        assert not any('selected Type3 CharProc' in message for message in native[name]['pending'])
        prefix = work / (name + '-page')
        run(args.pdftoppm, '-r', 72, '-singlefile', path, prefix)
        width, height, pixel = ppm(prefix.with_suffix('.ppm'))
        assert (width, height) == (200, 200)
        # ISO2 9.3.6 (physical p.320) suppresses Type 3 descriptions in mode 7.
        # Some Poppler versions instead execute them. Keep the emitted Tr=7
        # and source-based native check intact; disclose that renderer limit
        # rather than counting its legacy behaviour as normative confirmation.
        if mode == 7:
            observed = {pixel(x, y) for x, y in [(60, 140), (140, 60), (140, 140)]}
            assert observed in [{(255, 255, 255)}, {(0, 255, 0)}], observed
            mode7_rendered_by_poppler = observed == {(0, 255, 0)}
        for x, y in [(60, 140), (140, 60), (140, 140)]:
            if mode != 7 or not mode7_rendered_by_poppler:
                assert pixel(x, y) == colour, (name, x, y, pixel(x, y), colour)
                normative_pixel_checks += 1
        for x, y in [(25, 25), (175, 175)]:
            assert pixel(x, y) == (255, 255, 255)
            normative_pixel_checks += 1
        observations.append({'file': path.name, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
            'textHex': text.hex(), 'mode': mode, 'embeddingFailures': failures,
            'qpdf': True, 'pypdf': True, 'popplerPixelObservations': 5})
    version = subprocess.run([args.pdftoppm, '-v'], capture_output=True, text=True, check=True)
    report = {'status': 'pass', 'pdfs': len(observations), 'pages': len(observations),
        'pixelChecks': normative_pixel_checks, 'pixelObservations': 5 * len(observations), 'observations': observations,
        'scope': 'Actual selected native Type 3 programs and auxiliary font use, including PDF2 modes 3/7.',
        'notAConformanceCertificate': True, 'fontProgramLegalityAndValidation': 'external',
        'sourceHashesVerified': True,
        'popplerVersion': (version.stderr + version.stdout).splitlines()[0],
        'popplerExecutesType3InMode7': mode7_rendered_by_poppler,
        'mode7Boundary': 'Emitted Tr=7 and native ISO2 rules checked; Poppler suppression is not claimed when its renderer executes this glyph.'}
    (work / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
