"""Check encrypted UR3 native revisions with independent decryption and detached CMS math.

These checks establish bytes, authentication and declared permissions, not PKI
trust, vendor licensing authority or a pyHanko usage-rights policy approval.
"""
from __future__ import annotations

import argparse
import asyncio
import hashlib
import io
import json
import logging
from pathlib import Path
import subprocess

from asn1crypto import cms
from cryptography.hazmat.primitives import serialization
from pypdf import PdfReader
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.pdf_utils.crypt.pubkey import SimpleEnvelopeKeyDecrypter
from pyhanko.sign.validation import async_validate_detached_cms
from pyhanko_certvalidator import ValidationContext
from validate_public_key_security import decrypt_packet


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--work-dir', type=Path, required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    args.work_dir.mkdir(parents=True, exist_ok=True)
    subprocess.run([args.php, str(root / 'tools/fixtures/encrypted-usage-rights.php'), str(args.work_dir)], check=True, cwd=root)
    manifest = json.loads((args.work_dir / 'manifest.json').read_text())
    assert len(manifest['cases']) == 78 and manifest['refusals'] == 156
    fixture = root / 'tests/Fixtures/signing'
    identity = SimpleEnvelopeKeyDecrypter.load(str(fixture / 'signer-private-key.pem'), str(fixture / 'signer.der'))
    private_key = serialization.load_pem_private_key((fixture / 'signer-private-key.pem').read_bytes(), None)
    context = ValidationContext(trust_roots=[], other_certs=[], allow_fetching=False, revocation_mode='soft-fail')
    logging.getLogger('pyhanko').setLevel(logging.CRITICAL)
    reports = []
    qpdf_count = tampered = 0
    for case in manifest['cases']:
        source = (args.work_dir / (case['stem'] + '.pdf')).read_bytes()
        public_key = case['kind'].startswith('s')
        for filled in (False, True):
            path = args.work_dir / (case['stem'] + ('-filled' if filled else '') + '.pdf')
            raw = path.read_bytes()
            assert raw.startswith(source)
            reader = PdfFileReader(io.BytesIO(raw), strict=True)
            handler = reader.security_handler
            if public_key:
                default = handler.get_stream_filter()
                result = default.authenticate(identity)
                assert result.status.name == 'USER'
                packets = [item.dump() for item in default.recipients]
                payload = decrypt_packet(packets[0], private_key)
                assert len(payload) == 24
                # ISO1 literal byte order differs from pyHanko's deployed convention.
                word = int.from_bytes(payload[20:], 'big' if case['version'] == '2.0' else 'little')
                assert word == case['permissionWord']
                key = hashlib.new('sha256' if len(default.shared_key) == 32 else 'sha1', payload[:20] + b''.join(packets)).digest()[:len(default.shared_key)]
                assert key == default.shared_key
                assert bool(word & 2) == case['owner']
            else:
                if case['kind'] == 'r5':
                    independent = PdfReader(io.BytesIO(raw), strict=True)
                    assert independent.decrypt('owner' if case['owner'] else 'user') == (2 if case['owner'] else 1)
                else:
                    result = reader.decrypt('owner' if case['owner'] else 'user')
                    assert result.status.name == ('OWNER' if case['owner'] else 'USER')
                assert int(reader.trailer['/Encrypt']['/P']) & 0xffffffff == case['permissionWord']
                if case['kind'] != 'r7':
                    subprocess.run([args.qpdf, '--password=user', '--check', str(path)], check=True, capture_output=True)
                    qpdf_count += 1
            document = independent.trailer['/Root'] if case['kind'] == 'r5' else reader.root
            assert document['/Pages']['/Count'] == 1
            field = document['/AcroForm']['/Fields'][0].get_object()
            assert str(field['/V']) == ('changed' if filled else 'original')
            # Contents is physically exempt from encryption. Read before the
            # generic decrypted-object cache: UR signatures are outside the field tree.
            physical = PdfFileReader(io.BytesIO(raw), strict=True)
            root_object = physical.get_object(physical.trailer_view.raw_get('/Root').reference, never_decrypt=True)
            reference = root_object['/Perms'].raw_get('/UR3').reference
            signature = physical.get_object(reference, never_decrypt=True)
            offsets = [int(value) for value in signature['/ByteRange']]
            assert len(offsets) == 4 and offsets[0] == 0
            assert offsets[2] + offsets[3] == len(source)
            contents = bytes.fromhex(raw[offsets[1] + 1:offsets[2] - 1].decode('ascii'))
            assert contents == signature.raw_get('/Contents').original_bytes
            signed_data = cms.ContentInfo.load(contents)['content']
            payload = raw[:offsets[1]] + raw[offsets[2]:offsets[2] + offsets[3]]
            status = asyncio.run(async_validate_detached_cms(payload, signed_data, signer_validation_context=context))
            assert status.intact and status.valid and not status.trusted
            assert hashlib.sha256(status.signing_cert.dump()).hexdigest() == case['signerSha256']
            if not filled:
                bad = asyncio.run(async_validate_detached_cms(bytes([payload[0] ^ 1]) + payload[1:], signed_data, signer_validation_context=context))
                assert not bad.intact
                tampered += 1
            reports.append({'file': path.name, 'sha256': hashlib.sha256(raw).hexdigest(), 'decrypted': True, 'decryptionReader': 'pypdf' if case['kind'] == 'r5' else 'pyHanko', 'cmsIntact': True, 'cmsValid': True, 'trusted': False, 'filled': filled})
    report = {'sources': len(manifest['cases']), 'pdfs': len(reports), 'qpdf': qpdf_count, 'tamperedCmsRefused': tampered, 'nativeCredentialRefusals': manifest['refusals'], 'results': reports,
              'boundary': 'Independent decryption, CMS mathematics and declared permission words; no PKI trust, vendor authority or independent UR/DocMDP policy approval.'}
    (args.work_dir / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({key: value for key, value in report.items() if key != 'results'}))


if __name__ == '__main__':
    main()
