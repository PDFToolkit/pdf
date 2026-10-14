"""Independent pypdf/XML/qpdf checks for metadata ownership and encrypted payload wrappers.

Sources: PDF-Declarations.pdf pp.7-10; PDF20_AN001-BPC.pdf p.4;
PDF20_AN002-AF.pdf pp.7,10,12-13; PDF20_AN003-ObjectMetadataLocations.pdf pp.4-10;
ISO_32000-2_sponsored_EC3.pdf pp.109-110,730,850-853.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import xml.etree.ElementTree as ET
from pathlib import Path

from pypdf import PdfReader
from pypdf.generic import ContentStream


def run(*arguments: str) -> str:
    result = subprocess.run(arguments, check=True, capture_output=True, text=True, encoding="utf-8")
    return result.stdout


def xmp(owner):
    stream = owner["/Metadata"]
    assert stream["/Type"] == "/Metadata" and stream["/Subtype"] == "/XML"
    return ET.fromstring(stream.get_data())


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", required=True)
    parser.add_argument("--qpdf", required=True)
    parser.add_argument("--work-dir", type=Path, required=True)
    arguments = parser.parse_args()
    source = Path(__file__).resolve().parents[1]
    output = arguments.work_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    metadata_path = output / "metadata-declarations.pdf"
    wrapper_path = output / "encrypted-payload-wrapper.pdf"
    run(arguments.php, str(source / "examples/pdf20-metadata-declarations.php"), str(metadata_path))
    run(arguments.php, str(source / "examples/pdf20-encrypted-payload.php"), str(wrapper_path))
    run(arguments.qpdf, "--check", str(metadata_path))
    run(arguments.qpdf, "--check", str(wrapper_path))

    metadata = PdfReader(metadata_path)
    assert metadata.pdf_header == "%PDF-2.0" and len(metadata.pages) == 1
    root = metadata.trailer["/Root"]
    ns = {"rdf": "http://www.w3.org/1999/02/22-rdf-syntax-ns#", "pdfd": "http://pdfa.org/declarations/", "dc": "http://purl.org/dc/elements/1.1/"}
    catalog_xmp = xmp(root)
    claims = catalog_xmp.findall(".//pdfd:declarations/rdf:Bag/rdf:li", ns)
    assert len(claims) == 1 and claims[0].find("pdfd:conformsTo", ns).text == "https://example.org/pdf/reviewed"
    assert claims[0].find(".//pdfd:claimReport", ns).text == "#ef=review.txt"
    title = catalog_xmp.findall(".//dc:title/rdf:Alt/rdf:li", ns)
    assert [item.attrib["{http://www.w3.org/XML/1998/namespace}lang"] for item in title] == ["x-default", "fr"]
    assert title[1].text == "Métadonnées PDF 2.0 et parties de document"
    page = metadata.pages[0]
    part_root = root["/DPartRoot"]
    part = part_root["/DPartRootNode"]
    assert page.raw_get("/DPart") == part.indirect_reference
    assert part.raw_get("/Start") == page.indirect_reference and "/End" not in part
    assert part.raw_get("/Parent") == part_root.indirect_reference
    assert part_root["/NodeNameList"] == ["/Proof"] and part_root["/RecordLevel"] == 0
    assert part["/DPM"]["/Job"] == "Proof 42"
    assert xmp(part).find(".//{urn:example:job}description").text == "One-page proof"
    assert not xmp(part).findall(".//pdfd:declarations", ns)
    properties = page["/Resources"]["/Properties"]["/ColourInfo"]
    assert xmp(properties).find(".//{urn:example:sample}purpose").text == "Calibrated colour demonstration"
    assert page["/Resources"]["/ExtGState"]["/BpcOn"]["/UseBlackPtComp"] == "/ON"
    assert page["/Resources"]["/ColorSpace"]["/Calibrated"][0] == "/CalRGB"
    operations = ContentStream(page["/Contents"], metadata).operations
    assert any(operator == b"BDC" and list(operands) == ["/Sample", "/ColourInfo"] for operands, operator in operations)
    report = root["/Names"]["/EmbeddedFiles"]["/Names"]
    assert report[0] == "review.txt" and report[1] == root["/AF"][0] == part["/AF"][0]
    file = report[1].get_object()
    assert file["/AFRelationship"] == "/Supplement" and "/Metadata" not in file
    assert file["/EF"]["/F"]["/Subtype"] == "/text/plain" and "/ModDate" in file["/EF"]["/F"]["/Params"]
    assert file["/EF"]["/F"].get_data() == b"Example review report. This is not a certification.\n"

    wrapper = PdfReader(wrapper_path)
    assert not wrapper.is_encrypted and len(wrapper.pages) == 1
    wrapper_root = wrapper.trailer["/Root"]
    entries = wrapper_root["/Names"]["/EmbeddedFiles"]["/Names"]
    assert len(entries) == 2 and entries[0] == wrapper_root["/Collection"]["/D"] == "payload.pdf"
    assert wrapper_root["/Collection"]["/View"] == "/H" and entries[1] == wrapper_root["/AF"][0]
    payload = entries[1].get_object()
    assert payload["/AFRelationship"] == "/EncryptedPayload" and payload["/EP"]["/Subtype"] == "/Standard"
    encrypted_path = output / "extracted-encrypted-payload.pdf"
    encrypted_path.write_bytes(payload["/EF"]["/F"].get_data())
    encrypted = PdfReader(encrypted_path)
    assert encrypted.is_encrypted and encrypted.trailer["/Encrypt"]["/R"] == 6
    decrypted_path = output / "decrypted-payload.pdf"
    run(arguments.qpdf, "--password=demo-reader", "--decrypt", str(encrypted_path), str(decrypted_path))
    run(arguments.qpdf, "--check", str(decrypted_path))
    decrypted = PdfReader(decrypted_path)
    assert not decrypted.is_encrypted and "after password authentication" in decrypted.pages[0].extract_text()
    proof = {"status": "passed", "checks": ["namespace-aware XML and RDF bags", "bilingual title", "distinct document, part and marked-content metadata", "DPart reciprocal links and DPM", "calibrated colour and exact BPC state", "discoverable AF identity/MIME/source date", "unencrypted one-payload wrapper", "independent AES-256 R6 decryption"], "examples": {path.name: {"bytes": path.stat().st_size, "sha256": hashlib.sha256(path.read_bytes()).hexdigest()} for path in (metadata_path, wrapper_path)}}
    (output / "metadata-validation.json").write_text(json.dumps(proof, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(proof, indent=2))


if __name__ == "__main__":
    main()
