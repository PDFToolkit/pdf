"""Independent Annex F byte/table oracle, including initial page P != 0.

qpdf 12.4.2 intentionally validates against page zero, even when it reads /P.
This oracle uses the retained ISO F.3/F.4 definitions and pypdf's resolved xrefs.
No PHP layout classes or JSON implementation catalogue are imported.
"""

from bisect import bisect_right
from pathlib import Path
import argparse
import json
import re
import struct
import subprocess
import tempfile

from pypdf import PdfReader
from pypdf.generic import IndirectObject


class Bits:
    def __init__(self, data, position=0):
        self.data = data
        self.position = position * 8

    def read(self, width):
        assert 0 <= width <= 32
        result = 0
        for _ in range(width):
            assert self.position < len(self.data) * 8
            result = (result << 1) | ((self.data[self.position // 8] >> (7 - self.position % 8)) & 1)
            self.position += 1
        return result

    def align(self):
        padding = (-self.position) % 8
        assert self.read(padding) == 0, 'Hint padding must be zero'

    def vector(self, count, width):
        values = [self.read(width) for _ in range(count)]
        self.align()
        return values


def validate(path, password='', fixture=False, metadata=True, report=True):
    data = path.read_bytes()
    reader = PdfReader(path, strict=True)
    if reader.is_encrypted:
        assert reader.decrypt(password), 'The explicitly supplied fixture password must authenticate'
    if fixture:
        assert reader.trailer['/Info']['/Title'] == 'Native private title'
        if password and not metadata:
            # pypdf does not implement explicit Crypt Identity cipher selection.
            # Validate that selection against original clear stream bytes rather
            # than its default-cipher-transformed in-memory stream value.
            reference = reader.trailer['/Root'].raw_get('/Metadata')
            offset = reader.xref[0][reference.idnum]
            position = data.index(b'\nstream\n', offset) + 8
            assert b'/Filter /Crypt' in data[offset:position] and b'/Name /Identity' in data[offset:position]
            assert data[position:position + len(b'<document-metadata/>')] == b'<document-metadata/>'
        else:
            assert reader.trailer['/Root']['/Metadata'].get_data() == b'<document-metadata/>'
        for index, page in enumerate(reader.pages):
            assert page['/PrivateData'] == 'Private page ' + str(index)
            assert page['/Contents'].get_data() == f'BT /F1 12 Tf 20 300 Td (Native page {index}) Tj ET'.encode()
        assert reader.pages[1]['/Metadata'].get_data() == b'<page-metadata/>'
        if password:
            assert b'Private page ' not in data and b'Native private title' not in data
            assert b'<page-metadata/>' not in data
            assert (b'<document-metadata/>' not in data) == metadata
    parameters = re.match(rb'%PDF-1\.[1-7]\n%[^\n]+\n(\d+) 0 obj\n(<< /Linearized 1 .*?>>)\nendobj\n', data)
    assert parameters and parameters.end() <= 1024
    dictionary = parameters.group(2)
    values = {name.decode(): int(value) for name, value in re.findall(rb'/([LOENTP]) (\d+)', dictionary)}
    hint_extents = list(map(int, re.search(rb'/H \[([\d ]+)\]', dictionary).group(1).split()))
    assert len(hint_extents) in (2, 4)
    hoff, hlen = hint_extents[:2]
    assert values['L'] == len(data) and values['N'] == len(reader.pages)
    assert 0 <= values['P'] < values['N']
    first_page = reader.pages[values['P']]
    assert first_page.indirect_reference.idnum == values['O']
    hint_number = int(re.match(rb'(\d+) 0 obj\n', data[hoff:]).group(1))
    hint = IndirectObject(hint_number, 0, reader).get_object()
    hint_data = hint.get_data()
    assert re.search(rb'endobj\n$', data[hoff:hoff+hlen])
    if len(hint_extents) == 4:
        overflow_offset, overflow_length = hint_extents[2:]
        overflow_number = int(re.match(rb'(\d+) 0 obj\n', data[overflow_offset:]).group(1))
        assert overflow_number == hint_number + 1
        overflow = IndirectObject(overflow_number, 0, reader).get_object()
        assert all(name not in overflow for name in ['/S', '/T', '/O', '/A', '/E', '/V', '/I', '/C', '/L', '/R', '/B'])
        assert re.search(rb'endobj\n$', data[overflow_offset:overflow_offset+overflow_length])
        assert values['E'] <= overflow_offset
        hint_data += overflow.get_data()
    for page in reader.pages:
        assert all(name in page for name in ['/MediaBox', '/Resources', '/CropBox', '/Rotate'])
        ancestor = page['/Parent']
        while ancestor:
            assert all(name not in ancestor for name in ['/MediaBox', '/Resources', '/CropBox', '/Rotate'])
            ancestor = ancestor.get('/Parent')
    xrefs = {number: offset for number, offset in reader.xref[0].items() if number != 0}
    offsets = sorted(xrefs.values())
    main_offset = int(re.search(rb'/Prev (\d+)', data[parameters.end():hoff]).group(1))
    boundaries = sorted(set([*offsets, main_offset]))

    def adjusted(offset):
        return offset + (hlen if offset >= hoff else 0)

    def span(first, count, length, offset):
        assert count > 0
        start = xrefs[first]
        assert start == adjusted(offset), (first, 'bad offset', start, adjusted(offset))
        for number in range(first, first + count):
            assert data[xrefs[number]:].startswith(f'{number} 0 obj\n'.encode())
        last = xrefs[first + count - 1]
        end = boundaries[bisect_right(boundaries, last)]
        assert end - start == length, (first, 'bad range', length, end-start)
        return start, end

    h = struct.unpack('>IIHIHIHIHHHHH', hint_data[:36])
    min_objects, first_offset, object_bits, min_length, length_bits, min_content_offset, content_offset_bits, min_content_length, content_length_bits, shared_count_bits, shared_id_bits, numerator_bits, denominator = h
    assert denominator > 0
    bits = Bits(hint_data, 36)
    counts = [min_objects + delta for delta in bits.vector(values['N'], object_bits)]
    lengths = [min_length + delta for delta in bits.vector(values['N'], length_bits)]
    nshared = bits.vector(values['N'], shared_count_bits)
    references = bits.vector(sum(nshared), shared_id_bits)
    numerators = bits.vector(sum(nshared), numerator_bits)
    content_offsets = [min_content_offset + delta for delta in bits.vector(values['N'], content_offset_bits)]
    content_lengths = [min_content_length + delta for delta in bits.vector(values['N'], content_length_bits)]
    assert bits.position // 8 == hint['/S']
    assert nshared[0] == 0
    assert adjusted(first_offset) == xrefs[values['O']]
    page_order = [values['P'], *[index for index in range(values['N']) if index != values['P']]]
    number = 1
    offset = first_offset
    for entry, page_index in enumerate(page_order):
        first = values['O'] if entry == 0 else number
        if entry == 1:
            offset = values['E'] - hlen
        start, end = span(first, counts[entry], lengths[entry], offset)
        assert reader.pages[page_index].indirect_reference.idnum == first
        assert 0 <= content_offsets[entry] <= lengths[entry]
        assert 0 <= content_lengths[entry] <= lengths[entry] - content_offsets[entry]
        if entry == 0:
            assert end == values['E']
        else:
            offset += lengths[entry]
            number += counts[entry]

    shared_offset = hint['/S']
    first_shared, shared_start, nfirst, ntotal, group_count_bits, min_group_length, group_length_bits = struct.unpack('>IIIIHIH', hint_data[shared_offset:shared_offset+24])
    bits = Bits(hint_data, shared_offset+24)
    group_lengths = [min_group_length + delta for delta in bits.vector(ntotal, group_length_bits)]
    signatures = bits.vector(ntotal, 1)
    for present in signatures:
        if present:
            for _ in range(16):
                bits.read(8)
    group_counts = [delta + 1 for delta in bits.vector(ntotal, group_count_bits)]
    assert nfirst > 0 and nfirst <= ntotal
    first = values['O']
    offset = first_offset
    shared_numbers = []
    for index in range(ntotal):
        if index == nfirst:
            first = first_shared
            offset = shared_start
        span(first, group_counts[index], group_lengths[index], offset)
        shared_numbers.append(set(range(first, first + group_counts[index])))
        first += group_counts[index]
        offset += group_lengths[index]
    assert all(index < ntotal for index in references)
    assert len(numerators) == len(references)
    for key in ['/O', '/A', '/E', '/V', '/I', '/C', '/L', '/R']:
        if key not in hint:
            continue
        start = hint[key]
        first, offset, count, length = struct.unpack('>IIII', hint_data[start:start+16])
        span(first, count, length, offset)
        if key in ['/V', '/C', '/R']:
            nrefs, width = struct.unpack('>IH', hint_data[start+16:start+22])
            refs = Bits(hint_data, start+22).vector(nrefs, width)
            assert all(index < ntotal for index in refs)
    if '/T' in hint:
        start = hint['/T']
        header = struct.unpack('>IIIHIHIHIIII', hint_data[start:start+42])
        first, offset, count, gap_bits, min_length, length_bits, min_count, count_bits, shared_first, shared_offset, shared_count, shared_length = header
        bits = Bits(hint_data, start+42)
        gaps = bits.vector(count, gap_bits)
        object_counts = [min_count + delta for delta in bits.vector(count, count_bits)]
        image_lengths = [min_length + delta for delta in bits.vector(count, length_bits)]
        page_index = -1
        for index in range(count):
            page_index += gaps[index] + 1
            thumbnail = reader.pages[page_index].raw_get('/Thumb')
            assert thumbnail.idnum == first
            span(first, object_counts[index], image_lengths[index], offset)
            first += object_counts[index]
            offset += image_lengths[index]
        assert count == sum('/Thumb' in page for page in reader.pages)
        if shared_count:
            span(shared_first, shared_count, shared_length, shared_offset)
    embedded_rows = []
    if '/B' in hint:
        start = hint['/B']
        first, offset, count, number_bits, count_bits, length_bits, nshared_bits = struct.unpack('>IIIHHHH', hint_data[start:start+20])
        bits = Bits(hint_data, start+20)
        objects = bits.vector(count, number_bits)
        object_counts = bits.vector(count, count_bits)
        group_lengths = bits.vector(count, length_bits)
        nrefs = bits.vector(count, nshared_bits)
        refs = bits.vector(sum(nrefs), shared_id_bits)
        assert all(index < ntotal for index in refs)
        ref_offset = 0
        for index in range(count):
            assert IndirectObject(objects[index], 0, reader).get_object()['/Type'] == '/EmbeddedFile'
            row_refs = refs[ref_offset:ref_offset + nrefs[index]]
            embedded_rows.append((objects[index], object_counts[index], group_lengths[index], row_refs))
            ref_offset += nrefs[index]
            if object_counts[index]:
                assert nrefs[index] == 0, 'F.4.6 forbids mixing private objects and shared references'
                span(first, object_counts[index], group_lengths[index], offset)
                assert first == objects[index]
                assert IndirectObject(objects[index], 0, reader).get_object()['/Type'] == '/EmbeddedFile'
                first += object_counts[index]
                offset += group_lengths[index]
            else:
                assert group_lengths[index] == 0 and nrefs[index] > 0
    if not fixture and report:
        print(path.name, 'independent byte ranges and page/shared/optional hints verified', 'P='+str(values['P']))
    return reader, data, hint, shared_numbers, embedded_rows


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('pdf', nargs='*', type=Path)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--password', default='')
    args = parser.parse_args()
    if args.pdf:
        for path in args.pdf:
            validate(path, args.password)
        return
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-linearization-') as temporary:
        directory = Path(temporary)
        fixture = subprocess.run([args.php, str(root / 'tools/fixtures/linearization.php'), str(directory)], capture_output=True)
        assert fixture.returncode == 0, fixture.stdout.decode(errors='replace') + fixture.stderr.decode(errors='replace')
        cases = json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))
        for case in cases:
            path = directory / case['file']
            validate(path, case['password'], True, case['metadata'])
            if case['firstPage'] == 0:
                result = subprocess.run([args.qpdf, '--password=' + case['password'], '--check-linearization', str(path)], capture_output=True)
                assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
        print(len(cases), 'native linearized files validated; primary/overflow hints, xref formats, object streams and encrypted data')


if __name__ == '__main__':
    main()
