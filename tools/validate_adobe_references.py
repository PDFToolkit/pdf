"""Verify unchanged archival Adobe sources and portfolio attachments with pypdf."""

from __future__ import annotations

import argparse
import hashlib
from io import BytesIO
import json
from pathlib import Path

from pypdf import PdfReader


SOURCE_SHA256 = {
    'pdf_17_errata.pdf': 'a4bc550b9aa2690bedd264cea5149e4f84b541734580206568bf06c9c46717b9',
    'pdf_reference.pdf': 'e7d1fd2df64e906f758bb33a4f80d4430388567d8cd5e4d7c4728aae6ee624a2',
    'pdf_reference1-7_addendum_redaction.pdf': '49ffc2f192b2211f847c6d702077b6f729b594875595bd77f55214029f9d9a58',
    'pdf_reference_1-7.pdf': '3377f6eae9d1bc117c8c47b32ea3177c4a36875d4581345a2ca5705315c60b68',
}
PORTFOLIO_ATTACHMENTS = {
    'PDF17Errata.pdf', 'pdf_implementation.pdf', 'pdf_reference.pdf',
    'pdf_reference_addendum_redaction.pdf',
}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ValueError(message)


def verify_pdf(content: bytes, record: dict, label: str) -> PdfReader:
    require(record['role'] == 'archival-reference', f'Incorrect reference role: {label}')
    require(len(content) == record['bytes'], f'Source size changed: {label}')
    require(hashlib.sha256(content).hexdigest() == record['document_sha256'], f'Source fingerprint changed: {label}')
    reader = PdfReader(BytesIO(content), strict=True)
    require(len(reader.pages) == record['pdf_pages'], f'Physical PDF page count changed: {label}')
    require(reader.metadata is not None and reader.metadata.title == record['title'], f'Source title differs from the catalogue: {label}')
    return reader


def validate(root: Path) -> None:
    catalogue = json.loads((root / 'resources/adobe-references/metadata.json').read_text(encoding='utf-8'))
    require(catalogue['schema_version'] == 1 and catalogue['role'] == 'archival-reference', 'Unknown archival source catalogue')
    records = catalogue['documents']
    documents = {record['document']: record for record in records}
    require(len(documents) == len(records) and set(documents) == set(SOURCE_SHA256), 'The catalogue must retain all four unique supplied Adobe originals')
    sources = {name: (root / record['path']).read_bytes() for name, record in documents.items()}
    attachments_checked = 0
    duplicates_checked = 0
    for name, record in documents.items():
        require(record['document_sha256'] == SOURCE_SHA256[name], f'The catalogue changed an original source fingerprint: {name}')
        reader = verify_pdf(sources[name], record, name)
        require(reader.pdf_header == '%PDF-' + record['container_pdf_version'], f'Container PDF version differs: {name}')
        embedded_records = record['embedded_documents']
        embedded = {item['document']: item for item in embedded_records}
        attachments = list(reader.attachment_list)
        actual_names = [item.name for item in attachments]
        expected_names = PORTFOLIO_ATTACHMENTS if name == 'pdf_reference.pdf' else set()
        require(len(embedded) == len(embedded_records) and set(embedded) == expected_names, f'Incorrect embedded source catalogue: {name}')
        require(len(actual_names) == len(set(actual_names)) and set(actual_names) == expected_names, f'Missing or unexpected portfolio attachments: {name}')
        for attachment in attachments:
            item = embedded[attachment.name]
            label = name + ' -> ' + attachment.name
            content = attachment.content
            require(attachment.alternative_name == attachment.name, f'Embedded filename differs: {label}')
            require(attachment.size == len(content), f'Embedded Size differs from decoded bytes: {label}')
            verify_pdf(content, item, label)
            matches = item['matches_document']
            identical = [source for source, data in sources.items() if data == content]
            require(identical == ([matches] if matches is not None else []), f'Embedded duplicate relation differs: {label}')
            if matches is not None:
                duplicates_checked += 1
            attachments_checked += 1
    require(attachments_checked == 4 and duplicates_checked == 3, 'The portfolio must retain four attachments, including three exact supplied duplicates')
    print(f'Verified {len(documents)} unchanged Adobe originals, {attachments_checked} embedded PDFs and {duplicates_checked} exact duplicates independently.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    validate(parser.parse_args().root.resolve())
