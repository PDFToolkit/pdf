"""Independently inspect PDF2 GEO measures, owner contexts, point clouds and revisions."""

from __future__ import annotations

import argparse
import io
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

from pypdf import PdfReader


def verify_measure(measure: dict, geographic: bool) -> None:
    assert measure['/Type'] == '/Measure' and measure['/Subtype'] == '/GEO'
    assert measure['/GCS']['/Type'] == ('/GEOGCS' if geographic else '/PROJCS')
    assert measure['/GCS']['/EPSG'] == (4326 if geographic else 3857)
    assert list(measure['/LPTS']) == [0, 0, 1, 0, 1, 1, 0, 1]
    assert list(measure['/Bounds']) == [0, 0, 0, 1, 1, 1, 1, 0]
    assert list(measure['/PDU']) == ['/M', '/SQM', '/DEG']
    expected = [48.85, 2.34, 48.85, 2.36, 48.87, 2.36, 48.87, 2.34] if geographic else [1000, 2000, 2000, 2000, 2000, 3000, 1000, 3000]
    assert list(measure['/GPTS']) == expected
    if geographic:
        assert '/PCSM' not in measure
    else:
        matrix = list(measure['/PCSM'])
        assert matrix == [1000, 0, 0, 0, 1000, 0, 0, 0, 1, 1000, 2000, 0]
        # Apply the row-vector affine mapping independently, not through PHP.
        for offset in range(0, 8, 2):
            x, y = measure['/LPTS'][offset:offset + 2]
            assert x * matrix[0] + y * matrix[3] + matrix[9] == expected[offset]
            assert x * matrix[1] + y * matrix[4] + matrix[10] == expected[offset + 1]


def verify_cloud(cloud: dict) -> None:
    assert cloud['/Type'] == '/PtData' and cloud['/Subtype'] == '/Cloud'
    assert list(cloud['/Names']) == ['/LAT', '/LON', '/ALT']
    assert [list(row) for row in cloud['/XPTS']] == [[48.86, 2.35, 35]]


def verify_pdf(data: bytes) -> None:
    reader = PdfReader(io.BytesIO(data), strict=True)
    assert reader.pdf_header == '%PDF-2.0'
    page, = reader.pages
    geographic, projected = page['/VP']
    assert list(geographic['/BBox']) == [48, 350, 284, 620]
    assert list(projected['/BBox']) == [310, 350, 546, 620]
    verify_measure(geographic['/Measure'], True)
    verify_measure(projected['/Measure'], False)
    cloud, = geographic['/PtData']
    verify_cloud(cloud)
    objects = page['/Resources']['/XObject']
    form = objects['/Grid']
    assert form['/Subtype'] == '/Form' and list(form['/BBox']) == [0, 0, 1, 1]
    verify_measure(form['/Measure'], True)
    verify_cloud(form['/PtData'])
    image = objects['/Raster']
    assert image['/Subtype'] == '/Image' and image.get_data() == b'\xe3\xe9\xd4'
    verify_measure(image['/Measure'], False)
    assert 'PDF 2.0: geospatial objects' in page.extract_text()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default=shutil.which('qpdf'))
    parser.add_argument('--work-dir', type=Path)
    options = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf20-geospatial-') as temporary:
        directory = options.work_dir or Path(temporary)
        directory.mkdir(parents=True, exist_ok=True)
        subprocess.run([options.php, str(root / 'tools/fixtures/pdf20_geospatial.php'), str(directory)], cwd=root, check=True)
        manifest = json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))
        revisions = 0
        for case in manifest:
            path = directory / case['file']
            data = path.read_bytes()
            if case['incremental']:
                assert data.startswith((directory / case['initial']).read_bytes())
            for match in re.finditer(rb'\n%%EOF\n', data):
                if case['linearized'] and match.end() < case['baseLength']:
                    continue
                verify_pdf(data[:match.end()])
                if options.qpdf:
                    prefix = directory / 'historical-prefix.pdf'
                    try:
                        prefix.write_bytes(data[:match.end()])
                        subprocess.run([options.qpdf, '--check', str(prefix)], check=True, capture_output=True)
                    finally:
                        prefix.unlink(missing_ok=True)
                revisions += 1
            if options.qpdf:
                subprocess.run([options.qpdf, '--check', str(path)], check=True, capture_output=True)
        print(f'Verified {len(manifest)} geospatial PDFs and {revisions} historical/fallback revisions: '
              'GCS/GPTS/LPTS, PDU, PCSM, Bounds, viewport/form/image contexts and point clouds match independently.')


if __name__ == '__main__':
    main()
