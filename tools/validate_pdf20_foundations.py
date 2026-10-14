"""Validate PDF2 versions, text bytes, AF and named MCAF with qpdf and pypdf."""
from pathlib import Path
import argparse
import json
import subprocess
import tempfile
from pypdf import PdfReader

ROOT = Path(__file__).resolve().parents[1]


def run(command):
    result = subprocess.run(list(map(str, command)), cwd=ROOT, capture_output=True, text=True, encoding='utf-8', errors='replace')
    if result.returncode != 0:
        raise AssertionError(f"Command failed: {command}\n{result.stdout}\n{result.stderr}")
    return result.stdout


def raw(value):
    return value.original_bytes


def check_association(root, page):
    document_file = root['/AF'][0]
    page_file = page['/AF'][0]
    property_file = page['/Resources']['/Properties']['/AFData']['/MCAF'][0]
    assert document_file.idnum == page_file.idnum == property_file.idnum
    assert document_file.generation == page_file.generation == property_file.generation == 0
    file = document_file.get_object()
    assert file['/Type'] == '/Filespec' and file['/AFRelationship'] == '/Data'
    embedded = file['/EF']['/F']
    assert embedded['/Type'] == '/EmbeddedFile' and embedded['/Subtype'] == '/text/csv'
    assert embedded['/Params']['/ModDate'] == 'D:20261014000000Z'
    content = page['/Contents'].get_data()
    assert b'/AF /AFData BDC' in content and b'EMC' in content
    return embedded.get_data()


def check_example(path, qpdf):
    run([qpdf, '--check', path])
    reader = PdfReader(path)
    assert reader.pdf_header == '%PDF-2.0' and len(reader.pages) == 1
    assert raw(reader.trailer['/Info']['/Title']) == b'\xef\xbb\xbf' + 'PDF 2.0 : fichiers associés'.encode('utf-8')
    payload = check_association(reader.trailer['/Root'], reader.pages[0])
    assert payload == b'produit,quantite,prix_unitaire\nPapier,2,3.50\nEnveloppes,4,1.20\n'
    file = reader.trailer['/Root']['/AF'][0].get_object()
    assert raw(file['/UF']) == b'\xef\xbb\xbf' + 'données.csv'.encode('utf-8')
    font = reader.pages[0]['/Resources']['/Font']['/F1']
    assert font['/Subtype'] == '/Type0' and font['/Encoding'] == '/Identity-H'
    assert font['/DescendantFonts'][0]['/FontDescriptor']['/FontFile2'].get_data()
    assert '/ToUnicode' in font


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--example', type=Path)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='pdf20-foundations-') as temporary:
        directory = Path(temporary)
        run([args.php, ROOT / 'tools/fixtures/pdf20_foundations.php', directory])
        cases = json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))
        assert len(cases) == 9
        for case in cases:
            path = directory / case['file']
            run([args.qpdf, '--check', path])
            reader = PdfReader(path)
            assert reader.pdf_header == case['header'] and len(reader.pages) == 1
            root = reader.trailer['/Root']
            if case['header'] == '%PDF-1.7':
                assert root['/Version'] == '/2.0'
            info = reader.trailer['/Info']
            # Inspect stored bytes independently. Some reader APIs preserve the UTF8
            # marker as U+FEFF; this comparison does not mistake that API for the data.
            assert raw(info['/Title']) == b'\xef\xbb\xbf' + case['title'].encode('utf-8')
            assert raw(info['/Subject']) == b'\xfe\xff' + case['subject'].encode('utf-16-be')
            assert raw(info['/Default']) == b'Default PDFDoc text'
            assert raw(info['/Opaque']) == b'\xef\xbb\xbf\0\xff'
            assert check_association(root, reader.pages[0]) == case['csv'].encode('utf-8')
            if case['baseFile']:
                assert path.read_bytes().startswith((directory / case['baseFile']).read_bytes())
        if args.example:
            check_example(args.example.resolve(), args.qpdf)
    print('Verified 9 PDF2 files across all xref formats/header/catalog/incremental declarations, Unicode encodings, opaque bytes and AF/MCAF identities' + (' and the embedded-font example.' if args.example else '.'))


if __name__ == '__main__':
    main()
