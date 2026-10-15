"""Independent source-byte, reachable-object, text and Poppler pixel checks for applied redaction."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import subprocess

from pypdf import PdfReader
from pypdf.generic import ArrayObject, DictionaryObject, IndirectObject, StreamObject


def run(*arguments: str) -> None:
    subprocess.run(arguments, check=True, capture_output=True, encoding='utf-8')


def ppm(path: Path):
    import re
    data = path.read_bytes()
    match = re.match(rb'P6\s+(\d+)\s+(\d+)\s+255\s', data)
    assert match is not None
    width, height = int(match[1]), int(match[2])
    pixels = data[match.end():]
    assert len(pixels) == width * height * 3
    return width, height, pixels


def inspect(path: Path) -> dict:
    raw = path.read_bytes()
    assert raw.count(b'startxref') == raw.count(b'%%EOF') == 1
    assert b'SECRET' not in raw and b'/Prev' not in raw
    pdf = PdfReader(path, strict=True)
    assert len(pdf.pages) == 2
    root = pdf.trailer['/Root']
    assert set(root) == {'/Type', '/Pages'}
    assert '/Info' not in pdf.trailer and '/Encrypt' not in pdf.trailer
    assert 'PUBLIC PAGE 1' in pdf.pages[0].extract_text()
    assert 'REDACTED' in pdf.pages[1].extract_text()
    assert 'SECRET' not in ''.join(page.extract_text() for page in pdf.pages)
    pending = [pdf.trailer]
    seen = set()
    streams = 0
    while pending:
        value = pending.pop()
        if isinstance(value, IndirectObject):
            key = (value.idnum, value.generation)
            if key in seen:
                continue
            seen.add(key)
            value = value.get_object()
        if isinstance(value, StreamObject):
            streams += 1
            assert b'SECRET' not in value.get_data()
            assert value.get('/Subtype') != '/Image'  # shared affected payload has no surviving use
        if isinstance(value, DictionaryObject):
            assert not any(key in value for key in ('/ActualText', '/Annots', '/Metadata', '/PieceInfo', '/AF', '/StructTreeRoot', '/AcroForm'))
            pending.extend(value.values())
        elif isinstance(value, ArrayObject):
            pending.extend(value)
    return {'pages': 2, 'reachable_streams': streams, 'single_revision': True, 'shared_image_payload_removed': True, 'actualtext_metadata_annotations_removed': True}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--qpdf', required=True)
    parser.add_argument('--pdftoppm', default='pdftoppm')
    parser.add_argument('--work-dir', required=True, type=Path)
    arguments = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    work = arguments.work_dir.resolve()
    work.mkdir(parents=True, exist_ok=True)
    result, original = work / 'redacted.pdf', work / 'original.pdf'
    run(arguments.php, str(root / 'examples/pdf17-secure-redaction.php'), str(result), '--source=' + str(original))
    run(arguments.qpdf, '--check', str(original))
    run(arguments.qpdf, '--check', str(result))
    assert b'SECRETIMG' in original.read_bytes() and b'SECRET-TEXT' in original.read_bytes() and b'SECRET-ACTUALTEXT' in original.read_bytes()
    report = inspect(result)
    for source, name in [(original, 'original'), (result, 'redacted')]:
        run(arguments.pdftoppm, '-r', '72', str(source), str(work / name))
    for number in (1, 2):
        width, height, pixels = ppm(work / f'redacted-{number}.ppm')
        assert (width, height) == (200, 120)
        def pixel(x, y):
            offset = ((height - 1 - y) * width + x) * 3
            return pixels[offset:offset + 3]
        assert pixel(110, 10) == b'\0\xff\0'  # outside vector survives
        if number == 1:
            assert pixel(25, 25) == b'\xff\xff\xff'  # earlier shared image call is removed
        else:
            assert pixel(20, 69) == b'\0\0\0'  # mark in default page coordinates, not CTM translated by 5000
    report['qpdf'] = True
    report['poppler_pages'] = 4
    report['outside_vectors_preserved'] = True
    report['replacement_ctm_isolated'] = True
    run(arguments.php, str(root / 'tools/build_redaction_transform_fixtures.php'), str(work))
    probes = [(60, 40), (90, 34), (50, 30), (109, 30)]
    for name in ('original', 'redacted'):
        path = work / f'transforms-{name}.pdf'
        run(arguments.qpdf, '--check', str(path))
        assert len(PdfReader(path, strict=True).pages) == 4
        run(arguments.pdftoppm, '-r', '72', str(path), str(work / f'transforms-{name}'))
    for number, (x, y) in enumerate(probes, 1):
        for name, expected in [('original', b'\0\0\0'), ('redacted', b'\xff\xff\xff')]:
            width, height, pixels = ppm(work / f'transforms-{name}-{number}.ppm')
            assert (width, height) == (200, 120)
            offset = ((height - 1 - y) * width + x) * 3
            assert pixels[offset:offset + 3] == expected, (name, number, 'transformed rectangle')
            public = ((height - 1 - 15) * width + 155) * 3
            assert pixels[public:public + 3] == b'\0\xff\0'
    report['transformed_rectangle_pixel_cases'] = 4
    report['poppler_pages'] += 8
    run(arguments.php, str(root / 'tools/build_redaction_dependency_fixtures.php'), str(work))
    dependency_source = work / 'dependencies-original.pdf'
    dependency_result = work / 'dependencies-redacted.pdf'
    for path in (dependency_source, dependency_result):
        run(arguments.qpdf, '--check', str(path))
        assert len(PdfReader(path, strict=True).pages) == 4
    assert b'SOURCESECRET' in dependency_source.read_bytes()
    assert b'DEPENDENT' in dependency_source.read_bytes()
    assert b'SOURCESECRET' not in dependency_result.read_bytes()
    assert b'DEPENDENT' not in dependency_result.read_bytes()
    dependency_pdf = PdfReader(dependency_result, strict=True)
    for index, page in enumerate(dependency_pdf.pages):
        resources = page['/Resources'].get('/XObject', {})
        if index in (0, 2):
            assert set(resources) == {'/Public'}
            assert resources['/Public'].get_object().get_data() == b'PUBLICIMG'
        else:
            assert not resources
    run(arguments.pdftoppm, '-r', '72', str(dependency_result), str(work / 'dependencies-redacted'))
    report['transitive_mask_resource_cases'] = 2
    report['poppler_pages'] += 4
    (work / 'report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
