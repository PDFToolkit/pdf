"""Check PDF2 AP exceptions, exact appearance dictionaries and independent rendered pixels."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

from pypdf import PdfReader


def run(*arguments):
    result = subprocess.run(list(map(str, arguments)), capture_output=True)
    if result.returncode:
        raise AssertionError(result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace'))


def pixel(path, x, y):
    data = path.read_bytes()
    header = re.match(rb'P6\s+(\d+)\s+(\d+)\s+255\s', data)
    assert header and (int(header[1]), int(header[2])) == (100, 100)
    samples = data[header.end():]
    assert len(samples) == 30_000
    offset = ((99 - y) * 100 + x) * 3
    return tuple(samples[offset:offset + 3])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--qpdf', required=True)
    parser.add_argument('--pdftoppm', default='pdftoppm')
    parser.add_argument('--bootstrap', type=Path)
    parser.add_argument('--work-dir', type=Path, required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    resource = json.loads((root / 'resources/annotation-appearances/coverage.json').read_text(encoding='utf-8'))
    source = resource['source']
    assert hashlib.sha256((root / source['path']).read_bytes()).hexdigest() == source['sha256']
    work = args.work_dir.resolve()
    work.mkdir(parents=True, exist_ok=True)
    command = [args.php, root / 'tools/fixtures/annotation-appearances.php', work]
    if args.bootstrap:
        command.append(args.bootstrap.resolve())
    run(*command)
    native = json.loads((work / 'native.json').read_text(encoding='utf-8'))
    assert len(native['rejected']) == 11 and all(native['rejected'].values())
    checks = []
    pixel_checks = 0
    for case in native['files']:
        path = work / case['file']
        run(args.qpdf, '--check', path)
        reader = PdfReader(path, strict=True)
        assert len(reader.pages) == 1
        annotation = reader.pages[0]['/Annots'][0].get_object()
        if case['file'] == 'pdf17-optional.pdf':
            assert reader.pdf_header == '%PDF-1.7' and '/AP' not in annotation
        elif case['file'] == 'pdf20-point.pdf':
            assert list(annotation['/Rect']) == [42, 17, 42, 17] and '/AP' not in annotation
        elif case['file'] == 'pdf20-link.pdf':
            assert annotation['/Subtype'] == '/Link' and '/AP' not in annotation
        else:
            normal = annotation['/AP']['/N']
            if case['file'] in ['pdf20-named.pdf', 'pdf20-undefined.pdf']:
                assert list(normal) == ['/On']
                assert annotation['/AS'] == ('/On' if case['greenAppearance'] else '/Missing')
                normal = normal['/On']
            assert normal['/Subtype'] == '/Form' and list(normal['/BBox']) == [0, 0, 20, 20]
            assert b'0 1 0 rg' in normal.get_data()
        if case['file'] == 'pdf17-upgraded-fixed.pdf':
            assert reader.pdf_header == '%PDF-1.7' and reader.trailer['/Root']['/Version'] == '/2.0'
            assert b'/Prev ' in path.read_bytes()
        if case['greenAppearance']:
            prefix = work / path.stem
            run(args.pdftoppm, '-r', 72, '-singlefile', path, prefix)
            ppm = prefix.with_suffix('.ppm')
            for x, y in [(30, 30), (50, 45), (70, 60)]:
                assert pixel(ppm, x, y) == (0, 255, 0)
                pixel_checks += 1
            for x, y in [(10, 45), (90, 45), (50, 10), (50, 90)]:
                assert pixel(ppm, x, y) == (255, 255, 255)
                pixel_checks += 1
        checks.append({'file': path.name, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(), 'qpdf': True, 'pypdf': True, 'greenAppearance': case['greenAppearance']})
    report = {'status': 'pass', 'pdfs': len(checks), 'nativeRejected': len(native['rejected']), 'pixelChecks': pixel_checks,
        'cases': checks, 'sourceHashVerified': True, 'ec3Reviews': [22, 124],
        'boundary': 'AP/Form structure and pixels do not execute rollover interactions, infer missing state appearances or certify full PDF conformance.'}
    assert report['pdfs'] == 8 and pixel_checks == 28
    (work / 'report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
