"""ISO7.11/12.3.5: independent attachment bytes, metadata, collection graph and revisions."""

import argparse
import base64
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tempfile

from pypdf import PdfReader
from pypdf.generic import IndirectObject, NameObject


def byte_string(value):
    return value.original_bytes if hasattr(value, 'original_bytes') else bytes(value)


def reference(value):
    assert isinstance(value, IndirectObject)
    return value.idnum, value.generation


def names(node, root=True):
    if '/Names' in node:
        values = node['/Names']
        keys = [byte_string(values[i]) for i in range(0, len(values), 2)]
        assert keys == sorted(set(keys))
        pairs = dict(zip(keys, values[1::2]))
    else:
        pairs = {}
        previous = None
        for child in node['/Kids']:
            reference(child)
            subset = names(child.get_object(), root=False)
            assert previous is None or previous < min(subset)
            assert not pairs.keys() & subset.keys()
            pairs.update(subset)
            previous = max(subset)
    if not root:
        assert [byte_string(value) for value in node['/Limits']] == [min(pairs), max(pairs)]
    return pairs


def check_stream(stream, expected):
    assert stream['/Type'] == '/EmbeddedFile'
    data = stream.get_data()
    assert data == expected
    params = stream['/Params']
    assert params['/Size'] == len(expected)
    assert byte_string(params['/CheckSum']) == hashlib.md5(expected).digest()


def validate_fixture(path, password, amount, payloads):
    reader = PdfReader(path, strict=True)
    if reader.is_encrypted:
        assert reader.decrypt(password)
    assert len(reader.pages) == 1
    assert 'Native embedded files' in reader.pages[0].extract_text()
    root = reader.trailer['/Root']
    tree = root['/Names']['/EmbeddedFiles']
    files = names(tree)
    assert list(files) == [b'Main', b'Notes']
    main_ref = files[b'Main']
    reference(main_ref)
    main = main_ref.get_object()
    assert main['/Type'] == '/Filespec' and main['/F'] == 'data.bin'
    assert main['/UF'] == 'données.bin'
    assert [byte_string(value) for value in main['/ID']] == [b'opaque permanent', b'opaque changing']
    assert reference(main['/EF'].raw_get('/F')) == reference(main['/EF'].raw_get('/UF'))
    check_stream(main['/EF']['/F'], payloads['Main'])
    assert main['/EF']['/F']['/Filter'] == '/FlateDecode'
    params = main['/EF']['/F']['/Params']
    assert params['/CreationDate'] == "D:20261013090000+02'00'"
    assert params['/ModDate'] == params['/CreationDate']
    assert params['/Mac']['/Subtype'] == int.from_bytes(b'CARO', 'big')
    assert params['/Mac']['/Creator'] == int.from_bytes(b'TEXT', 'big')
    assert params['/Mac']['/ResFork'].get_data() == payloads['ResourceFork']
    related = main['/RF']['/F']
    assert len(related) == 2 and related[0] == 'total.json'
    reference(related[1])
    check_stream(related[1].get_object(), payloads['Related'])
    assert related[1].get_object()['/Filter'] == '/LZWDecode'
    assert related[1].get_object()['/DecodeParms']['/EarlyChange'] == 0
    note = files[b'Notes'].get_object()
    check_stream(note['/EF']['/F'], payloads['Notes'])
    reference(main.raw_get('/CI'))
    reference(note.raw_get('/CI'))
    item = main['/CI']
    assert item['/Amount']['/D'] == amount and item['/Amount']['/P'] == 'EUR '
    assert item['/Title'] == 'Main'
    assert item['/Created'] == params['/CreationDate']
    assert '/D' not in note['/CI']['/Amount']
    assert note['/CI']['/Amount']['/P'] == 'No data'
    collection = root['/Collection']
    assert byte_string(collection['/D']) == b'Main'
    assert collection['/View'] == '/D'
    assert collection['/Schema']['/Amount']['/Subtype'] == '/N'
    assert collection['/Schema']['/Created']['/Subtype'] == '/D'
    assert collection['/Schema']['/Title']['/Subtype'] == '/S'
    assert [str(value) for value in collection['/Sort']['/S']] == ['/Amount', '/Title']
    assert len(collection['/Sort']['/A']) == 1 and not collection['/Sort']['/A'][0].value
    # Table158: the missing second direction is true; the display prefix is absent from numeric data.
    assert isinstance(item['/Amount']['/D'], (int, float))
    assert reader.trailer['/Root'].indirect_reference is not None


def validate_example(path):
    reader = PdfReader(path, strict=True)
    assert len(reader.pages) == 1 and 'Fichiers incorpores et collections' in reader.pages[0].extract_text()
    files = names(reader.trailer['/Root']['/Names']['/EmbeddedFiles'])
    assert list(files) == [b'Mesures', b'Notes']
    main = files[b'Mesures'].get_object()
    check_stream(main['/EF']['/F'], b'article,montant\nA,12.50\nB,7.25\n')
    check_stream(main['/RF']['/F'][1].get_object(), b'{"total":19.75}')
    check_stream(files[b'Notes'].get_object()['/EF']['/F'], b'Les montants sont exprimes en euros.\n')
    assert main['/CI']['/Amount']['/D'] == 19.75


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--example', type=Path)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-native-files-') as temporary:
        directory = Path(temporary)
        subprocess.run([args.php, str(root / 'tools/fixtures/native_files.php'), str(directory)], check=True, cwd=root)
        cases = json.loads((directory / 'manifest-native-files.json').read_text(encoding='utf-8'))
        for case in cases:
            payloads = {name: base64.b64decode(value) for name, value in case['payloads'].items()}
            previous = None
            for i, item in enumerate(case['files']):
                path = directory / item['file']
                data = path.read_bytes()
                if previous is not None:
                    assert data.startswith(previous)
                    previous_xref = int(re.findall(rb'startxref\s+(\d+)\s+%%EOF', previous)[-1])
                    assert re.search(rb'/Prev ' + str(previous_xref).encode() + rb'\b', data[len(previous):])
                validate_fixture(path, case['password'], item['amount'], payloads)
                password = ['--password=' + case['password']] if case['password'] else []
                for command in ['--check'] + (['--check-linearization'] if i == 0 and case['linearized'] else []):
                    checked = subprocess.run([args.qpdf, *password, command, str(path)], capture_output=True)
                    assert checked.returncode == 0, checked.stdout.decode(errors='replace') + checked.stderr.decode(errors='replace')
                previous = data
        if args.example:
            validate_example(args.example)
    print(f'Native attachment/collection oracle: {len(cases)} histories / {len(cases) * 2} PDFs passed independent pypdf, hashlib and qpdf checks.')


if __name__ == '__main__':
    main()
