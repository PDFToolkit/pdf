"""Verify the retained Adobe source and the extension example with an independent reader."""
from pathlib import Path
import argparse
import hashlib
import json

from pypdf import PdfReader
from pypdf.generic import ContentStream


ROOT = Path(__file__).resolve().parents[1]


def require(condition, message):
    if not condition:
        raise ValueError(message)


def validate(directory):
    catalogue = ROOT / 'resources/adobe-supplement-iso32000'
    metadata = json.loads((catalogue / 'metadata.json').read_text(encoding='utf-8'))
    source = ROOT / metadata['path']
    require(hashlib.sha256(source.read_bytes()).hexdigest() == '50d0bbc96028dca1a54cf3c671054536d26e9494dc52877061763b4aed08ac48', 'Adobe source bytes changed')
    require(len(PdfReader(source, strict=True).pages) == 140, 'Adobe source page count changed')
    require(metadata['document_sha256'] == hashlib.sha256(source.read_bytes()).hexdigest(), 'Source catalogue fingerprint differs')
    reader = PdfReader(directory / 'adobe-extensions.pdf', strict=True)
    require(len(reader.pages) == 1, 'Expected one extension example page')
    extensions = reader.root_object['/Extensions']
    require(extensions['/Type'] == '/Extensions', 'Extensions dictionary type differs')
    adobe = extensions['/ADBE']
    require(adobe['/BaseVersion'] == '/1.7' and adobe['/ExtensionLevel'] == 3, 'Adobe extension declaration differs')
    page = reader.pages[0]
    require('PDFToolkit - extensions Adobe' in page.extract_text(), 'Visible example text is missing')
    require('DOC-000001  |  EXH-000019' in page.extract_text(), 'Visible Bates numbering is missing')
    operations = ContentStream(page['/Contents'].get_object(), reader).operations
    artifacts = [arguments for arguments, operator in operations if operator == b'BDC']
    require(len(artifacts) == 1 and artifacts[0][0] == '/Artifact', 'Bates marked content is missing')
    properties = artifacts[0][1]
    require(properties['/Type'] == '/Pagination' and properties['/Subtype'] == '/BatesN', 'Bates artifact classification differs')
    require(list(properties['/Contents']) == ['DOC-000001', 'EXH-000019'], 'Bates content order or values differ')
    require(sum(operator == b'EMC' for _, operator in operations) == 1, 'Bates marked content is not balanced')
    print('Retained Adobe source, extension declaration and visible/structural Bates numbers verified independently.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    validate(parser.parse_args().directory)
