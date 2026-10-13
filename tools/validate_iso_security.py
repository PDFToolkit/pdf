#!/usr/bin/env python3
"""Independently inspect the complete ISO password-handler writer variants."""
from pathlib import Path
import argparse
import json
import subprocess
import tempfile
from io import BytesIO
from unittest.mock import patch
from pypdf import PdfReader
from pypdf.generic import IndirectObject, StreamObject
from pypdf._crypt_providers import _fallback
from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.reader import PdfFileReader


def run(command, expected=0):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    if result.returncode != expected:
        raise AssertionError(f"Unexpected exit {result.returncode}: {command}\n{result.stdout!r}\n{result.stderr!r}")
    return result.stdout


def inspect(reader, incremental=False):
    assert len(reader.pages) == 1
    assert reader.metadata.title == ("Updated private title" if incremental else "Historical private title")
    assert reader.pages[0].get_contents().get_data() == (b"% Updated private bytes\n" if incremental else b"% Private content bytes\n")
    assert reader.trailer['/Root']['/Metadata'].get_data() == b'<metadata>Document XML</metadata>'
    assert reader.pages[0]['/Metadata'].get_data() == b'<metadata>Private page XML</metadata>'
    streams = []
    for generation, offsets in reader.xref.items():
        for number in offsets:
            if number:
                obj = reader.get_object(IndirectObject(number, generation, reader))
                if isinstance(obj, StreamObject):
                    streams.append(obj)
    binary = bytes(range(256)) + b'\nendstream\nendobj\n'
    assert any(stream.get('/Type') == '/EmbeddedFile' and stream.get_data() == binary for stream in streams)


def inspect_revision_four(path, incremental=False):
    reader = PdfFileReader(BytesIO(path.read_bytes()))
    # Authenticate the PDF1.7 handler explicitly, without applying PDF2.0 MAC policy.
    assert reader.security_handler.authenticate('user', id1=reader.document_id[0]).status.name in ('USER', 'OWNER')
    assert reader.trailer['/Info']['/Title'] == ('Updated private title' if incremental else 'Historical private title')
    page = reader.root['/Pages']['/Kids'][0]
    assert page['/Contents'].data == (b'% Updated private bytes\n' if incremental else b'% Private content bytes\n')
    assert reader.root['/Metadata'].data == b'<metadata>Document XML</metadata>'
    assert page['/Metadata'].data == b'<metadata>Private page XML</metadata>'
    assert reader.get_object(generic.Reference(40, 0, pdf=reader)).data == bytes(range(256)) + b'\nendstream\nendobj\n'
    assert reader.get_object(generic.Reference(41, 0, pdf=reader)).data == b'Explicit clear stream'
    assert reader.get_object(generic.Reference(42, 0, pdf=reader)).data == b'Explicit encrypted stream'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-iso-security-') as temporary:
        directory = Path(temporary)
        run([args.php, root / 'tools/fixtures/iso_encryption.php', directory])
        cases = json.loads((directory / 'manifest.json').read_text())
        for case in cases:
            path = directory / case['file']
            owner = 'user' if case['attachmentOnly'] else 'owner'
            passwords = ['user', owner] if case['revision'] != 3 or case['bits'] == 128 else ['user']
            for password in passwords:
                run([args.qpdf, f'--password={password}', '--show-xref' if case['revision'] == 4 else '--check', path])
            run([args.qpdf, '--password=incorrect', '--check', path], expected=2)
            if case['revision'] == 4:
                # qpdf12.4.2 derives an object key even for an explicit Crypt;
                # ISO7.4.10 requires the selected key as-is. pyHanko follows this
                # exception and verifies all decoded data without algorithm patches.
                inspect_revision_four(path)
            else:
                plain = directory / ('decrypted-' + case['file'])
                run([args.qpdf, '--password=user', '--decrypt', '--preserve-unreferenced', path, plain])
                inspect(PdfReader(plain))
            # Use pypdf's own general RC4 provider: PyCA only accepts selected key sizes,
            # whereas ISO permits every whole-byte size from 40 to 128 bits.
            # qpdf hashes only n bytes in owner rounds for R3<128; ISO Algorithm3
            # requires the full MD5 digest. pypdf independently follows that algorithm.
            with patch('pypdf._encryption.rc4_encrypt', _fallback.rc4_encrypt), patch('pypdf._encryption.rc4_decrypt', _fallback.rc4_decrypt), patch('pypdf._encryption.CryptRC4', _fallback.CryptRC4):
                reader = PdfReader(path)
                assert reader.decrypt('user') in (1, 2)
                assert reader.decrypt(owner) == 2
                assert not reader.decrypt('incorrect')
            delta = directory / case['incrementalFile']
            assert delta.read_bytes().startswith(path.read_bytes())
            if case['revision'] == 4:
                run([args.qpdf, '--password=user', '--show-xref', delta])
                inspect_revision_four(delta, incremental=True)
            else:
                run([args.qpdf, '--password=user', '--check', delta])
                delta_plain = directory / ('decrypted-' + case['incrementalFile'])
                run([args.qpdf, '--password=user', '--decrypt', '--preserve-unreferenced', delta, delta_plain])
                inspect(PdfReader(delta_plain), incremental=True)
        print(f"ISO password handlers: {len(cases)} initial and {len(cases)} incremental RC4/AESV2 files, 40–128-bit keys, both xref formats, both access classes, metadata and explicit/default crypt selectors verified independently.")


if __name__ == '__main__':
    main()
