#!/usr/bin/env python3
"""Verify authored EC3 review anchors and exact approval reply chains independently."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path

from pypdf import PdfReader


def validate(root: Path) -> None:
    resource = json.loads((root / 'resources/iso32000-2-2020/ec3-semantic-reviews.json').read_text(encoding='utf-8'))
    source = root / resource['source_path']
    assert hashlib.sha256(source.read_bytes()).hexdigest() == resource['source_sha256']
    reader = PdfReader(source, strict=True)
    annotations = {}
    for index, page in enumerate(reader.pages, start=1):
        for reference in page.get('/Annots', []):
            annotation = reference.get_object()
            name = annotation.get('/NM')
            if name:
                annotations.setdefault(str(name), []).append((index, reference, annotation))
    issues = set()
    for review in resource['reviews']:
        assert review['issue'] not in issues
        issues.add(review['issue'])
        anchor = annotations[review['annotation_name']]
        assert len(anchor) == 1, review['issue']
        page, reference, annotation = anchor[0]
        assert page == review['physical_pdf_page']
        assert f"Issue #{review['issue']}" in [annotation.get('/T'), annotation.get('/Subj')]
        for name, state in zip(review['approval_chain'], review['approval_states'], strict=True):
            candidates = annotations[name]
            assert len(candidates) == 1
            _, approval_reference, approval = candidates[0]
            reply = approval.raw_get('/IRT')
            assert (reply.idnum, reply.generation) == (reference.idnum, reference.generation)
            assert approval['/State'] == state and approval['/StateModel'] == 'Review'
            reference = approval_reference
        assert review['approval_states'][-1] == review['effective_approval']
        for relative in [*review['implementation'], *review['validation']]:
            path = root / relative
            assert path.resolve().is_relative_to(root.resolve()) and path.is_file()
    print(f'EC3 semantic review: {len(issues)} exact source anchors and approval chains verified; unlisted corrections remain unresolved.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    validate(parser.parse_args().root)
