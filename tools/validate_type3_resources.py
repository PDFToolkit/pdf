"""Independently inspect and render EC3 #128 glyph/font/page resource priority."""
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


def pixel_reader(path):
    data = path.read_bytes()
    header = re.match(rb'P6\s+(?:#[^\n]*\n\s*)?(\d+)\s+(\d+)\s+255\s', data)
    assert header, path
    width, height = map(int, header.groups())
    pixels = data[header.end():]
    assert len(pixels) == width * height * 3

    def pixel(x, y):
        offset = ((height - 1 - y) * width + x) * 3
        return tuple(pixels[offset:offset + 3])

    return width, height, pixel


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--qpdf', required=True)
    parser.add_argument('--pdftoppm', default='pdftoppm')
    parser.add_argument('--ghostscript', default='gs')
    parser.add_argument('--work-dir', required=True, type=Path)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    source = root / 'resources/reference-documents/ISO_32000-2_sponsored_EC3.pdf'
    assert hashlib.sha256(source.read_bytes()).hexdigest() == '90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230'
    work = args.work_dir.resolve()
    work.mkdir(parents=True, exist_ok=True)
    run(args.php, root / 'tools/fixtures/type3-resources.php', work)
    expected = {'glyph': (0, 255, 0), 'font': (255, 0, 0), 'page': (0, 0, 255), 'inherited-page': (0, 0, 255), 'nested-form': (0, 0, 255)}
    observations = []
    pixel_checks = 0
    for name, colour in expected.items():
        path = work / (name + '.pdf')
        run(args.qpdf, '--check', path)
        pdf = PdfReader(path, strict=True)
        assert pdf.pdf_header == '%PDF-2.0' and len(pdf.pages) == 1
        page = pdf.pages[0]
        resources = page['/Resources']
        font = resources['/Font']['/Outer']
        assert font['/Subtype'] == '/Type3'
        glyph = font['/CharProcs']['/A']
        assert [op for _, op in ContentStream(glyph, pdf).operations] == [b'd0', b'Do']
        assert ('/Resources' in glyph) == (name == 'glyph')
        assert ('/Resources' in font) == (name in ['glyph', 'font'])
        selected = glyph.get('/Resources', font.get('/Resources', resources))
        ink = selected['/XObject']['/Ink']
        assert ink['/Subtype'] == '/Form' and ink['/Resources'] == {}
        painted = [tuple(int(v) * 255 for v in values) for values, op in ContentStream(ink, pdf).operations if op == b'rg']
        assert painted == [colour]
        if name == 'nested-form':
            nested = resources['/XObject']['/Nested']
            alternate = nested['/Resources']['/XObject']['/Ink']
            assert alternate.indirect_reference != ink.indirect_reference
        run(args.pdftoppm, '-r', 72, '-singlefile', path, work / (name + '-poppler'))
        run(args.ghostscript, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=ppmraw', '-r72', '-sOutputFile=' + str(work / (name + '-gs.ppm')), path)
        row = {'file': path.name, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(), 'expectedRgb': colour, 'qpdf': True, 'pypdf': True}
        for engine, suffix in [('Poppler', '-poppler.ppm'), ('Ghostscript', '-gs.ppm')]:
            width, height, pixel = pixel_reader(work / (name + suffix))
            assert (width, height) == (200, 200)
            observed = {pixel(x, y) for x, y in [(60, 60), (100, 100), (140, 140)]}
            assert len(observed) == 1
            actual = next(iter(observed))
            row[engine + 'Rgb'] = actual
            if engine == 'Poppler' and name == 'nested-form' and actual == (255, 255, 0):
                row['popplerBoundary'] = 'This renderer inherits the enclosing Form resource for the resource-less Type3 font; EC3 #128 specifies the parent page. This observation is not normative confirmation.'
            else:
                assert actual == colour, (name, engine, actual, colour)
                pixel_checks += 3
            for x, y in [(25, 25), (175, 175)]:
                assert pixel(x, y) == (255, 255, 255)
                pixel_checks += 1
        observations.append(row)
    report = {'status': 'pass', 'sourceHashVerified': True, 'physicalSourcePages': [127, 128, 333, 334], 'issue': 128, 'pdfs': 5, 'qpdfChecks': 5, 'pypdfChecks': 5, 'renderedPages': 10, 'normativePixelChecks': pixel_checks, 'observations': observations, 'scope': 'Explicit Type3 glyph > font > inherited page priority. Native graph, frozen-revision and WTPDF checks are tested separately. No complete renderer/font-program/WTPDF certification is claimed.'}
    report['ghostscriptVersion'] = subprocess.run([args.ghostscript, '--version'], capture_output=True, text=True, check=True).stdout.strip()
    poppler_version = subprocess.run([args.pdftoppm, '-v'], capture_output=True, text=True, check=True)
    report['popplerVersion'] = (poppler_version.stderr + poppler_version.stdout).splitlines()[0]
    (work / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
