"""Verify native RFC 3161, DSS/VRI and repeated timestamp revisions independently."""

from __future__ import annotations

import argparse
import base64
import hashlib
import io
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

from cryptography import x509
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import padding
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.pdf_utils.crypt.api import AuthStatus, PdfMacStatus
from pyhanko.pdf_utils.crypt.pdfmac import validate_pdf_mac


def verify_timestamps(reader: PdfFileReader, authority: bytes) -> int:
    signatures = [signature for signature in reader.embedded_signatures if signature.sig_object_type == '/DocTimeStamp']
    public = x509.load_der_x509_certificate(authority).public_key()
    for signature in signatures:
        assert signature.sig_object_type == '/DocTimeStamp'
        assert signature.sig_object['/SubFilter'] == '/ETSI.RFC3161'
        token = signature.signed_data['encap_content_info']['content'].parsed
        assert token['policy'].dotted == '1.2.3.4.1'
        assert token['nonce'].native is not None
        assert token['message_imprint']['hash_algorithm']['algorithm'].native == 'sha256'
        assert token['message_imprint']['hashed_message'].native == signature.compute_digest()
        signer = signature.signer_info
        assert signer['digest_algorithm']['algorithm'].native == 'sha256'
        attrs = signer['signed_attrs']
        attributes = {attribute['type'].native: attribute['values'][0] for attribute in attrs}
        assert attributes['content_type'].native == 'tst_info'
        assert attributes['message_digest'].native == hashlib.sha256(token.dump()).digest()
        signing_certificate = attributes['signing_certificate_v2']['certs'][0]
        assert signing_certificate['cert_hash'].native == hashlib.sha256(authority).digest()
        public.verify(signer['signature'].native, b'\x31' + attrs.dump()[1:], padding.PKCS1v15(), hashes.SHA256())
    return len(signatures)


def verify_certification(reader: PdfFileReader, certificate: bytes) -> None:
    signatures = [signature for signature in reader.embedded_signatures if signature.sig_object_type == '/Sig']
    assert len(signatures) == 1
    signature, = signatures
    reference, = signature.sig_object['/Reference']
    reference = reference.get_object()
    assert reference['/TransformMethod'] == '/DocMDP'
    assert reference['/TransformParams']['/P'] == 1
    assert reader.root['/Perms'].raw_get('/DocMDP').reference == signature.sig_field.raw_get('/V').reference
    signer = signature.signer_info
    assert signer['digest_algorithm']['algorithm'].native == 'sha256'
    attrs = signer['signed_attrs']
    attributes = {attribute['type'].native: attribute['values'][0] for attribute in attrs}
    assert attributes['content_type'].native == 'data'
    assert attributes['message_digest'].native == signature.compute_digest()
    x509.load_der_x509_certificate(certificate).public_key().verify(
        signer['signature'].native, b'\x31' + attrs.dump()[1:], padding.PKCS1v15(), hashes.SHA256())


def validate_case(directory: Path, case: dict, authority: bytes, approval_certificate: bytes, qpdf: str | None) -> int:
    data = (directory / case['file']).read_bytes()
    if case['approval']:
        assert data.startswith((directory / case['approval']).read_bytes())
    if case['stage'] != 'first':
        assert data.startswith((directory / case['first']).read_bytes())
    if case['stage'] == 'second':
        assert data.startswith((directory / case['validation']).read_bytes())
    prefixes = sorted(set(match.end() for match in re.finditer(rb'\n%%EOF\n', data)))
    count = 0
    for length in prefixes:
        if case['linearized'] and length < case['baseLength']:
            continue
        reader = PdfFileReader(io.BytesIO(data[:length]), strict=True)
        if case['cipher'] is not None:
            result = reader.decrypt('user')
            assert result.status == AuthStatus.USER and result.mac_status == PdfMacStatus.SUCCESSFUL
            validate_pdf_mac(reader)
        count += verify_timestamps(reader, authority)
        if case['approval']:
            verify_certification(reader, approval_certificate)
    reader = PdfFileReader(io.BytesIO(data), strict=True)
    if case['cipher'] is not None:
        reader.decrypt('user')
    assert len(reader.embedded_signatures) == (2 if case['stage'] == 'second' else 1) + bool(case['approval'])
    if case['stage'] != 'first':
        store = reader.root['/DSS']
        certificate, = store['/Certs']
        assert certificate.get_object().data == authority
        first = next(signature for signature in reader.embedded_signatures if signature.sig_object_type == '/DocTimeStamp')
        # /Contents is exempt from encryption, including in encrypted signature
        # dictionaries; use pyHanko's raw, padded extraction for its VRI key.
        key = '/' + hashlib.sha1(first.pkcs7_content).hexdigest().upper()
        assert key in store['/VRI']
        assert store['/VRI'][key]['/Cert'].raw_get(0).reference == store['/Certs'].raw_get(0).reference
    if qpdf and case['cipher'] in [None, 6]:
        command = [qpdf, '--check', str(directory / case['file'])]
        if case['cipher'] == 6:
            command.insert(1, '--password=user')
        subprocess.run(command, check=True, capture_output=True)
    return count


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default=shutil.which('qpdf'))
    parser.add_argument('--openssl', default=shutil.which('openssl'))
    parser.add_argument('--work-dir', type=Path)
    options = parser.parse_args()
    assert options.openssl, 'Select the OpenSSL executable explicitly.'
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf20-timestamps-') as temporary:
        directory = options.work_dir or Path(temporary)
        directory.mkdir(parents=True, exist_ok=True)
        subprocess.run([options.php, str(root / 'tools/fixtures/pdf20_timestamps.php'), str(directory), options.openssl], cwd=root, check=True)
        manifest = json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))
        authority = base64.b64decode(manifest['authority'])
        approval_certificate = base64.b64decode(manifest['approvalCertificate'])
        count = sum(validate_case(directory, case, authority, approval_certificate, options.qpdf) for case in manifest['cases'])
        print(f"Verified {len(manifest['cases'])} PDF files and {count} actual timestamp bindings across historical revisions; "
              "CMS/ESS/imprints, DSS/VRI padded keys, repeated timestamps, DocMDP P1 certifications, encrypted MACs and unchanged prefixes are valid.")


if __name__ == '__main__':
    main()
