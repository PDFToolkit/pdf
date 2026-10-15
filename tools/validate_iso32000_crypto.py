"""Independent TS 32001-32004 cryptographic and actual PDF integration checks."""

from __future__ import annotations

import argparse
import base64
import hashlib
import io
import json
import logging
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

from asn1crypto import cms, keys, pem, x509 as asn1_x509
from cryptography import x509
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ec, padding, rsa
from pyhanko.pdf_utils.crypt.api import AuthStatus, PdfMacStatus
from pyhanko.pdf_utils.crypt.pdfmac import PdfMacTokenHandler, validate_pdf_mac
from pyhanko.pdf_utils.crypt.pubkey import SimpleEnvelopeKeyDecrypter
from pyhanko.pdf_utils.reader import PdfFileReader

logging.getLogger("pyhanko.pdf_utils.reader").setLevel(logging.CRITICAL)


def digest(name: str, value: bytes) -> bytes:
    name = name.replace("-", "_")
    return hashlib.shake_256(value).digest(64) if name == "shake256" else hashlib.new(name, value).digest()


def validate_token(token: bytes, key: bytes, salt: bytes, document: bytes, signature: bytes | None = None) -> None:
    container = cms.ContentInfo.load(token, strict=True)
    assert container["content_type"].native == "authenticated_data"
    data = container["content"]
    handler = PdfMacTokenHandler.for_validation(file_encryption_key=key, kdf_salt=salt, auth_data=data)
    name = data["digest_algorithm"]["algorithm"].native
    handler.validate_pdfmac_token_cms(
        auth_data=data,
        document_digest=digest(name, document),
        signature_digest=None if signature is None else digest(name, signature),
    )


def validate_signature(record: dict) -> None:
    data = base64.b64decode(record["bytes"])
    container = cms.ContentInfo.load(base64.b64decode(record["cms"]), strict=True)
    assert container["content_type"].native == "signed_data"
    signed = container["content"]
    assert signed["encap_content_info"]["content"].native is None
    signer, = signed["signer_infos"]
    name = signer["digest_algorithm"]["algorithm"].native
    assert name == record["digest"].replace("-", "_")
    assert signed["digest_algorithms"][0]["algorithm"].native == name
    attributes = signer["signed_attrs"]
    claims = {attribute["type"].native: attribute["values"][0].native for attribute in attributes}
    assert len(claims) == len(attributes)
    assert claims["content_type"] == "data"
    assert claims["message_digest"] == digest(name, data)
    signed_bytes = b"\x31" + attributes.dump()[1:]
    certificate = x509.load_der_x509_certificate(base64.b64decode(record["certificate"]))
    public = certificate.public_key()
    signature = signer["signature"].native
    if isinstance(public, (rsa.RSAPublicKey, ec.EllipticCurvePublicKey)):
        algorithm = {"sha256": hashes.SHA256, "sha384": hashes.SHA384, "sha512": hashes.SHA512,
                     "sha3_256": hashes.SHA3_256, "sha3_384": hashes.SHA3_384, "sha3_512": hashes.SHA3_512}[name]()
        if isinstance(public, rsa.RSAPublicKey):
            public.verify(signature, signed_bytes, padding.PKCS1v15(), algorithm)
        else:
            public.verify(signature, signed_bytes, ec.ECDSA(algorithm))
    else:
        public.verify(signature, signed_bytes)
    macs = [attribute for attribute in signer["unsigned_attrs"] if attribute["type"].dotted == "1.0.32004.1.2"]
    if not record.get('mac', True):
        assert not macs
        return
    mac, = macs
    token, = mac["values"]
    validate_token(token.dump(), b"K" * 32, b"S" * 32, data, signature)


