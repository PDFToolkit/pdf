"""Independent native incremental-permission checks with pyHanko, pypdf and qpdf.

Keep the default pyHanko difference policy unchanged. It is an independent
implementation, not a universal ISO oracle: metadata may be treated as LTA and
some permitted annotation changes are deliberately unsupported. Negative probes
therefore change actual page rotation/content or a genuinely locked field.
Cryptographic integrity is checked separately from PKI trust; these synthetic
certificates are not installed as trust anchors and no fetching is enabled.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import logging
from pathlib import Path
import subprocess

from pypdf import PdfReader
from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.validation import validate_pdf_signature
from pyhanko_certvalidator import ValidationContext


def command(*arguments: str) -> str:
    result = subprocess.run(arguments, check=True, capture_output=True, text=True, encoding='utf-8')
    return result.stdout


def inspect(path: Path, qpdf: str, count: int = 1, forbidden: bool = False) -> dict:
    command(qpdf, '--check', str(path))
    independent = PdfReader(path, strict=True)
    assert len(independent.pages) == 1, path.name
    context = ValidationContext(trust_roots=[], other_certs=[], allow_fetching=False, revocation_mode='soft-fail')
    signatures = []
    with path.open('rb') as stream:
        reader = PdfFileReader(stream, strict=True)
        assert len(reader.embedded_signatures) == count, (path.name, 'signature count')
        for embedded in reader.embedded_signatures:
            status = validate_pdf_signature(embedded, signer_validation_context=context,
                ts_validation_context=context, skip_diff=False)
            assert status.intact and status.valid, (path.name, embedded.field_name, 'actual signature mathematics')
            assert not status.trusted, (path.name, 'test certificate must not acquire implicit PKI trust')
            assert status.docmdp_ok is (not forbidden), (path.name, embedded.field_name, status.diff_result)
            level = status.modification_level.name if status.modification_level is not None else None
            if forbidden:
                assert level == 'OTHER', (path.name, level)
                assert status.coverage.name == 'ENTIRE_REVISION', (path.name, status.coverage)
            signatures.append({
                'field': embedded.field_name,
                'intact': status.intact,
                'valid': status.valid,
                'trusted': status.trusted,
                'coverage': status.coverage.name,
                'docmdpLevel': embedded.docmdp_level.name if embedded.docmdp_level is not None else None,
                'docmdp_ok': status.docmdp_ok,
                'modificationLevel': level,
                'diffType': type(status.diff_result).__name__,
            })
    return {'sha256': hashlib.sha256(path.read_bytes()).hexdigest(), 'qpdf': True, 'pypdf': True, 'signatures': signatures}


def change_page(source: Path, target: Path, content: bool) -> None:
    with source.open('rb') as stream:
        writer = IncrementalPdfFileWriter(stream, strict=True)
        reference, _ = writer.find_page_for_modification(0)
        page = reference.get_object()
        if content:
            page[generic.NameObject('/Contents')] = writer.add_object(generic.StreamObject(
                stream_data=b'% Independent forbidden page content\nq 1 0 0 rg 0 0 30 30 re f Q\n'))
        else:
            page[generic.NameObject('/Rotate')] = generic.NumberObject((int(page.get('/Rotate', 0)) + 90) % 360)
        writer.mark_update(reference)
        with target.open('wb') as output:
            writer.write(output)
    assert target.read_bytes().startswith(source.read_bytes()), 'The adversarial update must preserve the signed prefix.'
    page = PdfReader(target, strict=True).pages[0]
    if content:
        assert b'Independent forbidden page content' in page.get_contents().get_data()
    else:
        original = PdfReader(source, strict=True).pages[0]
        assert int(page.get('/Rotate', 0)) != int(original.get('/Rotate', 0))


def change_locked_field(source: Path, target: Path) -> str:
    marker = 'Independent forbidden locked field value'
    with source.open('rb') as stream:
        writer = IncrementalPdfFileWriter(stream, strict=True)
        signature = writer.prev.embedded_signatures[0]
        policy = signature.fieldmdp
        assert policy is not None
        pending = [(reference, '', None) for reference in writer.root['/AcroForm']['/Fields']]
        visited = set()
        selected = None
        while pending:
            reference, prefix, inherited_type = pending.pop()
            identity = (reference.idnum, reference.generation)
            assert identity not in visited and len(visited) < 4096, 'Bounded, unambiguous fixture field tree required.'
            visited.add(identity)
            field = reference.get_object()
            partial = str(field.get('/T', ''))
            name = '.'.join(part for part in [prefix, partial] if part)
            field_type = field.get('/FT', inherited_type)
            if str(field_type) == '/Tx' and policy.is_locked(name):
                assert selected is None, 'Use one unambiguous locked text fixture field.'
                selected = name
                field[generic.NameObject('/V')] = generic.TextStringObject(marker)
                writer.mark_update(reference)
            pending.extend((child, name, field_type) for child in field.get('/Kids', []))
        assert selected is not None, 'The fixture must actually lock a text field.'
        with target.open('wb') as output:
            writer.write(output)
    assert target.read_bytes().startswith(source.read_bytes()), 'The field attack must preserve the signed prefix.'
    fields = PdfReader(target, strict=True).get_fields()
    assert fields is not None and fields[selected]['/V'] == marker
    return selected


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--qpdf', required=True)
    parser.add_argument('--work-dir', required=True, type=Path)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    work = args.work_dir.resolve()
    work.mkdir(parents=True, exist_ok=True)
    logging.getLogger('pyhanko_certvalidator').setLevel(logging.CRITICAL)
    logging.getLogger('pyhanko.sign.validation.generic_cms').setLevel(logging.CRITICAL)
    logging.getLogger('pyhanko.sign.diff_analysis').setLevel(logging.CRITICAL)
    native = json.loads(command(args.php, str(root / 'tools/fixtures/incremental-permissions.php'), str(work)))
    expected = {f'P{permission}:{attack}' for permission in [1, 2, 3] for attack in [
        'lang', 'page', 'content', 'remove-perms', 'remove-form', 'root', 'trailer', 'release',
        'field-value', 'field-name', 'fake-xref', 'orphan']}
    expected.update(f'P{permission}:timestamp:{attack}' for permission in [1, 2, 3] for attack in [
        'lang', 'page', 'content', 'field-value', 'remove-perms', 'fake-xref'])
    expected.update(['P2:approval:content', 'P3:approval:content', 'FieldMDP:locked-value',
        'FieldMDP:hidden-form', 'opaque-finalize', 'manual-history', 'cloned-history', 'UR:hidden-authority'])
    rejected = native['rejected']
    assert expected <= rejected.keys(), ('missing native rejection cases', sorted(expected - rejected.keys()))
    assert all(value is True for value in rejected.values()), 'Every reported native refusal must be a real rejection.'
    reports = {}
    for permission in [1, 2, 3]:
        name = f'core-p{permission}.pdf'
        reports[name] = inspect(work / name, args.qpdf)
        assert reports[name]['signatures'][0]['docmdpLevel'] == {
            1: 'NO_CHANGES', 2: 'FILL_FORMS', 3: 'ANNOTATE'}[permission]
        for content in [False, True]:
            attacked = f'independent-p{permission}-' + ('content' if content else 'rotate') + '.pdf'
            change_page(work / name, work / attacked, content)
            reports[attacked] = inspect(work / attacked, args.qpdf, forbidden=True)
        if permission > 1:
            approved = f'core-p{permission}-approval.pdf'
            reports[approved] = inspect(work / approved, args.qpdf, count=2)
            assert reports[approved]['signatures'][0]['modificationLevel'] == 'FORM_FILLING'
    reports['core-p1-dss.pdf'] = inspect(work / 'core-p1-dss.pdf', args.qpdf)
    assert reports['core-p1-dss.pdf']['signatures'][0]['modificationLevel'] == 'LTA_UPDATES'
    reports['core-fieldmdp.pdf'] = inspect(work / 'core-fieldmdp.pdf', args.qpdf)
    assert reports['core-fieldmdp.pdf']['signatures'][0]['docmdpLevel'] is None
    locked_name = change_locked_field(work / 'core-fieldmdp.pdf', work / 'independent-locked-field.pdf')
    reports['independent-locked-field.pdf'] = inspect(work / 'independent-locked-field.pdf', args.qpdf, forbidden=True)
    report = {'native_rejected': len(rejected), 'native_cases': sorted(rejected),
        'independent_adversarial_updates': 7, 'locked_field': locked_name,
        'trust_roots': [], 'fetching': False, 'diff_policy': 'Unmodified pyHanko default', 'files': reports}
    (work / 'incremental-permissions-report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'native_rejected': len(rejected), 'independent_adversarial_updates': 7,
        'independently_checked_files': len(reports), 'math_and_permissions_separated': True,
        'implicit_pki_trust': False}, indent=2))


if __name__ == '__main__':
    main()
