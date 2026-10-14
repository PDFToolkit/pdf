#!/usr/bin/env python3
"""Verify public-key PDF encryption with pyHanko and independent CMS/key derivation."""
from pathlib import Path
from io import BytesIO
import argparse
import hashlib
import json
import subprocess
import tempfile
import zlib
from asn1crypto import cms
from cryptography.hazmat.primitives import serialization, padding
from cryptography.hazmat.primitives.asymmetric import padding as rsa_padding
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.decrepit.ciphers.algorithms import ARC4
from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.pdf_utils.crypt.pubkey import SimpleEnvelopeKeyDecrypter


def run(command):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    if result.returncode:
        raise AssertionError(f'Command failed: {command}\n{result.stdout!r}\n{result.stderr!r}')
    return result.stdout


def decrypt_packet(packet, key):
    envelope = cms.ContentInfo.load(packet)['content']
    recipient = envelope['recipient_infos'][0].chosen
    assert recipient['key_encryption_algorithm']['algorithm'].native == 'rsaes_pkcs1v15'
    envelope_key = key.decrypt(recipient['encrypted_key'].native, rsa_padding.PKCS1v15())
    content = envelope['encrypted_content_info']
    assert content['content_encryption_algorithm']['algorithm'].native == 'aes128_cbc'
    cipher = Cipher(algorithms.AES(envelope_key), modes.CBC(content['content_encryption_algorithm']['parameters'].native))
    decoder = cipher.decryptor()
    padded = decoder.update(content['encrypted_content'].native) + decoder.finalize()
    unpadder = padding.PKCS7(128).unpadder()
    return unpadder.update(padded) + unpadder.finalize()


def check_material(filter_object, key, case, document, encrypt_metadata=True):
    packets = [packet.dump() for packet in filter_object.recipients]
    payloads = [decrypt_packet(packet, key) for packet in packets]
    assert all(len(payload) == (24 if document else 20) for payload in payloads)
    payload = payloads[0]
    assert all(item[:20] == payload[:20] for item in payloads)
    if document:
        order = 'little' if case['permissionByteOrder'] == 'V' else 'big'
        words = [int.from_bytes(item[20:], order) for item in payloads]
        assert words == case['recipientPermissions']
        assert words[0] == case['permissions']
        if case['pdfVersion'] == '2.0':
            assert order == 'big'
            assert len(words) == 2 and words[0] != words[1]
            assert all(word & 0x200 for word in words)
            assert all(word & 0xfffff000 == 0xfffff000 for word in words)
    metadata_suffix = b'' if encrypt_metadata else b'\xff' * 4
    digest_name = 'sha256' if len(filter_object.shared_key) == 32 else 'sha1'
    independent_key = hashlib.new(digest_name, payload[:20] + b''.join(packets) + metadata_suffix).digest()[:len(filter_object.shared_key)]
    assert independent_key == filter_object.shared_key