def validate_pdf(record: dict, directory: Path, qpdf: str | None, credential: SimpleEnvelopeKeyDecrypter) -> tuple[int, int]:
    source = directory / record["file"]
    data = source.read_bytes()
    prefixes = sorted(set(match.end() for match in re.finditer(rb"\n%%EOF\n", data)))
    assert prefixes and prefixes[-1] == len(data)
    checked = 0
    for length in prefixes:
        if record["linearized"] and length < record["baseLength"]:
            continue  # The early first-page xref/EOF belongs to the one /L-sized linearized revision.
        reader = PdfFileReader(io.BytesIO(data[:length]), strict=True)
        result = reader.decrypt_pubkey(credential) if record['publicKey'] else reader.decrypt("user")
        assert result.status == AuthStatus.USER
        assert result.mac_status == PdfMacStatus.SUCCESSFUL, (source.name, length, result)
        validate_pdf_mac(reader)
        assert str(reader.trailer_view["/Info"]["/Title"]) == "PDFToolkit integrity fixture"
        assert reader.root["/Pages"]["/Count"] == 1
        owner = PdfFileReader(io.BytesIO(data[:length]), strict=True)
        owner_result = owner.decrypt_pubkey(credential) if record['publicKey'] else owner.decrypt("owner")
        assert owner_result.status == (AuthStatus.USER if record['publicKey'] else AuthStatus.OWNER)
        assert owner_result.mac_status == PdfMacStatus.SUCCESSFUL
        checked += 1
    # Mutating a syntactically irrelevant byte must invalidate the complete file MAC.
    changed = bytearray(data)
    index = changed.index(b"endobj") - 1
    assert changed[index] == 10
    changed[index] = 32
    invalid = PdfFileReader(io.BytesIO(changed), strict=True)
    invalid_result = invalid.decrypt_pubkey(credential) if record['publicKey'] else invalid.decrypt("user")
    assert invalid_result.mac_status == PdfMacStatus.FAILED, (source.name, invalid_result)
    if qpdf and record["revision"] == 6 and not record['publicKey']:
        subprocess.run([qpdf, "--password=user", "--check", str(source)], check=True, capture_output=True)
    return checked, 1


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="php")
    parser.add_argument("--openssl", default=shutil.which("openssl"))
    parser.add_argument("--qpdf", default=shutil.which("qpdf"))
    parser.add_argument("--work-dir", type=Path)
    options = parser.parse_args()
    assert options.openssl, "Supply an explicit OpenSSL executable for Ed25519/Ed448 validation."
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix="pdf-iso32000-crypto-") as temporary:
        directory = options.work_dir or Path(temporary)
        directory.mkdir(parents=True, exist_ok=True)
        subprocess.run([options.php, str(root / "tools/iso32000_crypto_fixture.php"), str(directory), str(options.openssl)], check=True, cwd=root)
        manifest = json.loads((directory / "manifest.json").read_text(encoding="utf-8"))
        recipient = manifest['recipient']
        credential = SimpleEnvelopeKeyDecrypter(cert=asn1_x509.Certificate.load(base64.b64decode(recipient['certificate'])), private_key=keys.PrivateKeyInfo.load(pem.unarmor(recipient['private_key'].encode())[2]))
        prefixes = tampered = 0
        for record in manifest["pdfs"]:
            revisions, attacks = validate_pdf(record, directory, options.qpdf, credential)
            prefixes += revisions
            tampered += attacks
        for signature in manifest["signatures"]:
            validate_signature(signature)
        for record in manifest['seed_signatures']:
            source = directory / record['file']
            if options.qpdf:
                subprocess.run([options.qpdf, '--check', str(source)], check=True, capture_output=True)
            from pypdf import PdfReader
            reader = PdfReader(source, strict=True)
            field = reader.trailer['/Root']['/AcroForm']['/Fields'][0].get_object()
            seed = field['/SV']
            assert seed['/V'] == 3 and type(seed['/V']).__name__ == 'NumberObject'
            assert seed['/DigestMethod'] == ['/SHA256']
            signature = field['/V']
            ranges = signature['/ByteRange']
            raw = source.read_bytes()
            assert ranges[0] == 0 and ranges[2] + ranges[3] == len(raw)
            covered = raw[:ranges[1]] + raw[ranges[2]:]
            assert covered == base64.b64decode(record['bytes'])
            physical = signature['/Contents'].original_bytes
            assert physical.startswith(base64.b64decode(record['cms']))
            assert not any(physical[len(base64.b64decode(record['cms'])):])
            validate_signature(record)
        for token in manifest["tokens"]:
            validate_token(base64.b64decode(token["cms"]), b"K" * 32, b"S" * 32, b"document")
            validate_token(base64.b64decode(token["signedCms"]), b"K" * 32, b"S" * 32, b"document", b"signature")
        print(f"Verified {len(manifest['pdfs'])} real password/public-key PDFs, {prefixes} historical/fallback revisions with both password access levels or recipient credentials, "
              f"{tampered} tampering rejections, {len(manifest['signatures'])} independent curve/digest CMS signatures, {len(manifest['seed_signatures'])} actual PDF2 EdDSA seed signatures and six MAC digests.")


if __name__ == "__main__":
    main()
