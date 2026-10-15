"""Verify Adobe barcode preparation and native AP placement, not symbology encoding."""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
from pathlib import Path
import re
import subprocess
import zlib

from pypdf import PdfReader
from pypdf.generic import ContentStream, IndirectObject


def run(*command):
    subprocess.run(list(map(str, command)), check=True, capture_output=True)


def pixels(path):
    data = path.read_bytes()
    header = re.match(rb'P6\s+(\d+)\s+(\d+)\s+255\s', data)
    assert header
    width, height = int(header[1]), int(header[2])
    data = data[header.end():]
    assert len(data) == width * height * 3

    def pixel(x, y):
        offset = ((height - 1 - int(y)) * width + int(x)) * 3
        return tuple(data[offset:offset + 3])

    return width, height, pixel


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--qpdf', required=True)
    parser.add_argument('--pdftoppm', default='pdftoppm')
    parser.add_argument('--work-dir', type=Path, required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    coverage = json.loads((root / 'resources/barcodes/coverage.json').read_text(encoding='utf-8'))
    source = coverage['source']
    assert source['document'] == 'adobe_supplement_iso32000.pdf'
    assert source['physicalPdfPages'] == [45, 46, 47] and source['implementationNotePages'] == [130, 131]
    assert hashlib.sha256((root / source['path']).read_bytes()).hexdigest() == source['sha256']
    for evidence in [*coverage['evidence']['unitTests'], coverage['evidence']['fixture'], coverage['evidence']['oracle']]:
        assert (root / evidence).is_file()
    work = args.work_dir.resolve()
    work.mkdir(parents=True, exist_ok=True)
    run(args.php, root / 'tools/fixtures/barcode-appearances.php', work)
    preparation = json.loads((work / 'preparation.json').read_text(encoding='utf-8'))
    assert preparation['symbologyProof'] is False
    inputs = {
        'multi-direct': (b'abcdefgh', 0, 3, False),
        'multi-flate': (b'\xfe\xff' + 'été\0documents'.encode('utf-16-be'), 1, 3, False),
        'single-separate': (b'one', 0, 1, False),
        'single-merged': (b'one', 0, 1, True),
        'empty-value': (b'', 0, 2, False),
    }
    assert {case['name'] for case in preparation['cases']} == set(inputs)
    checked = []
    pixel_checks = 0
    for case in preparation['cases']:
        name = case['name']
        value, mode, count, merged = inputs[name]
        path = work / (name + '.pdf')
        run(args.qpdf, '--check', path)
        pdf = PdfReader(path, strict=True)
        assert len(pdf.pages) == 1
        catalog = pdf.trailer['/Root']
        assert catalog['/Extensions']['/ADBE']['/ExtensionLevel'] >= 3
        form = catalog['/AcroForm']
        assert form['/NeedAppearances'].value is False
        assert len(form['/Fields']) == 1
        field_reference = form['/Fields'][0]
        field = field_reference.get_object()
        assert field['/FT'] == '/Tx' and field['/T'] == 'barcode'
        assert field['/V'].original_bytes == value and int(field['/DataPrep']) == mode
        assert len(case['calls']) == count
        chunks = [base64.b64decode(call['bytes'], validate=True) for call in case['calls']]
        data = b''.join(chunks)
        assert (zlib.decompress(data) if mode else data) == value
        if mode:
            decoder = zlib.decompressobj()
            assert decoder.decompress(data) == value and decoder.eof
            assert not decoder.unused_data and not decoder.unconsumed_tail
        sizes = [len(chunk) for chunk in chunks]
        assert max(sizes) - min(sizes) <= 1 and sizes == sorted(sizes, reverse=True)
        total = 0
        for index, call in enumerate(case['calls']):
            assert (call['index'], call['total'], call['offset']) == (index, count, total)
            total += sizes[index]
        widgets = [field_reference] if merged else field['/Kids']
        assert len(widgets) == count
        assert ('/Kids' not in field) if merged else ('/AP' not in field)
        annotations = pdf.pages[0]['/Annots']
        assert len(annotations) == count
        run(args.pdftoppm, '-r', '72', '-singlefile', path, work / name)
        width, height, pixel = pixels(work / (name + '.ppm'))
        assert (width, height) == (600, 160)
        assert all(abs(actual - expected) <= 1 for actual, expected in zip(pixel(5, 5), (64, 128, 191)))
        pixel_checks += 1
        for index, reference in enumerate(widgets):
            assert isinstance(reference, IndirectObject) and reference == annotations[index]
            widget = reference.get_object()
            assert widget['/Subtype'] == '/Widget'
            if not merged:
                assert widget['/Parent'] == field
            assert '/A' not in widget and '/AA' not in widget
            metadata = widget['/PMD']
            expected_symbology = '/DataMatrix' if index == 1 else '/QRCode'
            assert metadata['/Type'] == '/PaperMetaData'
            assert metadata['/Symbology'] == expected_symbology
            assert case['calls'][index]['symbology'] == expected_symbology[1:]
            assert metadata['/Caption'] == 'TEST PATTERN - NO SYMBOLOGY ENCODER'
            rectangle = list(map(float, widget['/Rect']))
            left, right = sorted([rectangle[0], rectangle[2]])
            bottom, top = sorted([rectangle[1], rectangle[3]])
            assert index != 1 or rectangle[0] > rectangle[2]
            normal_reference = widget['/AP'].raw_get('/N')
            assert isinstance(normal_reference, IndirectObject)
            normal = normal_reference.get_object()
            assert normal['/Subtype'] == '/Form'
            assert list(map(float, normal['/BBox'])) == [0, 0, right - left, top - bottom]
            content = ContentStream(normal, pdf)
            assert [operator for _, operator in content.operations] == [b'q', b'cm', b'Do', b'Q']
            assert list(map(float, content.operations[1][0])) == [right - left, 0, 0, top - bottom, 0, 0]
            image = normal['/Resources']['/XObject']['/BarcodeImage']
            expected_image = b'\0\xff\xff\0' if index % 2 == 0 else b'\xff\0\0\xff'
            assert image['/Subtype'] == '/Image' and image['/ColorSpace'] == '/DeviceGray'
            assert (image['/Width'], image['/Height'], image['/BitsPerComponent']) == (2, 2, 8)
            assert image.get_data() == expected_image
            for x_fraction, y_fraction, position in [(0.25, 0.75, 0), (0.75, 0.75, 1), (0.25, 0.25, 2), (0.75, 0.25, 3)]:
                sample = expected_image[position]
                assert pixel(left + (right - left) * x_fraction, bottom + (top - bottom) * y_fraction) == (sample, sample, sample), (name, index, position)
                pixel_checks += 1
        checked.append({'name': name, 'widgets': count, 'merged': merged, 'preparation': mode})
    report = {'status': 'pass', 'pdfs': len(checked), 'pages': len(checked), 'widgets': sum(item['widgets'] for item in checked), 'pixelChecks': pixel_checks,
              'qpdf': True, 'pypdf': True, 'poppler': True, 'globalCompressionThenPartition': True,
              'symbologyProof': False, 'boundary': 'Test images verify message/PMD association and native PDF appearance geometry, not barcode decoding or quiet zones.', 'cases': checked}
    assert report['pdfs'] == coverage['evidence']['pdfsPerRuntime']
    assert report['widgets'] == coverage['evidence']['widgetsPerRuntime']
    assert report['pixelChecks'] == coverage['evidence']['pixelChecksPerRuntime']
    (work / 'report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
