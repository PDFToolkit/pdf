#!/usr/bin/env python3
"""Verify original byte identity and physical page counts for all supplied PDFs."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path

from pypdf import PdfReader


def validate(root: Path) -> None:
    manifest = json.loads((root / 'resources/iso32000-2-bundle/sources.json').read_text(encoding='utf-8'))
    documents = manifest['documents']
    if len(documents) != 11 or len({item['filename'] for item in documents}) != 11:
        raise ValueError('The supplied bundle must retain eleven distinct original filenames.')
    total_pages = 0
    for item in documents:
        path = root / item['path']
        if path.name != item['filename'] or not path.resolve().is_relative_to(root.resolve()):
            raise ValueError(f'Invalid original path: {item["filename"]}')
        data = path.read_bytes()
        if len(data) != item['bytes'] or hashlib.sha256(data).hexdigest() != item['sha256']:
            raise ValueError(f'Original bytes changed: {item["filename"]}')
        reader = PdfReader(path, strict=True)
        if len(reader.pages) != item['physical_pdf_pages']:
            raise ValueError(f'Physical page count changed: {item["filename"]}')
        if item['provider_url'] != 'https://www.pdfa.org/sponsored-standards/':
            raise ValueError(f'Missing source provenance: {item["filename"]}')
        total_pages += len(reader.pages)
    print(f'ISO 32000-2 bundle: {len(documents)} unchanged originals; {total_pages} physical PDF pages verified.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    validate(parser.parse_args().root)
