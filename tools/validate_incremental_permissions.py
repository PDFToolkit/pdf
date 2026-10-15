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
import asyncio
import hashlib
import json
import logging
from pathlib import Path
import re
import subprocess

from asn1crypto import cms
from pypdf import PdfReader
from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.validation import async_validate_detached_cms, validate_pdf_signature
from pyhanko_certvalidator import ValidationContext


def command(*arguments: str) -> str:
    result = subprocess.run(arguments, capture_output=True, text=True, encoding='utf-8')
    if result.returncode:
        raise AssertionError(f'{arguments[0]} exited {result.returncode}:\n{result.stdout}{result.stderr}')
    return result.stdout


def inspect(path: Path, qpdf: str, count: int = 1, forbidden: bool = False,
            expected_permission: bool | None = True, pages: int = 1) -> dict:
    command(qpdf, '--check', str(path))
    independent = PdfReader(path, strict=True)
    assert len(independent.pages) == pages, path.name
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
            expected = False if forbidden else expected_permission
            if expected is not None:
                assert status.docmdp_ok is expected, (path.name, embedded.field_name, status.diff_result)
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


def field_reference(writer: IncrementalPdfFileWriter, name: str):
    pending = [(reference, '') for reference in writer.root['/AcroForm']['/Fields']]
    visited = set()
    while pending:
        reference, parent = pending.pop()
        identity = (reference.idnum, reference.generation)
        assert identity not in visited and len(visited) < 4096
        visited.add(identity)
        field = reference.get_object()
        partial = str(field.get('/T', ''))
        full_name = '.'.join(part for part in (parent, partial) if part)
        if full_name == name:
            return reference
        pending.extend((child, full_name) for child in field.get('/Kids', []))
    raise AssertionError('Missing independent fixture field: ' + name)


def change_form_value(source: Path, target: Path, kind: str, name='value'):
    with source.open('rb') as stream:
        writer = IncrementalPdfFileWriter(stream, strict=True)
        reference = field_reference(writer, name)
        field = reference.get_object()
        value = generic.TextStringObject('changed') if kind in ('text', 'choice', 'richtext', 'file-select', 'text-stream', 'richtext-stream') else generic.NameObject('/A' if kind == 'radio' else '/Yes')
        if kind.endswith('-stream'):
            value = writer.add_object(generic.StreamObject(stream_data=b'changed'))
        field[generic.NameObject('/V')] = value
        if kind.startswith('richtext'):
            xml = '<body><p><b>changed</b></p></body>'
            field[generic.NameObject('/RV')] = (writer.add_object(generic.StreamObject(stream_data=xml.encode()))
                if kind.endswith('-stream') else generic.TextStringObject(xml))
        writer.mark_update(reference)
        widgets = field.get('/Kids', [])
        for widget_reference in widgets:
            widget = widget_reference.get_object()
            state = value if value in widget['/AP']['/N'] else generic.NameObject('/Off')
            widget[generic.NameObject('/AS')] = state
            writer.mark_update(widget_reference)
        with target.open('wb') as output:
            writer.write(output)
    assert target.read_bytes().startswith(source.read_bytes()), 'Independent updates preserve the signed prefix.'
    assert_form_value(target, kind, name=name)


def change_shared_appearance(source: Path, target: Path) -> None:
    with source.open('rb') as stream:
        writer = IncrementalPdfFileWriter(stream, strict=True)
        field = field_reference(writer, 'value').get_object()
        page_reference, _ = writer.find_page_for_modification(0)
        shared = page_reference.get_object()['/Resources']['/XObject'].raw_get('/Shared')
        selected = field['/Kids'][0].get_object()['/AP']['/N'].raw_get('/Yes')
        assert (shared.idnum, shared.generation) == (selected.idnum, selected.generation)
        appearance = shared.get_object()
        assert '/Filter' not in appearance, 'Unfiltered oracle appearance required.'
        writer.objects[(shared.generation, shared.idnum)] = generic.StreamObject(
            dict(appearance), stream_data=b'1 0 0 rg 0 0 20 20 re f\n')
        with target.open('wb') as output:
            writer.write(output)
    assert target.read_bytes().startswith(source.read_bytes())
    # Isolate the shared-object attack: neither V nor any widget AS is changed.
    fields = PdfReader(target, strict=True).get_fields()
    assert fields['value']['/V'] == '/Off'
    assert all(reference.get_object()['/AS'] == '/Off' for reference in fields['value']['/Kids'])


