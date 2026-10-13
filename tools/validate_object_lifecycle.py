"""ISO 7.3.9/7.5.4/7.5.6/7.5.7: independent effective xref, free-chain and generation checks."""

from pathlib import Path
import argparse
import json
import re
import subprocess
import tempfile
import zlib

from pypdf import PdfReader
from pypdf.generic import IndirectObject, NullObject


def integer(dictionary, name, default=None):
    match = re.search(rb'/' + name + rb'\s+(\d+)\b', dictionary)
    return int(match.group(1)) if match else default


def xref_section(data, offset):
    """Read physical records directly, without relying on any reader's free-object cache."""
    records = {}
    if data[offset:offset + 5] == b'xref\n':
        cursor = offset + 5
        while not data[cursor:].startswith(b'trailer\n'):
            end = data.index(b'\n', cursor)
            first, count = map(int, data[cursor:end].split())
            cursor = end + 1
            for number in range(first, first + count):
                line = data[cursor:cursor + 20]
                assert re.fullmatch(rb'\d{10} \d{5} [fn][ \r]\n', line), line
                records[number] = (1 if line[17:18] == b'n' else 0, int(line[:10]), int(line[11:16]))
                cursor += 20
        dictionary = data[cursor:data.index(b'\nstartxref\n', cursor)]
    else:
        marker = data.index(b'\nstream\n', offset)
        dictionary = data[offset:marker]
        assert b'/Type /XRef' in dictionary
        length = integer(dictionary, b'Length')
        payload = data[marker + 8:marker + 8 + length]
        if b'/Filter /FlateDecode' in dictionary:
            payload = zlib.decompress(payload)
        widths = list(map(int, re.search(rb'/W \[([^\]]+)\]', dictionary).group(1).split()))
        indices = re.search(rb'/Index \[([^\]]+)\]', dictionary)
        indices = list(map(int, indices.group(1).split())) if indices else [0, integer(dictionary, b'Size')]
        cursor = 0
        for first, count in zip(indices[::2], indices[1::2]):
            for number in range(first, first + count):
                fields = []
                for column, width in enumerate(widths):
                    fields.append(int.from_bytes(payload[cursor:cursor + width], 'big') if width else (1 if column == 0 else 0))
                    cursor += width
                records[number] = tuple(fields)
        assert cursor == len(payload)
    return records, dictionary


def effective_xref(data, offset):
    effective = {}
    seen = set()
    shadows = set()
    while offset is not None:
        assert offset not in seen, 'xref Prev chain is acyclic'
        seen.add(offset)
        records, dictionary = xref_section(data, offset)
        auxiliary = integer(dictionary, b'XRefStm')
        if auxiliary is not None:
            compressed, auxiliary_dictionary = xref_section(data, auxiliary)
            assert integer(auxiliary_dictionary, b'Prev') is None, 'hybrid auxiliary streams do not carry Prev'
            for number, entry in compressed.items():
                if entry[0] == 2 and records.get(number, (None,))[0] == 0:
                    assert records[number] == (0, 0, 65535)
                    shadows.add(number)
            records.update(compressed)  # ISO 7.5.8.4: stream entries override classic compatibility shadows.
        for number, entry in records.items():
            effective.setdefault(number, entry)
        offset = integer(dictionary, b'Prev')
    return effective, shadows


def verify_effective_metadata(data, metadata):
    records, shadows = effective_xref(data, metadata['xref'])
    assert set(records) == set(range(metadata['size'])), metadata['file']
    free = {int(number): tuple(fields) for number, fields in metadata['free'].items()}
    assert {number: fields for number, fields in records.items() if fields[0] == 0} == free, metadata['file']
    assert records[0][0] == 0 and records[0][2] == 65535
    visited = {0}
    number = records[0][1]
    while number:
        assert number in free and number not in visited
        visited.add(number)
        number = free[number][1]
    assert all(number in visited or fields == (0, 0, 65535) for number, fields in free.items())
    for number, generation in metadata['live'].items():
        fields = records[int(number)]
        assert fields[0] in (1, 2)
        assert (fields[2] if fields[0] == 1 else 0) == generation
        if fields[0] == 1:
            assert data[fields[1]:].startswith(f'{number} {generation} obj\n'.encode())
    assert {number: fields for number, fields in records.items() if fields[0] == 2} == {int(number): tuple(fields) for number, fields in metadata['compressed'].items()}
    assert shadows.isdisjoint(free), 'hybrid compatibility shadows are live objects, never reusable free holes'
    return records


