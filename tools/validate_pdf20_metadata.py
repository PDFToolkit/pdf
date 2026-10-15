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
from pypdf.generic import ContentStream, IndirectObject


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
    parser.add_argument("--pdftoppm", default="pdftoppm")
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
    assert "/Metadata" not in part
    assert part["/DPM"]["/State"] == "/Révisé"
    assert xmp(part_root).find(".//{urn:example:job}description").text == "One-page proof"
    assert not xmp(part_root).findall(".//pdfd:declarations", ns)
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

    branch_paths = []
    for format_name in ("table", "stream", "hybrid"):
        path = output / ("document-parts-ec3-" + format_name + ".pdf")
        run(arguments.php, str(source / "tools/fixtures/document-parts-ec3.php"), str(path), format_name)
        run(arguments.qpdf, "--check", str(path))
        branches = PdfReader(path, strict=True)
        assert len(branches.pages) == 3
        hierarchy = branches.trailer["/Root"]["/DPartRoot"]
        parent = hierarchy["/DPartRootNode"]
        children = parent["/DParts"]
        assert len(children) == 2 and all(isinstance(child, IndirectObject) for child in children)
        assert "/Start" not in parent and "/End" not in parent and "/Metadata" not in parent
        assert parent.raw_get("/Parent") == hierarchy.indirect_reference
        first, second = [child.get_object() for child in children]
        assert first.raw_get("/Parent") == parent.indirect_reference == second.raw_get("/Parent")
        assert first["/DPM"]["/State"] == "/Révisé" and second["/DPM"]["/State"] == "/Ready"
        assert "/Metadata" not in first and "/Metadata" not in second
        assert first.raw_get("/Start") == branches.pages[0].indirect_reference
        assert first.raw_get("/End") == branches.pages[1].indirect_reference
        assert second.raw_get("/Start") == branches.pages[2].indirect_reference and "/End" not in second
        for page, part in zip(branches.pages, [first, first, second], strict=True):
            assert page.raw_get("/DPart") == part.indirect_reference
        assert hierarchy["/RecordLevel"] == 1 and hierarchy["/NodeNameList"] == ["/Job", "/Customer"]
        branch_paths.append(path)

    domain_paths = []
    for format_name in ("table", "stream", "hybrid"):
        path = output / ("ec3-dictionary-domains-" + format_name + ".pdf")
        run(arguments.php, str(source / "tools/fixtures/ec3-dictionary-domains.php"), str(path), format_name)
        run(arguments.qpdf, "--check", str(path))
        domains = PdfReader(path, strict=True)
        root = domains.trailer["/Root"]
        assert len(domains.pages) == root["/Pages"]["/Count"] == 1
        assert len(root["/Pages"]["/Kids"]) == 1
        assert "/Contents" not in domains.pages[0] and "/Resources" in domains.pages[0]
        assert domains.pages[0]["/Resources"]["/Font"]["/F1"]["/FontDescriptor"]["/FontWeight"] == 450
        type3 = domains.pages[0]["/Resources"]["/Font"]["/F2"]
        assert type3["/Subtype"] == "/Type3" and "/FontName" not in type3["/FontDescriptor"]
        radio = root["/OCProperties"]["/D"]["/RBGroups"]
        assert len(radio) == 1 and len(radio[0]) == 2
        assert all(isinstance(item, IndirectObject) for item in radio[0])
        assert root["/OCProperties"]["/Configs"][0].get_object()["/RBGroups"] == []
        extension = root["/Extensions"]["/EXAM"]
        assert extension["/URL"] == "https://example.org/pdf-extension"
        assert "/Metadata" not in extension and "/AF" not in extension
        domain_paths.append(path)

    media_paths = []
    for format in ("table", "stream", "hybrid"):
        path = output / f"rich-media-{format}.pdf"
        run(arguments.php, str(source / "tools/fixtures/rich-media-ec3.php"), str(path), format)
        run(arguments.qpdf, "--check", str(path))
        media = PdfReader(path)
        annotations = [entry.get_object() for entry in media.pages[0]["/Annots"]]
        assert len(annotations) == 2
        assert annotations[0].raw_get("/RichMediaContent") != annotations[1].raw_get("/RichMediaContent")
        assert annotations[0].raw_get("/RichMediaSettings") != annotations[1].raw_get("/RichMediaSettings")
        for annotation in annotations:
            content = annotation["/RichMediaContent"]
            activation = annotation["/RichMediaSettings"]["/Activation"]
            assets = content["/Assets"]["/Names"]
            assert activation["/Scripts"][0] == assets[1]
            assert activation.raw_get("/Configuration") == content["/Configurations"][0]
            script = assets[1].get_object()["/EF"]["/F"]
            assert script.get_data() == b"// Deliberately empty script."
            assert script["/Subtype"] == "/application/javascript"
            primary_reference = content["/Configurations"][0].get_object()["/Instances"][0].get_object().raw_get("/Asset")
            assert primary_reference != assets[1]
            primary = primary_reference.get_object()["/EF"]["/F"]
            assert primary["/Subtype"] == "/application/octet-stream"
            assert primary.get_data() == b"Explicit opaque primary asset."
        media_paths.append(path)

    empty_paths = []
    for format_name in ("table", "stream", "hybrid"):
        rendered = []
        for kind in ("baseline", "empty"):
            path = output / f"path-painting-{format_name}-{kind}.pdf"
            run(arguments.php, str(source / "tools/fixtures/empty-path-painting.php"), str(path), format_name, kind)
            run(arguments.qpdf, "--check", str(path))
            pdf = PdfReader(path, strict=True)
            operators = [operator for _, operator in ContentStream(pdf.pages[0].get_contents(), pdf).operations]
            if kind == "empty":
                assert operators[:10] == [b"S", b"s", b"f", b"F", b"f*", b"B", b"B*", b"b", b"b*", b"n"]
            prefix = path.with_suffix("")
            run(arguments.pdftoppm, "-r", "72", "-singlefile", str(path), str(prefix))
            rendered.append(prefix.with_suffix(".ppm").read_bytes())
            empty_paths.append(path)
        assert rendered[0] == rendered[1], "Empty-path operators must not alter pixels or repaint a completed path"

    utf8_paths = []
    expected_xml = '<?xml version="1.0" encoding="UTF-8"?><x:xmpmeta xmlns:x="adobe:ns:meta/" xmlns:e="urn:example:utf8"><e:label>Révisé — 日本語</e:label></x:xmpmeta>'.encode('utf-8')
    for format_name in ("table", "stream", "hybrid"):
        run(arguments.php, str(source / "tools/fixtures/xmp-utf8-revisions.php"), str(output), format_name)
        prefix = b""
        reference = None
        for kind in ("source", "upgraded", "retained"):
            path = output / f"xmp-utf8-{format_name}-{kind}.pdf"
            run(arguments.qpdf, "--check", str(path))
            bytes_ = path.read_bytes()
            assert bytes_.startswith(prefix), "An XMP catalog upgrade must preserve its complete historical prefix"
            prefix = bytes_
            pdf = PdfReader(path, strict=True)
            assert pdf.pdf_header == "%PDF-1.7"
            root = pdf.trailer["/Root"]
            assert root.get("/Version") == (None if kind == "source" else "/2.0")
            metadata = root["/Metadata"]
            current_reference = (metadata.indirect_reference.idnum, metadata.indirect_reference.generation)
            assert reference is None or current_reference == reference
            reference = current_reference
            assert metadata["/Filter"] == ["/ASCII85Decode", "/FlateDecode"]
            xml = metadata.get_data()
            assert xml == expected_xml and xml.decode("utf-8").encode("utf-8") == xml
            assert ET.fromstring(xml).find("{urn:example:utf8}label").text == "Révisé — 日本語"
            utf8_paths.append(path)

    placement_paths = []
    for format_name in ("table", "stream", "hybrid"):
        path = output / f"structure-placement-{format_name}.pdf"
        run(arguments.php, str(source / "tools/fixtures/structure-placement-ec3.php"), str(path), format_name)
        run(arguments.qpdf, "--check", str(path))
        pdf = PdfReader(path, strict=True)
        root = pdf.trailer["/Root"]["/StructTreeRoot"]
        document = root["/K"][0].get_object()
        assert document["/S"] == "/Document"
        for reference, parent_role, dimension in zip(document["/K"], ("/P", "/Sect"), ("/Width", "/Height"), strict=True):
            parent = reference.get_object()
            figure = parent["/K"]
            assert parent["/S"] == parent_role and figure["/S"] == "/Figure"
            assert figure.raw_get("/P") == parent.indirect_reference
            attributes = figure["/A"]
            assert attributes["/O"] == "/Layout" and attributes[dimension] == 10
            assert "/Placement" not in attributes, "The default is contextual, not emitted as an invented explicit attribute"
            assert ("/Height" if dimension == "/Width" else "/Width") not in attributes
        placement_paths.append(path)

    wrapper = PdfReader(wrapper_path)
    assert not wrapper.is_encrypted and len(wrapper.pages) == 1
    wrapper_root = wrapper.trailer["/Root"]
    entries = wrapper_root["/Names"]["/EmbeddedFiles"]["/Names"]
    files = dict(zip(entries[::2], entries[1::2], strict=True))
    assert set(files) == {"payload.pdf", "instructions.txt"}
    assert wrapper_root["/Collection"]["/D"] == "payload.pdf"
    assert wrapper_root["/Collection"]["/View"] == "/H" and files["payload.pdf"] == wrapper_root["/AF"][0]
    assert b"demo-reader" in files["instructions.txt"].get_object()["/EF"]["/F"].get_data()
    payload = files["payload.pdf"].get_object()
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
    proof = {"status": "passed", "checks": ["namespace-aware XML and RDF bags", "bilingual title", "distinct document, DPartRoot and marked-content metadata; DPart Metadata forbidden", "DPart reciprocal links and UTF-8 name DPM", "EC3 flat DPart children and page order under all three xref formats", "EC3 nonempty pages, integer font weight, radio arrays and required extension URL", "calibrated colour and exact BPC state", "discoverable AF identity/MIME/source date", "unencrypted payload wrapper with an additional named attachment (EC3 #529)", "annotation-owned RichMedia configurations, script identity and MIME Subtype of named and unnamed embedded assets in all three xref formats", "independent AES-256 R6 decryption", "EC3 empty-path painting leaves Poppler pixels unchanged in all three xref formats"], "examples": {path.name: {"bytes": path.stat().st_size, "sha256": hashlib.sha256(path.read_bytes()).hexdigest()} for path in (metadata_path, wrapper_path, *branch_paths, *domain_paths, *media_paths, *empty_paths)}}
    proof["checks"].append("EC3 #296 exact UTF-8 XMP under two decoding filters, immutable PDF1.7-to-PDF2 upgrades and retained revisions in all three xref formats")
    proof["examples"].update({path.name: {"bytes": path.stat().st_size, "sha256": hashlib.sha256(path.read_bytes()).hexdigest()} for path in utf8_paths})
    proof["checks"].append("EC3 #188 PDF2 Figure/P and Figure/Sect contextual Placement, preserving absent Placement and the required Width/Height in three xref formats")
    proof["examples"].update({path.name: {"bytes": path.stat().st_size, "sha256": hashlib.sha256(path.read_bytes()).hexdigest()} for path in placement_paths})
    (output / "metadata-validation.json").write_text(json.dumps(proof, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(proof, indent=2))


if __name__ == "__main__":
    main()