def assert_form_value(path: Path, kind: str, name='value', read_only=False):
    reader = PdfReader(path, strict=True)
    fields = reader.get_fields()
    assert fields is not None and name in fields, (path.name, name)
    field = fields[name]
    expected_type = {'text': '/Tx', 'choice': '/Ch', 'checkbox': '/Btn', 'radio': '/Btn', 'richtext': '/Tx', 'file-select': '/Tx', 'text-stream': '/Tx', 'richtext-stream': '/Tx'}[kind]
    expected_value = 'changed' if kind in ('text', 'choice', 'richtext', 'file-select', 'text-stream', 'richtext-stream') else '/A' if kind == 'radio' else '/Yes'
    # Inspect actual objects: get_fields() normalizes stream V into a text value.
    raw_fields = reader.trailer['/Root']['/AcroForm']['/Fields']
    raw = next(reference.get_object() for reference in raw_fields
               if str(reference.get_object().get('/T', '')) == name)
    actual = raw['/V']
    actual = actual.get_data().decode('utf-8') if kind.endswith('-stream') else str(actual)
    assert str(field['/FT']) == expected_type and actual == expected_value, path.name
    if kind.startswith('richtext'):
        assert int(field['/Ff']) & (1 << 25)
        # get_fields() exposes a normalized subset and intentionally omits RV.
        rich = raw['/RV']
        rich = rich.get_data().decode('utf-8') if kind.endswith('-stream') else str(rich)
        assert rich == '<body><p><b>changed</b></p></body>'
    if kind == 'file-select':
        assert int(field['/Ff']) & (1 << 20)
    if read_only:
        assert int(field.get('/Ff', 0)) & 1, 'The source must actually retain its ReadOnly flag.'
    for index, widget_reference in enumerate(field.get('/Kids', [])):
        widget = widget_reference.get_object()
        expected_state = expected_value if index == 0 else '/Off'
        assert str(widget['/AS']) == expected_state and expected_state in widget['/AP']['/N']


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


def rendered_pixels(path: Path, pdftoppm: str, checks: list[tuple[int, int, tuple[int, int, int]]], page: int = 1) -> dict:
    prefix = path.with_suffix('') if page == 1 else path.with_name(path.stem + '-page' + str(page))
    command(pdftoppm, '-r', '72', '-f', str(page), '-l', str(page), '-singlefile', str(path), str(prefix))
    image = prefix.with_suffix('.ppm')
    data = image.read_bytes()
    header = re.match(rb'P6\s+(\d+)\s+(\d+)\s+255\s', data)
    assert header is not None
    width, height = int(header[1]), int(header[2])
    samples = data[header.end():]
    assert (width, height) == (200, 200) and len(samples) == width * height * 3
    for x, y, color in checks:
        offset = ((height - 1 - y) * width + x) * 3
        assert tuple(samples[offset:offset + 3]) == color, (path.name, x, y)
    return {'renderer': 'Poppler pdftoppm', 'pixelChecks': len(checks),
        'ppmSha256': hashlib.sha256(data).hexdigest()}


def button_structure(source: Path, target: Path) -> None:
    old_fields = PdfReader(source, strict=True).get_fields()
    new_fields = PdfReader(target, strict=True).get_fields()
    old_widgets, new_widgets = old_fields['value']['/Kids'], new_fields['value']['/Kids']
    assert len(old_widgets) == len(new_widgets)
    for old_reference, new_reference in zip(old_widgets, new_widgets):
        assert (old_reference.idnum, old_reference.generation) == (new_reference.idnum, new_reference.generation)
        old_states = old_reference.get_object()['/AP']['/N']
        new_states = new_reference.get_object()['/AP']['/N']
        assert old_states.keys() == new_states.keys()
        for state in old_states:
            old_ref, new_ref = old_states.raw_get(state), new_states.raw_get(state)
            assert (old_ref.idnum, old_ref.generation) == (new_ref.idnum, new_ref.generation)
            assert old_ref.get_object().get_data() == new_ref.get_object().get_data()