def verify_semantics(reader, phase, original):
    catalog = reader.trailer['/Root']
    assert len(reader.pages) == 2
    for index, page in enumerate(reader.pages):
        assert page['/Contents'].get_data() == f'BT /F1 12 Tf 20 300 Td (Lifecycle page {index}) Tj ET'.encode()
    obsolete = []
    if phase == 0:
        assert catalog['/OriginalString'] == 'Original indirect string'
        assert catalog['/OriginalStream'].get_data() == b'Original indirect stream'
    else:
        obsolete.extend([IndirectObject(original[0], 0, reader).get_object(), IndirectObject(original[1], 0, reader).get_object()])
    if phase == 2:
        assert catalog['/CurrentString'].get_data() == b'Reused generation-one stream'
        assert catalog['/CurrentString']['/Label'] == 'Generation-one stream dictionary'
        assert catalog['/CurrentStream']['/Label'] == 'Reused generation-one dictionary'
        assert catalog.raw_get('/CurrentString').generation == 1
        assert catalog.raw_get('/CurrentStream').generation == 1
        if '/CurrentContainer' in catalog:
            assert catalog['/CurrentContainer']['/Label'] == 'Reused former object-stream container'
            assert catalog.raw_get('/CurrentContainer').generation == 1
        if '/UnusedHole' in catalog:
            assert catalog['/UnusedHole'] == 'Unused hole generation zero'
            assert catalog.raw_get('/UnusedHole').generation == 0
    elif phase >= 3:
        obsolete.extend([catalog['/CurrentString'], catalog['/CurrentStream']])
        if '/UnusedHole' in catalog:
            obsolete.append(catalog['/UnusedHole'])
        if '/CurrentContainer' in catalog:
            obsolete.append(catalog['/CurrentContainer'])
    if phase == 4:
        assert catalog['/ThirdGeneration'] == 'Third live generation'
        assert catalog.raw_get('/ThirdGeneration').generation == 2
    return all(isinstance(value, NullObject) for value in obsolete)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-object-lifecycle-') as temporary:
        directory = Path(temporary)
        result = subprocess.run([args.php, str(root / 'tools/fixtures/object_lifecycle.php'), str(directory)], capture_output=True)
        assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
        cases = json.loads((directory / 'manifest-lifecycle.json').read_text(encoding='utf-8'))
        direct_reader_generation_limitations = 0
        for case in cases:
            previous = None
            for phase, metadata in enumerate(case['files']):
                path = directory / metadata['file']
                data = path.read_bytes()
                if previous is not None:
                    assert data.startswith(previous), 'ISO 7.5.6: every historical byte remains unchanged'
                records = verify_effective_metadata(data, metadata)
                for number in case['original']:
                    if phase in (1, 3):
                        assert records[number][0] == 0 and records[number][2] == (1 if phase == 1 else 2)
                    if phase == 2:
                        assert records[number][0] == 1 and records[number][2] == 1
                result = subprocess.run([args.qpdf, '--password=' + case['password'], '--check', str(path)], capture_output=True)
                assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
                if case['format'] == 'Hybrid':
                    result = subprocess.run([args.qpdf, '--password=' + case['password'], '--ignore-xref-streams', '--suppress-recovery', '--check', str(path)], capture_output=True)
                    assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
                direct = PdfReader(path, strict=True)
                if direct.is_encrypted:
                    assert direct.decrypt(case['password'])
                if not verify_semantics(direct, phase, case['original']):
                    # pypdf retains historical xref generations in its cache.
                    # An unmodified qpdf rewrite is an independent effective view;
                    # the physical source records above remain the generation oracle.
                    direct_reader_generation_limitations += 1
                canonical = directory / 'effective.pdf'
                result = subprocess.run([args.qpdf, '--password=' + case['password'], '--decrypt', '--object-streams=disable', '--preserve-unreferenced', str(path), str(canonical)], capture_output=True)
                assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
                view = PdfReader(canonical, strict=True)
                # qpdf rewrites IDs/generations; obsolete references become direct nulls.
                catalog = view.trailer['/Root']
                assert len(view.pages) == 2
                if phase == 0:
                    assert catalog['/OriginalString'] == 'Original indirect string'
                    assert catalog['/OriginalStream'].get_data() == b'Original indirect stream'
                else:
                    assert '/OriginalString' not in catalog or isinstance(catalog['/OriginalString'], NullObject)
                    assert '/OriginalStream' not in catalog or isinstance(catalog['/OriginalStream'], NullObject)
                if phase == 2:
                    assert catalog['/CurrentString'].get_data() == b'Reused generation-one stream'
                    assert catalog['/CurrentString']['/Label'] == 'Generation-one stream dictionary'
                    assert catalog['/CurrentStream']['/Label'] == 'Reused generation-one dictionary'
                    if '/CurrentContainer' in catalog:
                        assert catalog['/CurrentContainer']['/Label'] == 'Reused former object-stream container'
                elif phase >= 3:
                    assert '/CurrentString' not in catalog or isinstance(catalog['/CurrentString'], NullObject)
                    assert '/CurrentStream' not in catalog or isinstance(catalog['/CurrentStream'], NullObject)
                    assert '/CurrentContainer' not in catalog or isinstance(catalog['/CurrentContainer'], NullObject)
                if phase == 4:
                    assert catalog['/ThirdGeneration'] == 'Third live generation'
                previous = data
        print(len(cases), 'histories /', len(cases) * 5, 'PDFs verified: original bytes, effective xrefs, live/free generations, free-chain and encrypted reuse')
        print('Unmodified pypdf historical-generation cache limitations:', direct_reader_generation_limitations, '(physical records and unmodified qpdf effective views verified independently)')


if __name__ == '__main__':
    main()
