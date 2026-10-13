"""Independent F.3.10/F.4.3/F.4.6 oracle for normalized thumbnail and shared EF graphs.

qpdf 12.4.2 compares its own page-only classification against shared hint counts
and does not read Table F.11/F.12. For shared-EF fixtures the source-based binary
oracle verifies those hints; qpdf validates a strictly read, rewritten PDF instead.
No qpdf warnings are suppressed or treated as success.
"""

from pathlib import Path
import argparse
import json
import subprocess
import tempfile

from validate_linearization import validate


def check_graph(path, case):
    reader, data, hint, shared_numbers, embedded_rows = validate(path, case['password'], report=False)
    root = reader.trailer['/Root']
    colour = reader.pages[0]['/Resources']['/ColorSpace'].raw_get('/CS')
    metadata = root.raw_get('/Metadata')
    thumbs = [reader.pages[index].raw_get('/Thumb') for index in [0, 2]]
    assert thumbs[0].idnum != thumbs[1].idnum, 'Each thumbnail group needs a distinct image root'
    copied_colour = []
    copied_metadata = []
    for reference in thumbs:
        thumbnail = reference.get_object()
        assert thumbnail.get_data() == b'\xff\0\0'
        assert thumbnail['/PrivateLabel'] == 'Graph thumbnail label'
        copied_colour.append(thumbnail.raw_get('/ColorSpace').idnum)
        copied_metadata.append(thumbnail.raw_get('/Metadata').idnum)
        assert thumbnail['/Metadata'].get_data() == b'<graph-metadata>private thumbnail metadata</graph-metadata>'
        assert thumbnail['/Metadata']['/PrivateLabel'] == 'Graph metadata label'
    assert len(set(copied_colour)) == 1 and copied_colour[0] != colour.idnum
    assert len(set(copied_metadata)) == 1 and copied_metadata[0] != metadata.idnum
    assert '/T' in hint and '/B' in hint
    if case['mixedRoot']:
        image = reader.pages[0]['/Resources']['/XObject'].raw_get('/Im')
        assert image.idnum not in [value.idnum for value in thumbs]
        assert image.get_object().raw_get('/ColorSpace').idnum == colour.idnum
        assert image.get_object().raw_get('/Metadata').idnum == metadata.idnum
    if case['password']:
        offset = reader.xref[0][metadata.idnum]
        start = data.index(b'\nstream\n', offset) + 8
        assert b'/Name /Identity' in data[offset:start]
        assert data[start:].startswith(b'<graph-metadata>private thumbnail metadata</graph-metadata>')
        assert b'Graph metadata label' not in data and b'Graph thumbnail label' not in data
        for number in set(copied_metadata):
            copied_offset = reader.xref[0][number]
            copied_end = data.index(b'\nendobj\n', copied_offset)
            assert b'private thumbnail metadata' not in data[copied_offset:copied_end]
    else:
        assert metadata.get_object().get_data() == b'<graph-metadata>private thumbnail metadata</graph-metadata>'
    payloads = []
    for filespec in root['/PrivateFiles']:
        payloads.append(filespec['/EF'].raw_get('/F'))
    expected = [b'Private payload']
    if case['sharedEmbedded']:
        expected = [b'Private payload', b'First payload', b'Second payload'] if case['privateFirst'] else [b'First payload', b'Second payload', b'Private payload']
        if not case['includePrivate']:
            expected = [b'First payload', b'Second payload']
    assert [value.get_object().get_data() for value in payloads] == expected
    assert len(embedded_rows) == len(expected)
    rows = {number: (count, length, refs) for number, count, length, refs in embedded_rows}
    for payload in payloads:
        count, length, refs = rows[payload.idnum]
        if payload.get_object().get_data() != b'Private payload':
            assert count == length == 0 and refs
            numbers = set().union(*[shared_numbers[index] for index in refs])
            params = payload.get_object().raw_get('/Params')
            assert {payload.idnum, params.idnum} <= numbers
            assert params.idnum == reader.pages[1].raw_get('/PrivateParams').idnum
        else:
            assert count == 1 and length > 0 and not refs


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-linearization-graphs-') as temporary:
        directory = Path(temporary)
        result = subprocess.run([args.php, str(root / 'tools/fixtures/linearization_graphs.php'), str(directory)], capture_output=True)
        assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
        cases = json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))
        for case in cases:
            path = directory / case['file']
            check_graph(path, case)
            if case['sharedEmbedded']:
                # --suppress-recovery is an explicit strict-read contract, not
                # suppression of diagnostics. Then check the rebuilt file.
                rebuilt = directory / 'strict-rebuilt.pdf'
                result = subprocess.run([args.qpdf, '--password=' + case['password'], '--suppress-recovery', str(path), str(rebuilt)], capture_output=True)
                assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
                result = subprocess.run([args.qpdf, '--password=' + case['password'], '--check', str(rebuilt)], capture_output=True)
            else:
                result = subprocess.run([args.qpdf, '--password=' + case['password'], '--check-linearization', str(path)], capture_output=True)
            assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
        print(len(cases), 'normalized thumbnail/shared-EF graphs independently verified; source graphs unchanged')


if __name__ == '__main__':
    main()