def inspect_annotations(work: Path, native: dict, qpdf: str, pdftoppm: str, reports: dict) -> list[dict]:
    cases = native['annotations']
    assert {(item['format'], item['version']) for item in cases} == {
        (format, version) for format in ('Table', 'Stream') for version in ('1.7', '2.0')}
    limits = []
    for item in cases:
        stem = item['stem']
        previous = None
        original_content = None
        old_appearance = None
        for stage, color in [('', (255, 255, 255)), ('-added', (0, 0, 255)),
                             ('-modified', (255, 0, 0)), ('-detached', (255, 255, 255))]:
            path = work / f'{stem}{stage}.pdf'
            if previous is not None:
                assert path.read_bytes().startswith(previous.read_bytes())
            independent = PdfReader(path, strict=True)
            page = independent.pages[0]
            content = page.get_contents().get_data()
            if original_content is None:
                original_content = content
            assert content == original_content, 'Annotations must not change the signed page contents.'
            annots = page.get('/Annots', [])
            if stage in ('', '-detached'):
                assert len(annots) == 0
            else:
                assert len(annots) == 1
                annotation = annots[0].get_object()
                assert annotation['/Subtype'] == '/Square' and list(annotation['/Rect']) == [20, 20, 40, 40]
                assert int(annotation['/F']) == 4
                assert annotation['/Contents'] == ('review' if stage == '-added' else 'updated review')
                appearance = annotation['/AP']['/N']
                assert appearance['/Subtype'] == '/Form' and list(appearance['/BBox']) == [0, 0, 20, 20]
                assert len(appearance['/Resources']) == 0
                if stage == '-added':
                    old_appearance = appearance.indirect_reference
                    assert b'0 0 1 rg' in appearance.get_data()
                else:
                    assert b'1 0 0 rg' in appearance.get_data()
                    assert appearance.indirect_reference.idnum != old_appearance.idnum
                    assert b'0 0 1 rg' in independent.get_object(old_appearance.idnum).get_data()
            # pyHanko deliberately does not implement arbitrary annotation deltas.
            # Keep its default outcome, while independently proving structure,
            # actual signature mathematics and the rendered annotation changes.
            report = inspect(path, qpdf, expected_permission=True if stage == '' else None)
            report['annotationRendering'] = rendered_pixels(path, pdftoppm,
                [(30, 30, color), (10, 10, (255, 255, 255)), (60, 60, (255, 255, 255))])
            reports[path.name] = report
            if stage:
                limits.append({'file': path.name, 'nativePermitted': True,
                    'pyhankoDocMdpOk': report['signatures'][0]['docmdp_ok'],
                    'pyhankoModificationLevel': report['signatures'][0]['modificationLevel'],
                    'structureAndPixelsIndependentlyVerified': True})
            previous = path
        assert native['rejected'][stem + ':existing-appearance'] is True
    return limits


