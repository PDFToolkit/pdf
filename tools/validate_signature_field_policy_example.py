#!/usr/bin/env python3
"""Verify the user-facing signature field policy example and its actual CMS independently."""

from pathlib import Path
import argparse
import tempfile

from pypdf import PdfReader

from validate_signature_field_policies import run, signature_proof
from validate_variable_text_appearances import verify_variable_text_appearance


def verify(path, root):
    reader = PdfReader(path)
    assert len(reader.pages) == 1
    catalog = reader.trailer['/Root']
    fields = reader.get_fields()
    assert set(fields) == {'Reference', 'Approval'}
    assert fields['Reference']['/FT'] == '/Tx'
    assert fields['Reference']['/V'] == 'DOSSIER-2026-010'
    assert fields['Approval']['/FT'] == '/Sig'
    references = {str(item.get_object()['/T']): item for item in catalog['/AcroForm']['/Fields']}
    approval = references['Approval'].get_object()
    signature = approval['/V']
    assert signature['/Filter'] == '/Adobe.PPKLite'
    assert signature['/SubFilter'] == '/adbe.pkcs7.detached'
    assert signature['/Reason'] == 'Étudié et approuvé'
    seed = approval['/SV']
    assert seed['/Ff'] == 75
    assert seed['/Filter'] == signature['/Filter']
    assert seed['/SubFilter'] == [signature['/SubFilter']]
    assert seed['/DigestMethod'] == ['/SHA256']
    assert seed['/Reasons'] == [signature['/Reason']]
    assert '/Perms' not in catalog
    lock = approval['/Lock']
    transform = signature['/Reference'][0]
    assert len(signature['/Reference']) == 1
    assert transform['/TransformMethod'] == '/FieldMDP'
    assert transform.raw_get('/Data') == reader.trailer.raw_get('/Root')
    assert transform['/TransformParams']['/Action'] == lock['/Action'] == '/Include'
    assert transform['/TransformParams']['/Fields'] == lock['/Fields'] == ['Reference']
    assert transform['/TransformParams']['/V'] == '/1.2'
    page = reader.pages[0]
    assert len(page['/Annots']) == 2
    assert 'Signer un champ sous conditions' in page.extract_text()
    for item in page['/Annots']:
        widget = item.get_object()
        assert widget['/Subtype'] == '/Widget'
        assert widget.raw_get('/P') == page.indirect_reference
        field = widget['/Parent']
        assert widget.raw_get('/Parent') == references[str(field['/T'])]
        assert item in field['/Kids']
        appearance = widget['/AP']['/N']
        assert appearance['/Subtype'] == '/Form'
        text = b'DOSSIER-2026-010' if str(field['/T']) == 'Reference' else b'Signature mathematiquement verifiee'
        assert text in appearance.get_data()
        if str(field['/T']) == 'Reference':
            verify_variable_text_appearance(
                appearance, reader, [str(field['/V'])],
                [['DOSSIER-2026-011'], ['DOSSIER-2026-012']], 'Reference',
            )
    certificate = (root / 'tests/Fixtures/signing/signer.der').read_bytes()
    end, digest = signature_proof(path.read_bytes(), signature, certificate)
    assert end == path.stat().st_size and digest == 'SHA256'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--pdf', type=Path, help='Inspect an already generated example instead of generating it.')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-signature-field-policy-example-') as temporary:
        path = args.pdf or Path(temporary) / 'signature-field-policy.pdf'
        if args.pdf is None:
            run([args.php, root / 'examples/signature-field-policy.php', path])
        run([args.qpdf, '--check', path])
        verify(path, root)
    print('Signature field policy example: 1 page, 1 signature field, 1 selected text field, mandatory emitted seed, copied FieldMDP and widgets, actual CMS RSA/SHA256 mathematically verified. Reference AP independently parsed: complete Tx text, balanced q/Q and clipping, outside frame, repeated source-prescribed replacement and no-Tx append regression verified.')


if __name__ == '__main__':
    main()
