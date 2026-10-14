#!/usr/bin/env python3
"""Extract Adobe's retained PDF 1.7 ExtensionLevel 3 supplement catalogue.

The PDF is reference data. This extractor identifies printed table columns by
their header positions, preserves qualifiers and does not infer conformance
rules from application implementation notes. Requires pypdf and pdfplumber.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path

import pdfplumber
from pypdf import PdfReader

DOCUMENT = 'adobe_supplement_iso32000.pdf'
SHA256 = '50d0bbc96028dca1a54cf3c671054536d26e9494dc52877061763b4aed08ac48'
STANDARD = 'Adobe Supplement to ISO 32000, BaseVersion 1.7, ExtensionLevel 3 (June 2008)'
CAPTION = re.compile(r'^(?i:TABLE)\s*(\d+\.\d+[a-z]?)\s*(.+)$')
TYPES = {'name', 'number', 'real', 'integer', 'boolean', 'array', 'dictionary', 'stream', 'text string', 'byte string', 'string', 'date string', 'ASCII string', 'name tree', 'text tree', '(various)', 'various', 'text string or stream', 'dictionary or array', 'array or dictionary', 'array or name', 'name or array', 'array of names', 'text string or array of text string'}
ISO_BASES = {
    '3.18': ['20'], '3.19': ['21'], '3.22': ['25'], '3.25': ['28'],
    '3.27': ['30'], '3.28': ['31'], '3.25b': ['50'],
    '3.41': ['44'], '4.39': ['5', '89'], '4.45': ['5', '95'],
    '8.1': ['150'], '8.6': ['155'], '8.8': ['157'], '8.21': ['170'],
    '8.39': ['188'], '8.39a': ['229'], '8.48a': ['193'],
    '8.82': ['233'], '8.83': ['234'], '8.109': ['260'], '8.110': ['261'],
    '9.33': ['298'], '9.34': ['299'], '9.35': ['5', '300'], '9.39': ['304'],
    '9.47': ['312'], '9.49': ['164'], '9.51f': ['304'], '10.17': ['330'],
}
ADOBE_BASES = {
    '3.19': ['3.18'], '8.111a': ['8.110'],
    '9.39b': ['9.39a'], '9.39c': ['9.39a'], '9.39d': ['9.39a'],
    '9.39e': ['9.39a'], '9.39f': ['9.39a'], '9.51f': ['9.39'],
}
NON_DICTIONARIES = {'8.1a': 'PDF name values', '8.20': 'annotation subtype names', '8.48': 'action subtype names', '8.73': 'XML body attributes', '9.51d': 'Binding name values'}
SECTIONS = {
    '3.18': '3.5', '3.19': '3.5.2', '3.22': '3.5.4',
    '3.25': '3.6.1', '3.27': '3.6.2', '3.28': '3.6.3',
    '3.25a': '3.6.4', '3.25b': '3.6.4', '3.41': '3.10.2',
    '4.39': '4.8.4', '4.45': '4.9.1', '8.1': '8.1',
    '8.21': '8.4.5', '8.39': '8.4.5', '8.48a': '8.5.3', '8.48b': '8.5.3',
    '8.82': '8.6.3', '8.83': '8.6.3', '8.39a': '8.6.3', '8.39b': '8.6.3',
    '8.109': '8.8', '8.110': '8.8', '9.33': '9.5.1', '9.34': '9.5.1',
    '9.35': '9.5.2', '9.39': '9.5.3', '9.47': '9.5.3', '10.17': '10.7.1',
}
FIXED_NAMES = {
    ('3.25a', 'Type'): 'Extensions', ('3.25b', 'Type'): 'DeveloperExtensions',
    ('8.6c', 'Type'): 'Folder', ('8.48a', 'S'): 'RichMediaExecute',
    ('8.48b', 'Type'): 'RichMediaCommand', ('8.39b', 'Type'): 'PaperMetaData',
    ('8.111b', 'Type'): 'GEOGCS', ('8.111c', 'Type'): 'PROJCS',
    ('8.111d', 'Type'): 'PtData', ('8.111d', 'Subtype'): 'Cloud',
    ('9.39a', 'Type'): '3DMeasure', ('9.39g', 'Type'): 'ExData',
    ('9.39g', 'Subtype'): '3DM', ('9.49', 'Subtype'): 'RichMedia',
    ('9.50', 'Type'): 'RichMediaSettings', ('9.50a', 'Type'): 'RichMediaActivation',
    ('9.50b', 'Type'): 'RichMediaDeactivation', ('9.50c', 'Type'): 'RichMediaAnimation',
    ('9.50d', 'Type'): 'RichMediaPresentation', ('9.50e', 'Type'): 'RichMediaWindow',
    ('9.50f', 'Type'): 'RichMediaPosition', ('9.51', 'Type'): 'RichMediaContent',
    ('9.51a', 'Type'): 'RichMediaConfiguration', ('9.51b', 'Type'): 'RichMediaInstance',
    ('9.51c', 'Type'): 'RichMediaParams', ('9.51e', 'Type'): 'CuePoint',
}
INDIRECT_DICTIONARY_ARRAYS = {('9.50a', 'Scripts'), ('9.51', 'Configurations'), ('9.51', 'Views'), ('9.51a', 'Instances')}
SPECIALIZATIONS = {
    '8.111a': ('GEO', 49), '9.39b': ('LD3', 63), '9.39c': ('PD3', 65),
    '9.39d': ('AD3', 68), '9.39e': ('RD3', 71), '9.39f': ('3DC', 74),
}


def clean(value: str) -> str:
    return re.sub(r'\s+', ' ', value).strip()


def column_text(chars: list[dict], start: float, end: float) -> str:
    selected = [char for char in chars if start - 1 <= char['x0'] < end - 1]
    return clean(pdfplumber.utils.extract_text(selected, x_tolerance=1, y_tolerance=3) or '')


def write(path: Path, value: object, check: bool) -> None:
    content = (json.dumps(value, indent=2, ensure_ascii=False) + '\n').encode('utf-8')
    if check:
        if not path.is_file() or path.read_bytes() != content:
            raise SystemExit(f'{path.name} differs; rerun the extractor without --check.')
    else:
        path.write_bytes(content)


def extract(root: Path, check: bool = False) -> None:
    source = root / 'resources/reference-documents' / DOCUMENT
    if hashlib.sha256(source.read_bytes()).hexdigest() != SHA256:
        raise ValueError('The retained supplement does not match the reviewed source document.')
    destination = root / 'resources' / 'adobe-supplement-iso32000'
    if not check:
        destination.mkdir(parents=True, exist_ok=True)
    reader = PdfReader(source)
    if len(reader.pages) != 140:
        raise ValueError('Expected the 140-page June 2008 supplement.')
    tables: dict[str, dict] = {}
    prose_pages = {}
    issues = []
    with pdfplumber.open(source) as pdf:
        for page_number, page in enumerate(pdf.pages[:101], 1):
            if page_number in {item[1] for item in SPECIALIZATIONS.values()}:
                prose_pages[page_number] = page.extract_text(x_tolerance=1)
            lines = page.extract_text_lines(return_chars=True, x_tolerance=1)
            captions = []
            active = None
            columns = None
            current = None
            clause = None
            for line in lines:
                text = clean(line['text'])
                if text == str(page_number):
                    continue
                if line['top'] < 65:
                    match = re.search(r'(\d+(?:\.\d+)+)\s+[A-Z]', text)
                    if match:
                        clause = match.group(1)
                    continue
                match = CAPTION.match(text)
                if match:
                    captions.append((line['top'], match.group(1), match.group(2)))
                    active = None
                    columns = None
                    continue
                header = re.match(r'^KEY\s+(TYPE|SUBTYPE)\s+(VALUE|DESCRIPTION)$', text, re.I)
                if header:
                    candidates = [caption for caption in captions if 0 < line['top'] - caption[0] < 60]
                    if not candidates:
                        issues.append({'kind': 'header_without_caption', 'pdf_page': page_number, 'text': text})
                        continue
                    _, identifier, title = candidates[-1]
                    if identifier in NON_DICTIONARIES:
                        continue
                    # Multi-line titles directly above the header are retained.
                    title_lines = [clean(item['text']) for item in lines if candidates[-1][0] < item['top'] < line['top']]
                    title = clean(' '.join([title, *title_lines]))
                    words = page.crop((line['x0'], line['top'] - 1, line['x1'] + 1, line['bottom'] + 1)).extract_words()
                    type_x = next(item['x0'] for item in words if item['text'] in ['TYPE', 'SUBTYPE'])
                    value_x = next(item['x0'] for item in words if item['text'] in ['VALUE', 'DESCRIPTION'])
                    columns = (line['x0'], type_x, value_x)
                    active = tables.setdefault(identifier, {
                        'id': identifier, 'title': title, 'section': SECTIONS.get(identifier, ('8.2.4' if identifier.startswith('8.6') or identifier == '8.8' else '8.8.1' if identifier.startswith('8.111') else '9.5.6' if identifier.startswith('9.39') or identifier == '9.33a' else '9.6.1' if identifier.startswith(('9.49', '9.50', '9.51')) else clause)),
                        'pdf_pages': [], 'iso_base_tables': ISO_BASES.get(identifier, []),
                        'adobe_base_tables': ADOBE_BASES.get(identifier, []), 'entries': [], 'variable_entries': [], 'prose_entries': [],
                    })
                    if page_number not in active['pdf_pages']:
                        active['pdf_pages'].append(page_number)
                    current = None
                    continue
                if active is None or columns is None:
                    continue
                if line['top'] > 740 or line['x0'] < columns[0] - 10:
                    active = None
                    columns = None
                    continue
                key = column_text(line['chars'], columns[0], columns[1])
                kind = column_text(line['chars'], columns[1], columns[2])
                description = column_text(line['chars'], columns[2], 10000)
                known_start = kind in TYPES or kind in ['text', 'byte', 'ASCII', 'date', 'text string or', 'array of text', 'array of']
                if key and re.fullmatch(r'[A-Za-z0-9_]+', key) and kind and known_start:
                    current = {'name': key, 'type': kind, 'description': description, 'pdf_page': page_number}
                    active['entries'].append(current)
                elif current is not None and (not key or (current['name'] == 'Prefix' and key == 'name' and not kind)):
                    if key:
                        current['name'] = 'Prefix name'
                    if kind:
                        current['type'] = clean(current['type'] + ' ' + kind)
                    current['description'] = clean(current['description'] + ' ' + description)
                elif key:
                    # This is prose outside the table, rather than an entry.
                    active = None
                    columns = None
            page.close()
    # Source row wraps require a small, explicit extraction repair: the variable
    # Prefix name spans two lines in the first column of table 3.25a.
    if '3.25a' in tables:
        for entry in tables['3.25a']['entries']:
            if entry['name'] in ['Prefix', 'Prefix name']:
                entry['name'] = 'Prefix name'
                tables['3.25a']['variable_entries'].append(entry)
        tables['3.25a']['entries'] = [entry for entry in tables['3.25a']['entries'] if entry['name'] != 'Prefix name']
    for table in tables.values():
        for entry in [*table['entries'], *table['variable_entries']]:
            description = entry['description']
            qualifier = re.match(r'^\(([^)]+)\)', description)
            entry['qualifier'] = clean(qualifier.group(1)) if qualifier else None
            wording = entry['qualifier'] or ''
            entry['required'] = bool(re.search(r'\brequired\b', wording, re.I))
            entry['conditional'] = bool(re.search(r'\b(?:if|when|unless|only)\b|\brequired\s+for\b', wording, re.I))
            entry['inheritable'] = 'inheritable' in wording.lower()
            introduced = re.search(r'ExtensionLevel\s*(\d+)|PDF\s+(\d+\.\d+)', wording)
            entry['introduced_in'] = ('Adobe 1.7 ExtensionLevel ' + introduced.group(1)) if introduced and introduced.group(1) else (introduced.group(2) if introduced else None)
            entry['direct'] = bool(re.search(r'(?:shall|must) (?:be a direct object|not be an indirect reference)', description, re.I))
            reference_wording = r'(?:shall|must) be an indirect(?: object)? reference|^\([^)]*\)\s*An indirect(?: object)? reference'
            if entry['type'] == 'dictionary':
                reference_wording += r'|is an indirect(?: object)? reference'
            entry['indirect'] = not entry['direct'] and bool(re.search(reference_wording, description, re.I))
            entry['array_elements_direct'] = False
            entry['array_elements_indirect'] = (table['id'], entry['name']) in INDIRECT_DICTIONARY_ARRAYS
            if entry['array_elements_indirect']:
                entry['validation_type'] = 'array of dictionaries'
            if (table['id'], entry['name']) == ('8.48b', 'A'):
                entry['validation_type'] = 'text string or integer or real or boolean or array'
                entry['array_member_validation_type'] = 'text string or integer or real or boolean'
            entry['fixed_name'] = FIXED_NAMES.get((table['id'], entry['name']))
            if entry['type'] not in TYPES:
                issues.append({'kind': 'unmapped_type', 'table': table['id'], 'entry': entry['name'], 'pdf_page': entry['pdf_page'], 'type': entry['type']})
    for table in tables.values():
        unique = {}
        for entry in table['entries']:
            if entry['name'] in unique:
                previous = unique[entry['name']]
                entry['source_occurrences'] = [*previous.get('source_occurrences', [{'pdf_page': previous['pdf_page'], 'description': previous['description']}]), {'pdf_page': entry['pdf_page'], 'description': entry['description']}]
            unique[entry['name']] = entry
        table['entries'] = list(unique.values())
        if table['id'] in SPECIALIZATIONS:
            name, page = SPECIALIZATIONS[table['id']]
            plain = prose_pages[page]
            if table['id'] == '8.111a':
                match = re.search(r'When the subtype of a measurement dictionary is GEO,.*?defined\.', plain, re.S)
                if not match:
                    raise ValueError('Missing source statement for the GEO specialization.')
                wording = clean(match.group(0))
            else:
                anchor = plain.index('Subtype value of ' + name)
                start = plain.rfind('In addition to the entries in', 0, anchor)
                end = plain.index('TABLE ' + table['id'], anchor)
                if start == -1:
                    raise ValueError('Missing source introduction for ' + table['id'])
                wording = clean(plain[start:end])
            # These are explicit subtype discriminants stated next to the
            # tables, not additional printed rows or inferred PDF extensions.
            table['prose_entries'].append({
                'name': 'Subtype', 'type': 'name', 'description': wording,
                'pdf_page': page, 'qualifier': 'Required; ExtensionLevel 3',
                'required': True, 'conditional': False, 'inheritable': False,
                'introduced_in': 'Adobe 1.7 ExtensionLevel 3', 'direct': False,
                'indirect': False, 'array_elements_direct': False,
                'array_elements_indirect': False, 'fixed_name': name,
                'origin': 'prose specialization',
            })
    corrections_path = destination / 'corrections.json'
    corrections = json.loads(corrections_path.read_text(encoding='utf-8')) if corrections_path.is_file() else []
    corrections_by_entry = {(item['table'], item['entry']): item for item in corrections}
    for table in tables.values():
        for entry in [*table['entries'], *table['variable_entries'], *table['prose_entries']]:
            entry['source_constraints'] = {key: entry[key] for key in ['type', 'required', 'conditional', 'direct', 'indirect', 'array_elements_direct', 'array_elements_indirect', 'fixed_name']}
            correction = corrections_by_entry.get((table['id'], entry['name']))
            if correction:
                entry['implementation_correction'] = correction
                entry.update(correction['constraints'])
    metadata = {
        'document': DOCUMENT, 'path': 'resources/reference-documents/' + DOCUMENT,
        'document_sha256': SHA256, 'standard': STANDARD,
        'base_version': '1.7', 'extension_level': 3, 'edition': '1.0', 'date': 'June 2008',
        'pdf_pages': 140, 'part_i_pdf_pages': [9, 103], 'part_ii_pdf_pages': [106, 132],
        'dictionary_tables': len(tables), 'dictionary_entries': sum(len(table['entries']) + len(table['variable_entries']) for table in tables.values()),
        'prose_specialization_entries': sum(len(table['prose_entries']) for table in tables.values()),
        'scope': 'Object schemas for printed PDF dictionary tables in Part I; algorithms, XML and Adobe application implementation notes are catalogued separately.',
    }
    if len(tables) != 61 or metadata['dictionary_entries'] != 276 or metadata['prose_specialization_entries'] != 6 or issues:
        raise ValueError(f'Unexpected extraction coverage: {metadata}; issues: {issues}')
    write(destination / 'metadata.json', metadata, check)
    write(destination / 'dictionaries.json', list(tables.values()), check)
    write(destination / 'extraction-issues.json', issues, check)
    print(f"Extracted {len(tables)} dictionary tables, {metadata['dictionary_entries']} entries; {len(issues)} extraction issues.")


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--check', action='store_true')
    arguments = parser.parse_args()
    extract(arguments.root, arguments.check)