def inspect_usage_rights(work: Path, native: dict, qpdf: str, reports: dict) -> dict:
    cases = native['usage_rights']
    expected = {'ur3-p17': True, 'ur3-p20': False, 'ur3-fullsave17': True,
        'ur3-nosave17': False, 'ur3-unrelated-save': False, 'ur3-fullsave-only': False,
        'ur3-signature-only': False, 'ur3-false20': True, 'ur3-unrestricted20': True, 'ur3-fullsave20': True}
    assert {item['stem']: item['allowed'] for item in cases} == expected
    context = ValidationContext(trust_roots=[], other_certs=[], allow_fetching=False, revocation_mode='soft-fail')
    checked = 0
    tampered_checked = False
    for item in cases:
        source = work / (item['stem'] + '.pdf')
        if not item['allowed']:
            assert native['rejected']['UR:' + item['stem']] is True
        for filled in (False, True) if item['allowed'] else (False,):
            path = work / (item['stem'] + ('-filled' if filled else '') + '.pdf')
            raw = path.read_bytes()
            if filled:
                assert raw.startswith(source.read_bytes())
                assert_form_value(path, 'text')
            command(qpdf, '--check', str(path))
            reader = PdfReader(path, strict=True)
            assert reader.pdf_header == '%PDF-' + item['version'] and len(reader.pages) == 1
            assert not reader.is_encrypted and '/Encrypt' not in reader.trailer
            signature = reader.trailer['/Root']['/Perms']['/UR3']
            assert signature['/SubFilter'] == '/adbe.pkcs7.detached'
            references = signature['/Reference']
            assert len(references) == 1 and references[0]['/TransformMethod'] == '/UR'
            parameters = references[0]['/TransformParams']
            # P is absent for its false default. Preserve that distinction from
            # the version-dependent operation/saving decisions tested natively.
            assert ('/P' in parameters) is item['restrict']
            if item['restrict']:
                assert parameters['/P'].value is True
            rights = {key[1:] + '.' + str(value)[1:] for key in ('/Document', '/Form', '/Annots', '/Signature', '/EF')
                for value in parameters.get(key, [])}
            assert rights == set(item['rights'])
            assert item['applicationScope'] == 'Form.FillIn'
            offsets = list(signature['/ByteRange'])
            assert len(offsets) == 4 and offsets[0] == 0 and 0 < offsets[1] < offsets[2]
            assert offsets[2] + offsets[3] == len(source.read_bytes()) <= len(raw)
            assert raw[offsets[1]:offsets[1] + 1] == b'<' and raw[offsets[2] - 1:offsets[2]] == b'>'
            contents = signature.raw_get('/Contents').original_bytes
            assert bytes.fromhex(raw[offsets[1] + 1:offsets[2] - 1].decode('ascii')) == contents
            signed_data = cms.ContentInfo.load(contents)['content']
            payload = raw[:offsets[1]] + raw[offsets[2]:offsets[2] + offsets[3]]
            status = asyncio.run(async_validate_detached_cms(payload, signed_data, signer_validation_context=context))
            assert status.intact and status.valid and not status.trusted, path.name
            fingerprint = hashlib.sha256(status.signing_cert.dump()).hexdigest()
            assert fingerprint == item['signerSha256']
            if not tampered_checked:
                tampered = bytes([payload[0] ^ 1]) + payload[1:]
                invalid = asyncio.run(async_validate_detached_cms(tampered, signed_data, signer_validation_context=context))
                assert not invalid.intact, 'Independent CMS verification must detect a changed signed byte.'
                tampered_checked = True
            reports[path.name] = {'sha256': hashlib.sha256(raw).hexdigest(), 'qpdf': True, 'pypdf': True,
                'usageRightsCms': {'intact': status.intact, 'valid': status.valid, 'trusted': status.trusted,
                    'signerSha256': fingerprint, 'signedRevisionBytes': offsets[2] + offsets[3],
                    'declaredRights': sorted(rights), 'applicationScope': item['applicationScope'],
                    'nativeFillAllowed': item['allowed'], 'filled': filled,
                    'authority': 'Explicit fixture application policy; no PKI or vendor licensing authority inferred.'}}
            checked += 1
    return {'sourceCases': len(cases), 'permittedFills': sum(expected.values()),
        'independentlyVerifiedCmsFiles': checked, 'tamperedSignedByteRejected': tampered_checked,
        'boundary': 'CMS mathematics and declared rights are checked independently. The granting identity and scope are explicit application policy, not pyHanko or PKI trust.'}


