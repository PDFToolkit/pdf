"""ISO F.1/7.5.6: exact linearized snapshots followed by immutable byte-prefix updates."""

from pathlib import Path
import argparse
import hashlib
import json
import re
import subprocess
import tempfile

from pypdf import PdfReader
from validate_linearization import validate


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-linearized-revisions-') as temporary:
        directory = Path(temporary)
        result = subprocess.run([args.php, str(root / 'tools/fixtures/linearized_revisions.php'), str(directory)], capture_output=True)
        assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
        cases = json.loads((directory / 'manifest-revisions.json').read_text(encoding='utf-8'))
        for case in cases:
            previous = None
            previous_xref = None
            initial_hints = None
            initial_length = None
            original_ids = None
            for index, file in enumerate(case['files']):
                path = directory / file['file']
                data = path.read_bytes()
                assert len(data) == file['length']
                reader = PdfReader(path, strict=True)
                if reader.is_encrypted:
                    assert reader.decrypt(case['password'])
                assert len(reader.pages) == 3
                ids = (reader.trailer['/Root'].indirect_reference.idnum, *[page.indirect_reference.idnum for page in reader.pages])
                if index == 0:
                    validate(path, case['password'], fixture=True, metadata=case['metadata'])
                    original_ids = ids
                    initial_length = int(re.search(rb'/L (\d+)', data).group(1))
                    extents = list(map(int, re.search(rb'/H \[([\d ]+)\]', data).group(1).split()))
                    initial_hints = [(offset, data[offset:offset + length]) for offset, length in zip(extents[::2], extents[1::2])]
                    assert initial_length == len(data)
                    command = '--check-linearization'
                else:
                    assert data.startswith(previous)
                    assert ids == original_ids
                    assert initial_length < len(data), 'F.1: appended revisions cease to be linearized'
                    for offset, hint in initial_hints:
                        assert data[offset:offset + len(hint)] == hint
                    # Hybrid adds a compatibility preamble and final update;
                    # that preamble retains the actual previous startxref.
                    assert re.search(rb'/Prev ' + str(previous_xref).encode() + rb'\b', data[len(previous):])
                    assert reader.trailer['/Info']['/Title'] == 'Updated title'
                    assert reader.pages[1]['/PrivateData'] == 'Updated page 1'
                    assert reader.pages[1]['/Contents'].get_data() == b'BT /F1 12 Tf 20 300 Td (Updated page 1) Tj ET'
                    assert reader.pages[0]['/Contents'].get_data() == b'BT /F1 12 Tf 20 300 Td (Native page 0) Tj ET'
                    assert reader.pages[2]['/Contents'].get_data() == b'BT /F1 12 Tf 20 300 Td (Native page 2) Tj ET'
                    if index == 2:
                        assert reader.trailer['/Root']['/PrivateEpoch'] == 2
                    command = '--check'
                for span in file['spans']:
                    offset, length = span['offset'], span['length']
                    assert data[offset:].startswith(f"{span['number']} {span['generation']} obj\n".encode())
                    assert hashlib.sha256(data[offset:offset + length]).hexdigest() == span['sha256']
                    assert reader.xref[span['generation']][span['number']] == offset
                assert int(re.search(rb'startxref\n(\d+)\n%%EOF\n$', data).group(1)) == file['xref']
                assert reader.trailer['/Size'] == file['size']
                result = subprocess.run([args.qpdf, '--password=' + case['password'], command, str(path)], capture_output=True)
                assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
                previous, previous_xref = data, file['xref']
        print(len(cases), 'linearized histories /', len(cases) * 3, 'files independently verified; initial hints/bytes preserved, effective updates and all xref/security layouts valid')


if __name__ == '__main__':
    main()
