"""Independent source-based inverse of native LZW and predictor transforms.

Uses retained ISO 7.4.4 + RFC2083 formulas, pypdf raw stream access and qpdf.
Does not import PHP encoder code or reuse its prefix-pair dictionary strategy.
"""

import argparse
import base64
import json
from pathlib import Path
import subprocess
import tempfile
import zlib

from pypdf import PdfReader
from pypdf.generic import IndirectObject


def decode_lzw(data, early):
    position = 0
    width = 9
    table = [bytes([value]) for value in range(256)] + [None, None]
    previous = None
    out = bytearray()
    widths = set()
    clear_count = 0
    eod = False
    while position + width <= len(data) * 8:
        widths.add(width)
        code = 0
        for _ in range(width):
            code = (code << 1) | ((data[position // 8] >> (7 - position % 8)) & 1)
            position += 1
        if code == 256:
            table = [bytes([value]) for value in range(256)] + [None, None]
            previous = None
            width = 9
            clear_count += 1
            continue
        if code == 257:
            eod = True
            break
        if code < len(table):
            current = table[code]
        else:
            assert code == len(table) and previous
            current = previous + previous[:1]
        assert current is not None
        out.extend(current)
        if previous is not None:
            assert len(table) <= 4095
            table.append(previous + current[:1])
            if width < 12 and len(table) + early == 1 << width:
                width += 1
        previous = current
    assert eod, 'LZW EOD code must exist'
    assert clear_count >= 1
    assert 0 <= len(data) * 8 - position <= 7
    assert all((data[bit // 8] >> (7 - bit % 8)) & 1 == 0 for bit in range(position, len(data) * 8))
    return bytes(out), widths, clear_count


def undo_prediction(data, case):
    predictor = case['predictor']
    if predictor == 1:
        return data
    colors = case['colors']
    columns = case['columns']
    bpc = case['bits']
    samples = colors * columns
    rowbytes = (samples * bpc + 7) // 8
    bpp = max(1, (colors * bpc + 7) // 8)
    out = bytearray()
    if predictor == 2:
        assert len(data) % rowbytes == 0
        mask = (1 << bpc) - 1
        shift_total = rowbytes * 8
        padding = shift_total - samples * bpc
        for start in range(0, len(data), rowbytes):
            row = int.from_bytes(data[start:start+rowbytes], 'big')
            decoded = []
            result = row & ((1 << padding) - 1)
            for sample in range(samples):
                delta = (row >> (shift_total - (sample + 1) * bpc)) & mask
                left = decoded[sample-colors] if sample >= colors else 0
                value = (delta + left) & mask
                decoded.append(value)
                result |= value << (shift_total - (sample + 1) * bpc)
            out.extend(result.to_bytes(rowbytes, 'big'))
        return bytes(out)
    assert 10 <= predictor <= 15 and len(data) % (rowbytes + 1) == 0
    previous = bytearray(rowbytes)
    for start in range(0, len(data), rowbytes + 1):
        filter_type = data[start]
        assert 0 <= filter_type <= 4
        row = bytearray(data[start+1:start+1+rowbytes])
        for column in range(rowbytes):
            left = row[column-bpp] if column >= bpp else 0
            above = previous[column]
            upper_left = previous[column-bpp] if column >= bpp else 0
            if filter_type == 0:
                prediction = 0
            elif filter_type == 1:
                prediction = left
            elif filter_type == 2:
                prediction = above
            elif filter_type == 3:
                prediction = (left + above) // 2
            else:
                estimate = left + above - upper_left
                candidates = [left, above, upper_left]
                prediction = min(enumerate(candidates), key=lambda pair: (abs(estimate-pair[1]), pair[0]))[1]
            row[column] = (row[column] + prediction) % 256
        out.extend(row)
        previous = row
    return bytes(out)


def meaningful_samples(data, case):
    """Readers may canonicalize ignored low row-padding bits for TIFF samples."""
    if case['predictor'] != 2:
        return data
    bits = case['colors'] * case['columns'] * case['bits']
    padding = (-bits) % 8
    if not padding:
        return data
    rowbytes = (bits + 7) // 8
    out = bytearray(data)
    for index in range(rowbytes - 1, len(data), rowbytes):
        out[index] &= 255 << padding
    return bytes(out)


def validate(pdf, manifest, qpdf):
    reader = PdfReader(pdf, strict=True)
    cases = json.loads(manifest.read_text(encoding='utf-8'))
    clear_counts = {}
    policies = {}
    for index, case in enumerate(cases):
        expected = base64.b64decode(case['raw'])
        stream = IndirectObject(case['object'], 0, reader).get_object()
        params = stream['/DecodeParms']
        assert params.get('/Predictor', 1) == case['predictor']
        if case['codec'] == 'Flate':
            predicted = zlib.decompress(stream._data)
        else:
            assert params['/EarlyChange'] == case['early']
            predicted, widths, resets = decode_lzw(stream._data, case['early'])
            if case.get('name') == 'full-table':
                assert widths == {9, 10, 11, 12} and resets > 2
                clear_counts[case['early']] = resets
            policies.setdefault(case['early'], set()).update(widths)
        actual = undo_prediction(predicted, case)
        assert actual == expected, (case, 'independent inverse differs')
        if qpdf:
            result = subprocess.run([str(qpdf), '--show-object='+str(case['object']), '--filtered-stream-data', str(pdf)], capture_output=True)
            assert result.returncode == 0, result.stderr.decode(errors='replace')
            assert meaningful_samples(result.stdout, case) == meaningful_samples(expected, case), (case, 'qpdf differs beyond ignored TIFF padding')
        if index and index % 400 == 0:
            print(index, 'streams independently decoded', flush=True)
    assert policies[0] == policies[1] == {9, 10, 11, 12}
    print(len(cases), 'native streams verified; both EarlyChange policies, five sample depths, all PNG filters, TIFF and table resets', clear_counts)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('pdf', nargs='?', type=Path)
    parser.add_argument('cases', nargs='?', type=Path)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    if (args.pdf is None) != (args.cases is None):
        parser.error('Supply both PDF and case manifest, or neither to generate native fixtures.')
    if args.pdf is not None:
        validate(args.pdf, args.cases, args.qpdf)
        return
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-lzw-predictors-') as temporary:
        directory = Path(temporary)
        result = subprocess.run([args.php, str(root / 'tools/fixtures/lzw_predictors.php'), str(directory)], capture_output=True)
        assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
        validate(directory / 'lzw-predictor-cases.pdf', directory / 'lzw-predictor-cases.json', args.qpdf)


if __name__ == '__main__':
    main()