def inspect_templates(work: Path, native: dict, qpdf: str, pdftoppm: str, reports: dict) -> list:
    cases = native['template_instantiations']
    assert {(c['permission'], c['format'], c['version']) for c in cases} == {
        (p, x, v) for p in (2, 3) for x in ('Table', 'Stream') for v in ('1.7', '2.0')}
    results = []
    for item in cases:
        source = work / (item['stem'] + '.pdf')
        previous = source
        for suffix, pages in [('', 1), ('-spawned', 2), ('-twice', 3)]:
            path = work / (item['stem'] + suffix + '.pdf')
            assert path.read_bytes().startswith(previous.read_bytes())
            report = inspect(path, qpdf, expected_permission=True if pages == 1 else None, pages=pages)
            reader = PdfReader(path, strict=True)
            template = reader.trailer['/Root']['/Names']['/Templates']['/Names'][1].get_object()
            assert template['/Type'] == '/Template' and '/Parent' not in template
            for number, page in enumerate(reader.pages):
                if number:
                    assert page['/TemplateInstantiated'] == '/Invoice'
                    assert page.get_contents().get_data() == template['/Contents'].get_data()
                report.setdefault('templateRendering', []).append(rendered_pixels(path, pdftoppm,
                    [(30, 30, (0, 0, 255) if number else (255, 255, 255)),
                     (10, 10, (255, 255, 255))], page=number + 1))
            reports[path.name] = report
            previous = path
        results.append({'stem': item['stem'], 'sourcePages': 1, 'instantiatedPages': 3,
                        'native': reports[previous.name]['signatures']})
    return results


