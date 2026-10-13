"""Read generated mechanism examples independently with pypdf, without PHP implementation code."""

import argparse
from io import BytesIO
from pathlib import Path
import re
import struct

from pypdf import PdfReader
from pypdf.generic import IndirectObject


ROOT = Path(__file__).resolve().parents[1]


def require(condition, message):
    if not condition:
        raise ValueError(message)


def sfnt_tables(data):
    require(data[:4] == b'\0\1\0\0', 'Expected a TrueType sfnt program')
    count = struct.unpack_from('>H', data, 4)[0]
    tables = {}
    for index in range(count):
        tag, checksum, offset, length = struct.unpack_from('>4sIII', data, 12 + index * 16)
        require(offset + length <= len(data), 'Font table exceeds its program')
        tables[tag] = data[offset:offset + length]
    return tables


def validate_font(directory):
    reader = PdfReader(directory / 'embedded-font.pdf', strict=True)
    require(len(reader.pages) == 1, 'Embedded font example must have one page')
    text = reader.pages[0].extract_text()
    for expected in ['Une police incorporée, objet par objet.', 'Français : été, cœur, Noël. Ελληνικά : Αθήνα.',
                     'Chaque caractère conserve son identité Unicode.']:
        require(expected in text, 'Unicode extraction failed: ' + expected)
    font = reader.pages[0]['/Resources']['/Font']['/F1']
    require(font['/Subtype'] == '/Type0' and font['/Encoding'] == '/Identity-H', 'Expected Identity-H Type0 font')
    descendant = font['/DescendantFonts'][0].get_object()
    require(descendant['/Subtype'] == '/CIDFontType2', 'Expected a CIDFontType2 descendant')
    require(descendant['/BaseFont'] == font['/BaseFont'], 'Composite font names differ')
    require(font['/ToUnicode'].get_data().startswith(b'/CIDInit'), 'Missing ToUnicode CMap')
    require(len(descendant['/CIDToGIDMap'].get_data()) % 2 == 0, 'Invalid two-byte glyph map')
    font_file = descendant['/FontDescriptor']['/FontFile2']
    data = font_file.get_data()
    require(font_file['/Length1'] == len(data), 'FontFile2 decoded length differs')
    original = sfnt_tables((ROOT / 'vendor/pdftoolkit/pdf-fonts/examples/assets/fonts/NotoSans-Regular.ttf').read_bytes())
    embedded = sfnt_tables(data)
    require(set(embedded) == set(original) - {b'cmap', b'DSIG'}, 'Unexpected CID font table removal')
    for tag in embedded:
        actual, expected = embedded[tag], original[tag]
        if tag == b'head':
            actual = actual[:8] + b'\0' * 4 + actual[12:]
            expected = expected[:8] + b'\0' * 4 + expected[12:]
        require(actual == expected, 'A full font table changed: ' + repr(tag))
    padded = data + b'\0' * ((-len(data)) % 4)
    require(sum(struct.unpack('>' + 'I' * (len(padded) // 4), padded)) & 0xffffffff == 0xb1b0afba,
            'Independent sfnt checksum adjustment check failed')


def validate_images(directory):
    reader = PdfReader(directory / 'image-files.pdf', strict=True)
    require(len(reader.pages) == 1, 'Image file example must have one page')
    images = reader.pages[0]['/Resources']['/XObject']
    for name, fixture, space, transform in [('/JpegRGB', 'rgb.jpg', '/DeviceRGB', 1),
                                             ('/JpegCMYK', 'cmyk.jpg', '/DeviceCMYK', 0)]:
        image = images[name]
        require(image['/Filter'] == '/DCTDecode' and image['/ColorSpace'] == space, 'Incorrect JPEG filter/space')
        require(image['/DecodeParms']['/ColorTransform'] == transform, 'Incorrect JPEG colour transform')
        require(image.get_data() == (ROOT / 'vendor/pdftoolkit/pdf-images/examples/assets/images' / fixture).read_bytes(), 'JPEG bytes changed')
    require(list(images['/JpegCMYK']['/Decode']) == [1, 0] * 4, 'Adobe CMYK decode inversion missing')
    for name in ['/PngRGBA', '/PngPalette']:
        image = images[name]
        require(image['/ColorSpace'] == '/DeviceRGB' and image['/BitsPerComponent'] == 8, 'Incorrect PNG layout')
        pixels = image['/Width'] * image['/Height']
        require(len(image.get_data()) == pixels * 3, 'Incorrect RGB sample length')
        mask = image['/SMask']
        require(mask['/ColorSpace'] == '/DeviceGray' and mask['/BitsPerComponent'] == 8, 'Incorrect alpha layout')
        require(mask['/Width'] == image['/Width'] and mask['/Height'] == image['/Height'], 'Alpha dimensions differ')
        alpha = mask.get_data()
        require(len(alpha) == pixels and min(alpha) == 0 and max(alpha) == 255, 'Invalid alpha samples')
    require(set(images['/PngPalette']['/SMask'].get_data()) == {0, 85, 170, 255}, 'Palette tRNS values differ')


def validate_revisions(directory):
    data = (directory / 'incremental-update.pdf').read_bytes()
    endings = list(re.finditer(rb'startxref\n([0-9]+)\n%%EOF\n', data))
    require(len(endings) == 3 and data.count(b'%PDF-') == 1, 'Expected three revisions and one header')
    roots, contents, identifiers = [], [], []
    for index, ending in enumerate(endings):
        reader = PdfReader(BytesIO(data[:ending.end()]), strict=True)
        require(len(reader.pages) == 1, 'Revision page count changed')
        require(f'Revision {index} :' in reader.pages[0].extract_text(), 'Reader did not resolve effective content')
        root = reader.trailer.raw_get('/Root')
        contents_reference = reader.pages[0].raw_get('/Contents')
        roots.append((root.idnum, root.generation))
        contents.append((contents_reference.idnum, contents_reference.generation))
        require(contents_reference.generation == 0, 'Replacement changed the generation')
        file_id = reader.trailer['/ID']
        identifiers.append(tuple(item.original_bytes for item in file_id))
        if index:
            offset = int(ending.group(1))
            xref_header = re.match(rb'(\d+) (\d+) obj\n', data[offset:])
            section = IndirectObject(int(xref_header.group(1)), int(xref_header.group(2)), reader).get_object() if xref_header else reader.trailer
            require(section['/Prev'] == int(endings[index - 1].group(1)), 'Prev does not point at previous xref')
            require(reader.metadata.title == f'Révision incrémentale {index}', 'Latest Info was not resolved')
    require(len(set(roots)) == 1 and len(set(contents)) == 1, 'Historical object identifiers changed')
    require(len({item[0] for item in identifiers}) == 1, 'Permanent file identifier changed')
    require(len({item[1] for item in identifiers}) == 3, 'Changing file identifier was not renewed')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    directory = parser.parse_args().directory
    validate_font(directory)
    validate_images(directory)
    validate_revisions(directory)
    print('Independent checks passed: Unicode/full font program, JPEG/PNG samples and alpha, all three incremental revisions.')
