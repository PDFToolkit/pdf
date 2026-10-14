"""Independent qpdf/pypdf checks for real PDF2 structure destinations.

ISO 32000-2:2020 12.3.2.3–12.3.2.4, physical pages458–459;
Table202 page526; EC3 issues162/288 on page459 are Completed.
"""
from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

from pypdf import PdfReader
from pypdf.generic import ArrayObject, DictionaryObject, IndirectObject, NumberObject


def run(*arguments: str) -> None:
    subprocess.run(arguments, check=True, capture_output=True, text=True, encoding='utf-8')


def first_content_page(element, seen=None):
    """Follow the normative ordered content search, independently of PHP helpers."""
    seen = set() if seen is None else seen
    identity = (element.idnum, element.generation) if isinstance(element, IndirectObject) else id(element)
    assert identity not in seen, 'A structure hierarchy must not contain a cycle.'
    seen.add(identity)
    owner = element.get_object()
    kids = owner.raw_get('/K') if '/K' in owner else ArrayObject()
    kids = kids.get_object()
    kids = kids if isinstance(kids, ArrayObject) else [kids]
    for reference in kids:
        value = reference.get_object()
        if isinstance(value, NumberObject):
            return owner.raw_get('/Pg')
        assert isinstance(value, DictionaryObject)
        if value.get('/Type') in ('/MCR', '/OBJR'):
            return value.raw_get('/Pg') if '/Pg' in value else owner.raw_get('/Pg')
        page = first_content_page(reference, seen)
        if page is not None:
            return page
    return None


def inspect(path: Path) -> dict:
    reader = PdfReader(path)
    assert reader.pdf_header == '%PDF-2.0' and len(reader.pages) == 2
    root = reader.trailer['/Root']
    first, second = reader.pages
    structure = root['/StructTreeRoot']
    assert structure['/Namespaces'][0].get_object()['/NS'] == 'http://iso.org/pdf2/ssn'
    document = structure['/K'][0].get_object()
    assert document['/S'] == '/Document' and len(document['/K']) == 3
    target = document['/K'][1]
    empty = document['/K'][2]
    assert target.get_object()['/K'][0].get_object().raw_get('/Pg') == first.indirect_reference
    assert '/K' not in target.get_object()['/K'][0].get_object()
    assert first_content_page(target) == second.indirect_reference
    assert empty.get_object().raw_get('/Pg') == second.indirect_reference
    assert first_content_page(empty) is None  # Pg alone is not a content item.
    pairs = root['/Names']['/Dests']['/Names']
    named = {str(pairs[index]): pairs[index + 1].get_object() for index in range(0, len(pairs), 2)}
    assert set(named) == {'target-section', 'empty-section'}
    assert named['target-section']['/SD'][0] == target
    assert named['target-section']['/D'][0] == second.indirect_reference
    assert named['empty-section']['/SD'][0] == empty
    assert named['empty-section']['/D'][0] == first.indirect_reference
    annotations = first['/Annots']
    assert len(annotations) == 2 and first['/Tabs'] == '/S'
    explicit, named_link = [item.get_object()['/A'] for item in annotations]
    assert explicit['/S'] == '/GoTo' and explicit['/SD'][0] == target
    assert explicit['/D'][0] == second.indirect_reference
    assert named_link['/D'] == 'target-section'
    parents = structure['/ParentTree']['/Nums']
    owners = {int(parents[index]): parents[index + 1].get_object() for index in range(0, len(parents), 2)}
    for index, annotation in enumerate(annotations):
        assert owners[100 + index]['/S'] == '/Link'
        objects = [kid.get_object() for kid in owners[100 + index]['/K'] if isinstance(kid.get_object(), DictionaryObject)]
        assert any(kid.get('/Type') == '/OBJR' and kid.raw_get('/Obj') == annotation for kid in objects)
    for page in reader.pages:
        font = page['/Resources']['/Font']['/F1']
        program = font['/DescendantFonts'][0].get_object()['/FontDescriptor']['/FontFile2'].get_data()
        assert program[:4] == b'\0\1\0\0' and len(program) > 1000
        assert '/ToUnicode' in font
    assert 'Follow the explicit structure destination' in first.extract_text()
    assert 'Semantic target: the section on page 2' in second.extract_text()
    return {'pages': 2, 'link_annotations': 2, 'named_sd_values': 2, 'semantic_target_page': 2, 'empty_target_fallback_page': 1, 'embedded_font': True}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--qpdf', required=True)
    parser.add_argument('--work-dir', type=Path, required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    output = args.work_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    reports = {}
    for kind, flags in [('table', []), ('stream', ['--xref-stream']), ('hybrid', ['--hybrid'])]:
        path = output / f'structure-destinations-{kind}.pdf'
        run(args.php, str(root / 'examples/pdf20-structure-destinations.php'), str(path), *flags)
        run(args.qpdf, '--check', str(path))
        reports[kind] = inspect(path)
    (output / 'report.json').write_text(json.dumps(reports, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(reports, indent=2))


if __name__ == '__main__':
    main()
