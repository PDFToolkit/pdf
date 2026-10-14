#!/usr/bin/env python3
"""Check WTPDF source identity, normative leaf-clause coverage and evidence paths.

The inventory separates independently assessable aspects. This checker proves
traceability, not that a mechanical check certifies semantic conformance.
Requires the repository's pinned development pypdf dependency, never PHP runtime.
"""

from pathlib import Path
import hashlib
import json
import re

from pypdf import PdfReader


ROOT = Path(__file__).resolve().parents[1]


def check(root: Path = ROOT) -> dict:
    inventory = json.loads((root / 'resources/wtpdf-1.0/requirements.json').read_text(encoding='utf-8'))
    coverage = json.loads((root / 'resources/wtpdf-1.0/coverage.json').read_text(encoding='utf-8'))
    source = root / inventory['source']
    if hashlib.sha256(source.read_bytes()).hexdigest() != inventory['sha256']:
        raise ValueError('The WTPDF source differs from the retained original.')
    pdf = PdfReader(source)
    if len(pdf.pages) != inventory['physical_pdf_pages'] or len(pdf.pages) != 57:
        raise ValueError('WTPDF physical page count changed.')
    requirements = inventory['requirements']
    identifiers = [row['id'] for row in requirements]
    if len(set(identifiers)) != len(identifiers) or inventory.get('no_certification') is not True:
        raise ValueError('Require unique aspect identifiers and an explicit no-certification boundary.')
    source_clauses = {}
    clause = None
    annotation_count = 0
    for page, item in enumerate(pdf.pages, 1):
        for reference in item.get('/Annots', []):
            annotation_count += 1
            if reference.get_object().get('/Subtype') != '/Link':
                raise ValueError('A new source annotation requires explicit normative review.')
        if page < 9 or page > 49:
            continue
        for line in (item.extract_text() or '').splitlines():
            match = re.match(r'^((?:6|7|8)(?:\.\d+){0,5})\s+([A-Z0-9].*)$', line.strip())
            if match:
                clause = match[1]
            if clause is not None:
                source_clauses.setdefault(clause, []).append((page, line))
    if annotation_count != 173:
        raise ValueError('The annotation inventory requires renewed review.')
    covered = {row['clause'] for row in requirements}
    normative_leaves = set()
    for clause, lines in source_clauses.items():
        if any(other.startswith(clause + '.') for other in source_clauses):
            continue
        text = ' '.join(line for _, line in lines)
        if re.search(r'\b(?:shall|should|may)\b', text):
            normative_leaves.add(clause)
    missing = normative_leaves - covered
    if missing:
        raise ValueError('Unclassified normative leaf clauses: ' + ', '.join(sorted(missing)))
    statuses = set(inventory['statuses'])
    path_count = 0
    for row in requirements:
        if row['status'] not in statuses or not row['justification'] or not row['aspect']:
            raise ValueError('Invalid classification: ' + row['id'])
        if not row['physical_pdf_pages'] or any(not isinstance(page, int) or not 1 <= page <= 57 for page in row['physical_pdf_pages']):
            raise ValueError('Invalid source pages: ' + row['id'])
        if row['clause'] not in source_clauses and row['clause'] not in {'A', 'B.1', 'B.2', 'B.3', 'B.4', 'B.5', 'C'}:
            raise ValueError('Unknown source clause: ' + row['id'])
        for name in row['implementation_or_boundary'] + row['evidence']:
            path = (root / name).resolve()
            if not path.is_relative_to(root.resolve()) or not path.is_file():
                raise ValueError('Missing/unsafe evidence path: ' + row['id'] + ': ' + name)
            path_count += 1
    if coverage.get('requirement_inventory') != 'resources/wtpdf-1.0/requirements.json':
        raise ValueError('Mechanism summary must identify the requirement inventory.')
    return {'physical_pages': len(pdf.pages), 'link_annotations': annotation_count, 'classified_aspects': len(requirements), 'normative_leaf_clauses': len(normative_leaves), 'checked_evidence_paths': path_count, 'certification': False}


if __name__ == '__main__':
    print(json.dumps(check(), indent=2))