def independently_decode_named_stream(stream, file_key, method):
    encoded = stream.encoded_data
    # ISO7.4.10: explicit Crypt uses the selected shared key as-is.
    local_key = file_key
    if method == '/V2':
        decoder = Cipher(ARC4(local_key), mode=None).decryptor()
        decoded = decoder.update(encoded) + decoder.finalize()
    else:
        decoder = Cipher(algorithms.AES(local_key), modes.CBC(encoded[:16])).decryptor()
        padded = decoder.update(encoded[16:]) + decoder.finalize()
        unpadder = padding.PKCS7(128).unpadder()
        decoded = unpadder.update(padded) + unpadder.finalize()
    assert stream['/Filter'] == ['/Crypt', '/FlateDecode']
    assert stream['/DecodeParms'][0]['/Name'] == '/AttachmentKey'
    return zlib.decompress(decoded)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--pdf-version', choices=['1.7', '2.0'], default='1.7')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    fixtures = root / 'tests/Fixtures/signing'
    credential = SimpleEnvelopeKeyDecrypter.load(str(fixtures / 'signer-private-key.pem'), str(fixtures / 'signer.der'))
    attachment_credential = SimpleEnvelopeKeyDecrypter.load(str(fixtures / 'recipient-private-key.pem'), str(fixtures / 'recipient.der'))
    key = serialization.load_pem_private_key((fixtures / 'signer-private-key.pem').read_bytes(), None)
    attachment_key = serialization.load_pem_private_key((fixtures / 'recipient-private-key.pem').read_bytes(), None)
    metadata = (b'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:description>Public-key document XML</dc:description></rdf:Description></rdf:RDF></x:xmpmeta>'
                if args.pdf_version == '2.0' else b'<metadata>Public-key document XML</metadata>')
    count = 0
    with tempfile.TemporaryDirectory(prefix='pdf-public-key-') as temporary:
        directory = Path(temporary)
        run([args.php, root / 'tools/fixtures/public_key_encryption.php', directory, '--pdf-version', args.pdf_version])
        cases = json.loads((directory / 'manifest.json').read_text())
        assert len(cases) == (24 if args.pdf_version == '2.0' else 48)
        for case in cases:
            first = (directory / case['file']).read_bytes()
            for filename, incremental in [(case['file'], False), (case['incrementalFile'], True)]:
                pdf = (directory / filename).read_bytes()
                assert pdf.startswith(f'%PDF-{args.pdf_version}\n'.encode())
                assert case['pdfVersion'] == args.pdf_version
                assert not incremental or pdf.startswith(first)
                reader = PdfFileReader(BytesIO(pdf))
                handler = reader.security_handler
                default = handler.get_stream_filter()
                # Authenticate public API filters separately: EFF uses another identity,
                # without invoking an unrelated file MAC policy. Cipher/filter algorithms remain unchanged.
                result = default.authenticate(credential)
                assert result.status.name == 'USER'
                if case['permissionByteOrder'] == 'N':
                    # The public API exposes interpreted flags, not reserved bits in the raw word.
                    # Full 32-bit CMS words are checked independently by check_material below.
                    mask = sum(flag.value for flag in result.permission_flags.__class__)
                    assert result.permission_flags.value == case['permissions'] & mask
                else:
                    # Deliberately retained source-literal encoding differs from deployed
                    # big-endian permission interpretation; do not claim semantic interoperability.
                    assert result.permission_flags.value != case['permissions']
                check_material(default, key, case, document=True, encrypt_metadata=case['metadata'])
                assert reader.trailer['/Info']['/Title'] == 'Public-key private title'
                expected_content = b'% Updated public-key content\n' if incremental else b'% Private public-key content\n'
                assert reader.root['/Pages']['/Kids'][0]['/Contents'].data == expected_content
                assert reader.root['/Metadata'].data == metadata
                if args.pdf_version == '2.0':
                    assert '/Extensions' not in reader.root
                    assert case['permissionByteOrder'] == 'N'
                if case['attachment']:
                    attachment_filter = handler.get_stream_filter('/AttachmentKey')
                    assert attachment_filter.authenticate(attachment_credential).status.name == 'USER'
                    check_material(attachment_filter, attachment_key, case, document=False)
                    assert attachment_filter.shared_key != default.shared_key
                    method = str(reader.trailer['/Encrypt']['/CF']['/AttachmentKey']['/CFM'])
                    reference = generic.Reference(40, 0, pdf=reader)
                    raw_stream = reader.get_object(reference, never_decrypt=True)
                    assert independently_decode_named_stream(raw_stream, attachment_filter.shared_key, method) == bytes(range(256))
                    # A separate reader avoids caching the never-decrypted raw stream.
                    fresh_reader = PdfFileReader(BytesIO(pdf))
                    fresh_reader.security_handler.get_stream_filter().authenticate(credential)
                    fresh_reader.security_handler.get_stream_filter('/AttachmentKey').authenticate(attachment_credential)
                    assert fresh_reader.get_object(generic.Reference(40, 0, pdf=fresh_reader)).data == bytes(range(256))
                    assert reader.get_object(generic.Reference(41, 0, pdf=reader)).data == b'Explicit public-key clear stream'
                count += 1
    assert count == (48 if args.pdf_version == '2.0' else 96)
    print(f'Public-key security PDF {args.pdf_version}: {count} initial/incremental files, s3/s4/s5, RC4/AESV2/AESV3, independent CMS/keys/named-stream verification and unmodified pyHanko default-filter decryption; permission byte-order interpretations checked separately.')


if __name__ == '__main__':
    main()
