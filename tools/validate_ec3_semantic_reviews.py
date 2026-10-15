#!/usr/bin/env python3
"""Verify EC3 source anchors, approval chains and the exhaustive annotation inventory."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re

from pypdf import PdfReader


def validate(root: Path, write_inventory: bool = False) -> None:
    directory = root / 'resources/iso32000-2-2020'
    resource = json.loads((directory / 'ec3-semantic-reviews.json').read_text(encoding='utf-8'))
    source = root / resource['source_path']
    assert hashlib.sha256(source.read_bytes()).hexdigest() == resource['source_sha256']
    reader = PdfReader(source, strict=True)
    annotations = {}
    objects = {}
    for index, page in enumerate(reader.pages, start=1):
        for reference in page.get('/Annots', []):
            annotation = reference.get_object()
            objects[(reference.idnum, reference.generation)] = (index, reference, annotation)
            name = annotation.get('/NM')
            if name:
                annotations.setdefault(str(name), []).append((index, reference, annotation))
    reviewed = {}
    for review in resource['reviews']:
        name = review['annotation_name']
        assert name not in reviewed, name
        reviewed[name] = review
        anchor = annotations[name]
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

    # A review state is approval of the correction, not evidence that this library implements it.
    # Keep every named issue anchor, including editorial changes and additional anchors of reviewed issues.
    records = []
    for page, reference, annotation in objects.values():
        issue = re.search(r'Issue\s*#\s*(\d+)', str(annotation.get('/T', '')) + ' ' + str(annotation.get('/Subj', '')))
        if issue is None or '/IRT' in annotation:
            continue
        name = str(annotation.get('/NM', ''))
        assert name
        records.append({
            'issue': int(issue.group(1)),
            'physical_pdf_page': page,
            'annotation_name': name,
            'object_number': reference.idnum,
            'generation': reference.generation,
            'annotation_subtype': str(annotation['/Subtype']),
            'contents_sha256': hashlib.sha256(str(annotation.get('/Contents', '')).encode('utf-8')).hexdigest(),
            'semantic_review': 'ec3-semantic-reviews.json#' + name if name in reviewed else None,
        })
    records.sort(key=lambda item: (item['issue'], item['physical_pdf_page'], item['annotation_name']))
    inventory = {
        'schema_version': 1,
        'source_document': resource['source_document'],
        'source_path': resource['source_path'],
        'source_sha256': resource['source_sha256'],
        'physical_pdf_pages': len(reader.pages),
        'source_annotation_count': len(objects),
        'named_issue_anchor_count': len(records),
        'unique_issue_count': len({record['issue'] for record in records}),
        'reviewed_anchor_count': len(reviewed),
        'scope': 'Exhaustive inventory of top-level annotations named Issue #N in this exact retained PDF. A null semantic_review means no evidence in the native semantic review register; it does not mean no source overlay or existing implementation. This machine inventory is not a semantic classification or a conformance claim.',
        'annotations': records,
    }
    path = directory / 'ec3-annotation-inventory.json'
    if write_inventory:
        path.write_text(json.dumps(inventory, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    else:
        assert json.loads(path.read_text(encoding='utf-8')) == inventory, 'The EC3 annotation inventory differs from the retained original or review register.'
    print(f"EC3: {len(reviewed)} exact semantic anchors across {len({review['issue'] for review in resource['reviews']})} issues; {len(records)} issue anchors inventoried, with unlisted implementation evidence left unresolved.")


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--write-inventory', action='store_true', help='Regenerate source identity inventory; never creates semantic review evidence.')
    args = parser.parse_args()
    validate(args.root, args.write_inventory)