def inspect_radio_optional_off(work: Path, native: dict, qpdf: str, pdftoppm: str, reports: dict) -> list:
    cases = native['radio_optional_off']
    assert {(c['permission'], c['format']) for c in cases} == {(p, x) for p in (2, 3) for x in ('Table', 'Stream')}
    results = []
    for item in cases:
        previous = work / (item['stem'] + '.pdf')
        original_appearances = None
        for suffix, selection in [('', 'A'), ('-selected', 'B'), ('-cleared', 'Off')]:
            path = work / (item['stem'] + suffix + '.pdf')
            assert path.read_bytes().startswith(previous.read_bytes())
            report = inspect(path, qpdf, expected_permission=True if not suffix else None)
            fields = PdfReader(path, strict=True).get_fields()
            field = fields['radio']
            assert field['/V'] == '/' + selection
            appearances = []
            for index, ref in enumerate(field['/Kids']):
                widget = ref.get_object()
                on = ('A', 'B')[index]
                assert widget['/AS'] == '/' + (on if selection == on else 'Off')
                normal = widget['/AP']['/N']
                assert list(normal.keys()) == ['/' + on] and '/Off' not in normal
                stream = normal.raw_get('/' + on)
                appearances.append((ref.idnum, ref.generation, stream.idnum, stream.generation, stream.get_object().get_data()))
            if original_appearances is None:
                original_appearances = appearances
            assert appearances == original_appearances
            report['radioRendering'] = rendered_pixels(path, pdftoppm,
                [(40, 40, (255, 0, 0) if selection == 'A' else (255, 255, 255)),
                 (100, 40, (0, 0, 255) if selection == 'B' else (255, 255, 255))])
            reports[path.name] = report
            previous = path
        results.append({'stem': item['stem'], 'native': reports[previous.name]['signatures']})
    return results


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--qpdf', required=True)
    parser.add_argument('--pdftoppm', default='pdftoppm')
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
    expected.difference_update(['P2:field-value', 'P3:field-value'])
    expected.update(f'P{permission}:{case}' for permission in (2, 3)
        for case in ('form-fill:content', 'readonly-value'))
    expected.update(['P1:annotation', 'P2:annotation'])
    expected.update('UR:' + case for case in ('no-authority', 'authority-refused', 'missing-scope',
        'authority-not-persistent', 'hidden-content', 'removed-permissions'))
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
            filled = f'core-p{permission}-form-fill.pdf'
            assert (work / filled).read_bytes().startswith((work / name).read_bytes())
            assert_form_value(work / filled, 'text', name='name')
            reports[filled] = inspect(work / filled, args.qpdf)
            assert reports[filled]['signatures'][0]['modificationLevel'] == 'FORM_FILLING'
        independent_fill = f'independent-p{permission}-form-fill.pdf'
        change_form_value(work / name, work / independent_fill, 'text', name='name')
        reports[independent_fill] = inspect(work / independent_fill, args.qpdf, expected_permission=permission > 1)
        assert reports[independent_fill]['signatures'][0]['modificationLevel'] == 'FORM_FILLING'
    reports['core-p1-dss.pdf'] = inspect(work / 'core-p1-dss.pdf', args.qpdf)
    assert reports['core-p1-dss.pdf']['signatures'][0]['modificationLevel'] == 'LTA_UPDATES'
    reports['core-fieldmdp.pdf'] = inspect(work / 'core-fieldmdp.pdf', args.qpdf)
    assert reports['core-fieldmdp.pdf']['signatures'][0]['docmdpLevel'] is None
    locked_name = change_locked_field(work / 'core-fieldmdp.pdf', work / 'independent-locked-field.pdf')
    reports['independent-locked-field.pdf'] = inspect(work / 'independent-locked-field.pdf', args.qpdf, forbidden=True)
    form_fills = native['form_fills']
    combinations = {(item['permission'], item['format'], item['version'], item['kind']) for item in form_fills}
    assert combinations == {(p, xref, version, kind) for p in (2, 3) for xref in ('Table', 'Stream')
        for version in ('1.7', '2.0') for kind in ('text', 'choice', 'checkbox', 'radio')}
    button_limits = []
    for item in form_fills:
        source, filled = work / item['source'], work / item['filled']
        button = item['kind'] in ('checkbox', 'radio')
        assert filled.read_bytes().startswith(source.read_bytes())
        assert_form_value(filled, item['kind'])
        reports[source.name] = inspect(source, args.qpdf)
        reports[filled.name] = inspect(filled, args.qpdf, expected_permission=None if button else True)
        independent = work / ('independent-' + filled.name)
        change_form_value(source, independent, item['kind'])
        reports[independent.name] = inspect(independent, args.qpdf, expected_permission=None if button else True)
        if button:
            assert rejected[source.stem + ':shared-appearance'] is True
            reports[source.name]['buttonRendering'] = rendered_pixels(source, args.pdftoppm,
                [(10, 10, (0, 0, 255)), (30, 30, (255, 255, 255)), (60, 30, (255, 255, 255))])
            for changed in (filled, independent):
                button_structure(source, changed)
                reports[changed.name]['buttonRendering'] = rendered_pixels(changed, args.pdftoppm,
                    [(10, 10, (0, 0, 255)), (30, 30, (0, 0, 255)), (60, 30, (255, 255, 255))])
                button_limits.append({'file': changed.name, 'nativePermitted': True,
                    'pyhankoDocMdpOk': reports[changed.name]['signatures'][0]['docmdp_ok'],
                    'pyhankoModificationLevel': reports[changed.name]['signatures'][0]['modificationLevel'],
                    'valueWidgetStateAppearanceAndPixelsIndependentlyVerified': True})
        else:
            for changed in (filled, independent):
                assert reports[changed.name]['signatures'][0]['modificationLevel'] == 'FORM_FILLING'
        if item['kind'] == 'checkbox' and item['format'] == 'Table' and item['version'] == '2.0':
            shared = work / ('independent-' + source.stem + '-shared.pdf')
            change_shared_appearance(source, shared)
            reports[shared.name] = inspect(shared, args.qpdf, forbidden=True)
            reports[shared.name]['sharedAppearanceRendering'] = rendered_pixels(shared, args.pdftoppm,
                [(10, 10, (255, 0, 0)), (30, 30, (255, 255, 255)), (60, 30, (255, 255, 255))])
    read_only_limits = []
    for permission in (2, 3):
        source = work / f'core-p{permission}-readonly.pdf'
        reports[source.name] = inspect(source, args.qpdf)
        changed = work / f'independent-p{permission}-readonly.pdf'
        change_form_value(source, changed, 'text')
        assert_form_value(changed, 'text', read_only=True)
        # ReadOnly is a core usage constraint, not a FieldMDP lock in pyHanko.
        # Preserve the independent result instead of patching its policy.
        reports[changed.name] = inspect(changed, args.qpdf, expected_permission=None)
        read_only_limits.append({'file': changed.name, 'nativeRefused': True,
            'pyhankoDocMdpOk': reports[changed.name]['signatures'][0]['docmdp_ok'],
            'readOnlyFlagAndChangedValueIndependentlyVerified': True})
    extended_text_results = []
    extended = native['extended_text_fills']
    assert {(r['permission'], r['format'], r['version'], r['kind']) for r in extended} == {
        (p, xref, v, kind) for p in (2, 3) for xref in ('Table', 'Stream')
        for v in ('1.7', '2.0') for kind in ('richtext', 'file-select', 'text-stream', 'richtext-stream')}
    for item in extended:
        source, filled = work / item['source'], work / item['filled']
        assert filled.read_bytes().startswith(source.read_bytes())
        assert_form_value(filled, item['kind'])
        reports[source.name] = inspect(source, args.qpdf)
        reports[filled.name] = inspect(filled, args.qpdf, expected_permission=None)
        independent = work / ('independent-' + filled.name)
        change_form_value(source, independent, item['kind'])
        reports[independent.name] = inspect(independent, args.qpdf, expected_permission=None)
        extended_text_results.append({'kind': item['kind'], 'native': reports[filled.name]['signatures'],
                                     'independent': reports[independent.name]['signatures']})
    template_results = inspect_templates(work, native, args.qpdf, args.pdftoppm, reports)
    radio_off = inspect_radio_optional_off(work, native, args.qpdf, args.pdftoppm, reports)
    annotation_limits = inspect_annotations(work, native, args.qpdf, args.pdftoppm, reports)
    usage_rights = inspect_usage_rights(work, native, args.qpdf, reports)
    report = {'native_rejected': len(rejected), 'native_cases': sorted(rejected),
        'independent_adversarial_updates': 12, 'locked_field': locked_name,
        'native_form_fill_combinations': len(combinations), 'independent_form_fill_combinations': len(combinations),
        'pyhanko_confirmed_form_fill_combinations': sum(item['kind'] in ('text', 'choice') for item in form_fills),
        'read_only_oracle_limits': read_only_limits,
        'button_widget_oracle_limits': button_limits,
        'annotation_oracle_limits': annotation_limits,
        'extended_text_fills': extended_text_results,
        'template_instantiations': template_results,
        'radio_optional_off': radio_off,
        'native_annotation_combinations': len(native['annotations']),
        'usage_rights': usage_rights,
        'hybrid_boundary': 'Native hybrid coverage is separate; unmodified strict pyHanko rejects hybrid signature validation.',
        'trust_roots': [], 'fetching': False, 'diff_policy': 'Unmodified pyHanko default', 'files': reports}
    (work / 'incremental-permissions-report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'native_rejected': len(rejected), 'independent_adversarial_updates': 12,
        'native_form_fill_combinations': len(combinations), 'read_only_oracle_limits': read_only_limits,
        'extended_text_fill_combinations': len(extended),
        'template_instantiation_combinations': len(template_results),
        'radio_optional_off_combinations': len(radio_off),
        'button_widget_oracle_limits': len(button_limits),
        'independent_button_pixel_checks': sum(item.get('buttonRendering', {}).get('pixelChecks', 0) for item in reports.values()),
        'native_annotation_combinations': len(native['annotations']),
        'usage_rights': usage_rights,
        'independent_annotation_pixel_checks': sum(item.get('annotationRendering', {}).get('pixelChecks', 0) for item in reports.values()),
        'independently_checked_files': len(reports), 'math_and_permissions_separated': True,
        'implicit_pki_trust': False}, indent=2))


if __name__ == '__main__':
    main()
