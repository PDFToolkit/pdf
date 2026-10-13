#!/usr/bin/env python3
"""Inspect written seed/MDP/appearance policies and verify actual CMS/PKCS#1 signatures independently."""

from pathlib import Path
import argparse
import hashlib
import subprocess
import tempfile

from pypdf import PdfReader
from asn1crypto import cms, core
from cryptography import x509
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import dsa, padding, rsa


def run(command):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    if result.returncode:
        raise AssertionError(f'Validation command failed: {command}\n{result.stdout!r}\n{result.stderr!r}')
    return result.stdout


def signature_proof(pdf, signature, certificate_der):
    start, before, after, length = map(int, signature['/ByteRange'])
    assert start == 0 and 0 < before < after and after + length <= len(pdf)
    assert pdf[before:before + 1] == b'<' and pdf[after - 1:after] == b'>'
    covered = pdf[:before] + pdf[after:after + length]
    certificate = x509.load_der_x509_certificate(certificate_der)
    key = certificate.public_key()
    # Public test credential: only verify signatures mathematically, without declaring PKI trust.
    if str(signature['/SubFilter']) == '/adbe.x509.rsa_sha1':
        certs = signature['/Cert']
        certs = certs if isinstance(certs, list) else [certs]
        assert bytes(certs[0]) == certificate_der
        value = core.OctetString.load(bytes(signature['/Contents']))
        key.verify(value.native, covered, padding.PKCS1v15(), hashes.SHA1())
        return after + length, 'SHA1'
    container = cms.ContentInfo.load(bytes(signature['/Contents']))
    signed = container['content']
    assert container['content_type'].native == 'signed_data'
    assert len(signed['signer_infos']) == 1
    assert certificate_der in [item.chosen.dump() for item in signed['certificates']]
    info = signed['signer_infos'][0]
    assert info['digest_algorithm']['algorithm'].native == 'sha256'
    payload = hashlib.sha1(covered).digest() if str(signature['/SubFilter']) == '/adbe.pkcs7.sha1' else covered
    assert signed['encap_content_info']['content'].native == (payload if str(signature['/SubFilter']) == '/adbe.pkcs7.sha1' else None)
    attributes = info['signed_attrs']
    values = {item['type'].native: item['values'][0].native for item in attributes}
    assert values['message_digest'] == hashlib.sha256(payload).digest()
    signed_bytes = b'\x31' + attributes.dump()[1:]
    if isinstance(key, rsa.RSAPublicKey):
        key.verify(info['signature'].native, signed_bytes, padding.PKCS1v15(), hashes.SHA256())
    else:
        assert isinstance(key, dsa.DSAPublicKey)
        key.verify(info['signature'].native, signed_bytes, hashes.SHA256())
    return after + length, 'SHA256'


def verify(path, certificate_der):
    pdf = path.read_bytes()
    reader = PdfReader(path)
    root = reader.trailer['/Root']
    fields = {str(ref.get_object()['/T']): ref.get_object() for ref in root['/AcroForm']['/Fields']}
    proofs = {}
    for name, field in fields.items():
        if '/V' not in field:
            continue
        signature = field['/V']
        end, digest = signature_proof(pdf, signature, certificate_der)
        proofs[name] = end
        seed = field.get('/SV')
        if seed is not None:
            seed = seed.get_object()
            flags = int(seed.get('/Ff', 0))
            if flags & 1:
                assert signature['/Filter'] == seed['/Filter']
            if flags & 2:
                supported = ['/adbe.pkcs7.detached', '/adbe.pkcs7.sha1', '/adbe.x509.rsa_sha1']
                preferred = next(str(value) for value in seed['/SubFilter'] if str(value) in supported)
                assert str(signature['/SubFilter']) == preferred
            if flags & 8:
                assert signature['/Reason'] == seed['/Reasons'][0] == 'Étudié et approuvé'
            if flags & 64:
                assert '/' + digest in seed['/DigestMethod']
            cert = seed.get('/Cert')
            if cert is not None:
                cert = cert.get_object()
                assert bytes(cert['/Subject'][0]) == certificate_der == bytes(cert['/Issuer'][0])
                actual = x509.load_der_x509_certificate(certificate_der)
                assert cert['/SubjectDN'][0]['/CN'] == actual.subject.get_attributes_for_oid(x509.NameOID.COMMON_NAME)[0].value
                usage = actual.extensions.get_extension_for_class(x509.KeyUsage).value
                assert usage.digital_signature and usage.content_commitment and usage.key_encipherment
    assert max(proofs.values()) == len(pdf)
    if path.name == 'certification-and-approval.pdf':
        assert set(proofs) == {'author', 'approval'}
        assert proofs['author'] < proofs['approval']
        assert root['/Perms'].raw_get('/DocMDP') == fields['author'].raw_get('/V')
        references = fields['author']['/V']['/Reference']
        methods = {str(ref['/TransformMethod']): ref for ref in references}
        assert methods['/DocMDP']['/TransformParams']['/P'] == 2
        mdp = methods['/FieldMDP']
        assert mdp.raw_get('/Data') == reader.trailer.raw_get('/Root')
        assert mdp['/TransformParams']['/Action'] == fields['author']['/Lock']['/Action'] == '/Include'
        assert mdp['/TransformParams']['/Fields'] == fields['author']['/Lock']['/Fields'] == ['protected']
        assert fields['author']['/SV']['/MDP']['/P'] == 2
        assert '/V' not in fields['protected']
    elif path.name == 'adobe-lock-and-appearance.pdf':
        field = fields['approval']
        assert root['/Extensions']['/ADBE']['/BaseVersion'] == '/1.7'
        assert root['/Extensions']['/ADBE']['/ExtensionLevel'] >= 3
        assert field['/Lock']['/P'] == 1 and field['/SV']['/LockDocument'] == '/true'
        assert field['/SV']['/AppearanceFilter'] == 'Explicit supplied appearance'
        widget = field['/Kids'][0].get_object()
        assert widget.raw_get('/Parent') == root['/AcroForm']['/Fields'][0]
        assert widget['/AP']['/N']['/Subtype'] == '/Form'
        assert widget['/P']['/Annots'][0] == field['/Kids'][0]
    elif path.name == 'certification-detection.pdf':
        assert '/Perms' not in root
        references = fields['author']['/V']['/Reference']
        assert references[0]['/TransformMethod'] == '/DocMDP'
        assert references[0]['/TransformParams']['/P'] == 2
    elif path.name == 'advisory-fallback.pdf':
        assert fields['approval']['/SV']['/Ff'] == 0
        assert fields['approval']['/V']['/SubFilter'] == '/adbe.x509.rsa_sha1'
    elif path.name == 'dsa-reasons.pdf':
        assert '/DigestMethod' not in fields['approval']['/SV']
        assert fields['approval']['/V']['/Reason'] == 'Étudié et approuvé'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-signature-field-policies-') as temporary:
        directory = Path(temporary)
        run([args.php, root / 'tools/fixtures/signature_field_policies.php', directory])
        paths = sorted(directory.glob('*.pdf'))
        assert len(paths) == 8
        for path in paths:
            run([args.qpdf, '--check', path])
            name = 'dsa-1024' if path.name == 'dsa-reasons.pdf' else 'signer'
            certificate = (root / 'tests/Fixtures/signing' / (name + '.der')).read_bytes()
            verify(path, certificate)
    print('Signature field policies: 8 PDFs, 9 actual mathematical RSA/DSA signatures, mandatory/advisory seeds, certification with/without Perms, incremental approval, copied FieldMDP, Adobe lock and attached appearance independently verified.')


if __name__ == '__main__':
    main()
