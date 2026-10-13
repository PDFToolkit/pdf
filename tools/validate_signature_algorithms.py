#!/usr/bin/env python3
"""Verify Table257's native RSA/DSA algorithms and minimum PDF versions independently."""

from pathlib import Path
import argparse
import hashlib
import subprocess
import tempfile

from pypdf import PdfReader
from asn1crypto import cms, core
from cryptography import x509
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import rsa, dsa, padding


def run(command):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    if result.returncode:
        raise AssertionError(f'Validation command failed: {command}\n{result.stdout!r}\n{result.stderr!r}')
    return result.stdout


def verify(path, root):
    pdf = path.read_bytes()
    field = PdfReader(path).trailer['/Root']['/AcroForm']['/Fields'][0].get_object()
    signature = field['/V']
    zero, before, after, length = map(int, signature['/ByteRange'])
    assert zero == 0 and after + length == len(pdf)
    covered = pdf[:before] + pdf[after:after + length]
    contents = bytes(signature['/Contents'])
    certificate = x509.load_der_x509_certificate(
        (root / 'tests/Fixtures/signing' / (path.stem.rsplit('-', 1)[0] + '.der')).read_bytes()
    )
    key = certificate.public_key()
    if str(signature['/SubFilter']) == '/adbe.x509.rsa_sha1':
        value = core.OctetString.load(contents)
        key.verify(value.native, covered, padding.PKCS1v15(), hashes.SHA1())
        assert pdf.startswith(b'%PDF-1.3')
    else:
        content_info = cms.ContentInfo.load(contents)
        signed_data = content_info['content']
        signer = signed_data['signer_infos'][0]
        assert signer['digest_algorithm']['algorithm'].native == 'sha256'
        payload = hashlib.sha1(covered).digest() if str(signature['/SubFilter']) == '/adbe.pkcs7.sha1' else covered
        encapsulated = signed_data['encap_content_info']['content'].native
        assert encapsulated == (payload if str(signature['/SubFilter']) == '/adbe.pkcs7.sha1' else None)
        attrs = signer['signed_attrs']
        values = {item['type'].native: item['values'][0].native for item in attrs}
        assert values['message_digest'] == hashlib.sha256(payload).digest()
        assert values['message_digest'] != hashlib.sha256(payload + b'tampered').digest()
        signed_bytes = b'\x31' + attrs.dump()[1:]
        if isinstance(key, rsa.RSAPublicKey):
            key.verify(signer['signature'].native, signed_bytes, padding.PKCS1v15(), hashes.SHA256())
        else:
            assert isinstance(key, dsa.DSAPublicKey)
            key.verify(signer['signature'].native, signed_bytes, hashes.SHA256())
        assert pdf.startswith(b'%PDF-1.6')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-signature-algorithms-') as temporary:
        directory = Path(temporary)
        run([args.php, root / 'tools/fixtures/signature_algorithm_boundaries.php', directory])
        paths = sorted(directory.glob('*.pdf'))
        assert len(paths) == 4
        for path in paths:
            run([args.qpdf, '--check', path])
            verify(path, root)
    print('Native signature algorithms: 4 PDFs, RSA1024/SHA1 and RSA/DSA CMS SHA256, exact ByteRange and minimum PDF versions independently verified.')


if __name__ == '__main__':
    main()
