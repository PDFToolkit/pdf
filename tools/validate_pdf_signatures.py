#!/usr/bin/env python3
"""Validate PDF byte ranges and native CMS/PKCS#1 output using independent readers and crypto."""
from pathlib import Path
from io import BytesIO
import argparse
import hashlib
import json
import subprocess
import tempfile
from asn1crypto import cms, core
from cryptography import x509
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import padding
from cryptography.exceptions import InvalidSignature
from pypdf import PdfReader


def run(command):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    if result.returncode:
        raise AssertionError(f'Validation command failed: {command}\n{result.stdout!r}\n{result.stderr!r}')
    return result.stdout


def verify_signature(signature, pdf, expected_certificate):
    ranges = [int(value) for value in signature['/ByteRange']]
    assert len(ranges) == 4 and ranges[0] == 0
    _, before, after, length = ranges
    assert 0 < before < after <= len(pdf) and after + length <= len(pdf)
    assert pdf[before:before + 1] == b'<' and pdf[after - 1:after] == b'>'
    assert pdf[after + length - 6:after + length] == b'%%EOF\n'
    encoded = pdf[before + 1:after - 1]
    contents = bytes.fromhex(encoded.decode('ascii'))
    assert contents == bytes(signature['/Contents'])
    covered = pdf[:before] + pdf[after:after + length]
    fmt = str(signature['/SubFilter'])
    assert all(not hasattr(value, 'idnum') for value in signature.values())
    if fmt == '/adbe.x509.rsa_sha1':
        value = core.OctetString.load(contents)
        assert contents[len(value.dump()):] == b'\0' * (len(contents) - len(value.dump()))
        certificate_value = signature['/Cert']
        der = bytes(certificate_value[0]) if isinstance(certificate_value, list) else bytes(certificate_value)
        assert der == expected_certificate
        certificate = x509.load_der_x509_certificate(der)
        certificate.public_key().verify(value.native, covered, padding.PKCS1v15(), hashes.SHA1())
        try:
            certificate.public_key().verify(value.native, covered + b'tampered', padding.PKCS1v15(), hashes.SHA1())
            raise AssertionError('An altered byte range retained a valid PKCS#1 signature.')
        except InvalidSignature:
            pass
    else:
        assert '/Cert' not in signature
        content_info = cms.ContentInfo.load(contents)
        assert content_info['content_type'].native == 'signed_data'
        der = content_info.dump()
        assert contents[len(der):] == b'\0' * (len(contents) - len(der))
        signed_data = content_info['content']
        certificate = next(item.chosen for item in signed_data['certificates'] if item.chosen.dump() == expected_certificate)
        assert len(signed_data['signer_infos']) == 1
        signer = signed_data['signer_infos'][0]
        assert signer['sid'].chosen['serial_number'].native == certificate.serial_number
        if fmt == '/adbe.pkcs7.sha1':
            payload = hashlib.sha1(covered).digest()
            assert signed_data['encap_content_info']['content'].native == payload
        else:
            assert fmt == '/adbe.pkcs7.detached'
            payload = covered
            assert signed_data['encap_content_info']['content'].native is None
        digest_name = signer['digest_algorithm']['algorithm'].native
        attrs = signer['signed_attrs']
        if attrs.native is not None:
            attributes = {item['type'].native: item['values'][0].native for item in attrs}
            assert attributes['content_type'] == 'data'
            assert attributes['message_digest'] == hashlib.new(digest_name, payload).digest()
            assert attributes['message_digest'] != hashlib.new(digest_name, payload + b'tampered').digest()
            signed_bytes = b'\x31' + attrs.dump()[1:]
        else:
            signed_bytes = payload
        algorithms = {'sha1': hashes.SHA1, 'sha256': hashes.SHA256, 'sha384': hashes.SHA384, 'sha512': hashes.SHA512}
        cert = x509.load_der_x509_certificate(certificate.dump())
        cert.public_key().verify(signer['signature'].native, signed_bytes, padding.PKCS1v15(), algorithms[digest_name]())
    return after + length


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    certificate = (root / 'tests/Fixtures/signing/signer.der').read_bytes()
    verified = 0
    with tempfile.TemporaryDirectory(prefix='pdf-native-signatures-') as temporary:
        directory = Path(temporary)
        run([args.php, root / 'tools/fixtures/pdf_signatures.php', directory])
        cases = json.loads((directory / 'manifest.json').read_text())
        for case in cases:
            first = (directory / case['file']).read_bytes()
            for filename, expected_count in [(case['file'], 1), (case['secondFile'], 2)]:
                path = directory / filename
                password = [] if case['password'] is None else ['--password=' + case['password']]
                run([args.qpdf, *password, '--check', path])
                pdf = path.read_bytes()
                assert expected_count == 1 or pdf.startswith(first)
                view = pdf
                if case['password'] is not None:
                    # pypdf does not exempt signature Contents during decryption.
                    # qpdf creates an independent clear dictionary view preserving
                    # ByteRange and Contents; verification still uses ORIGINAL bytes.
                    plain = directory / ('decrypted-' + filename)
                    run([args.qpdf, *password, '--decrypt', path, plain])
                    view = plain.read_bytes()
                reader = PdfReader(BytesIO(view))
                fields = reader.trailer['/Root']['/AcroForm']['/Fields']
                assert len(fields) == expected_count and len(reader.pages) == 1
                lengths = []
                for field in fields:
                    signature = field.get_object()['/V']
                    lengths.append(verify_signature(signature, pdf, certificate))
                    verified += 1
                assert lengths[0] == len(first)
                assert lengths[-1] == len(pdf)
    print(f'PDF signatures: {verified} signatures in {len(cases) * 2} files, all three ISO formats, all xref formats, plaintext and R2/R3/R4/R5 encryption, incremental preservation, DER/CMS digests and RSA integrity independently verified.')


if __name__ == '__main__':
    main()
