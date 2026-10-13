#!/usr/bin/env python3
"""Independently validate historical Adobe R=5 encryption with qpdf and pypdf.

This validator uses public, non-secret 'user'/'owner' fixture passwords. R=5 is
selected only to test the historical supplement; qpdf's modern default is R=6.
"""
import argparse
import hashlib
import json
from pathlib import Path
import random
import re
import stringprep
import subprocess
import unicodedata

from pypdf import PdfReader
from pypdf.generic import IndirectObject


ROOT = Path(__file__).resolve().parents[1]
FIXTURES = ROOT / "tests/Fixtures/adobe-encryption"


def run(command, *, expected=0):
    result = subprocess.run(list(map(str, command)), capture_output=True, text=True, encoding="utf-8", errors="replace")
    if result.returncode != expected:
        raise AssertionError(f"Unexpected exit {result.returncode}: {' '.join(map(str, command))}\n{result.stdout}\n{result.stderr}")
    return result.stdout


def dictionary_vector(path, qpdf):
    document = PdfReader(path)
    dictionary = document.trailer["/Encrypt"]
    assert dictionary["/R"] == 5 and dictionary["/V"] == 5 and dictionary["/Length"] == 256
    vector = {name: dictionary[f"/{name}"].original_bytes.hex() for name in ["O", "U", "OE", "UE", "Perms"]}
    vector.update(P=int(dictionary["/P"]), EncryptMetadata=metadata_flag(dictionary),
                  filename=path.name, sha256=hashlib.sha256(path.read_bytes()).hexdigest())
    report = run([qpdf, "--password=user", "--show-encryption-key", "--show-encryption", path])
    vector["fileKey"] = re.search(r"Encryption key = ([0-9a-f]{64})", report)[1]
    return vector


def metadata_flag(dictionary):
    flag = dictionary.get("/EncryptMetadata")
    return True if flag is None else flag.value


def build_public_key_vectors():
    seed = bytes(range(20))
    recipients = [FIXTURES / f"public-key-recipient-{number}.der" for number in [1, 2]]
    vectors = []
    for ordered in [recipients, list(reversed(recipients))]:
        for metadata in [True, False]:
            raw = seed + b"".join(path.read_bytes() for path in ordered) + (b"" if metadata else b"\xff" * 4)
            vectors.append(dict(seed=seed.hex(), recipients=[path.name for path in ordered], EncryptMetadata=metadata,
                                fileKey=hashlib.sha256(raw).hexdigest()))
    data = dict(producer="OpenSSL 3.2.3 cms -encrypt -binary -aes-256-cbc -outform DER; SHA-256 oracle: Python hashlib",
                scope="Opaque CMS envelopes of a public 20-byte test seed plus four sample permission bytes. No CMS or certificate validation is implemented by the library.",
                recipients=[dict(filename=path.name, sha256=hashlib.sha256(path.read_bytes()).hexdigest()) for path in recipients], vectors=vectors)
    (FIXTURES / "public-key-sha256.json").write_text(json.dumps(data, indent=2, sort_keys=True) + "\n", encoding="utf-8", newline="\n")


def reference_legacy_key(vector):
    password = bytes.fromhex(vector["userPasswordHex"])
    padding = bytes.fromhex("28bf4e5e4e758a4164004e56fffa01082e2e00b6d0683e802f0ca9fe6453697a")
    data = (password + padding)[:32] + bytes.fromhex(vector["O"]) + (vector["P"] & 0xffffffff).to_bytes(4, "little") + bytes.fromhex(vector["ID"])
    if vector["R"] >= 4 and not vector["EncryptMetadata"]:
        data += b"\xff" * 4
    result = hashlib.md5(data).digest()
    length = 5 if vector["R"] == 2 else vector["Length"] // 8
    if vector["R"] >= 3:
        for _ in range(50):
            result = hashlib.md5(result[:length]).digest()
    return result[:length].hex()


def validate_key_derivation(args, output):
    legacy = json.loads((FIXTURES / "qpdf-legacy-key-derivation.json").read_text(encoding="utf-8"))["vectors"]
    cases = []
    expected = []
    for vector in legacy:
        path = FIXTURES / vector["filename"]
        assert hashlib.sha256(path.read_bytes()).hexdigest() == vector["sha256"]
        document = PdfReader(path)
        encryption = document.trailer["/Encrypt"]
        assert encryption["/R"] == vector["R"] and encryption["/V"] == vector["V"] and encryption["/P"] == vector["P"]
        assert int(encryption.get("/Length", 40)) == vector["Length"]
        assert encryption["/O"].original_bytes.hex() == vector["O"]
        assert document.trailer["/ID"][0].original_bytes.hex() == vector["ID"]
        assert metadata_flag(encryption) == vector["EncryptMetadata"]
        report = run([args.qpdf, "--password=user", "--show-encryption-key", "--show-encryption", path])
        assert re.search(r"Encryption key = ([0-9a-f]+)", report)[1] == vector["fileKey"]
        run([args.qpdf, "--password=user", "--check", path])
        run([args.qpdf, "--password=wrong", "--check", path], expected=2)
        assert reference_legacy_key(vector) == vector["fileKey"]
        cases.append(vector)
        expected.append(vector["fileKey"])
    # Exercise padding, byte truncation, endian permissions, all legacy byte lengths
    # and the R=2 fixed five-byte rule using the independent Python algorithm.
    for revision in [2, 3, 4]:
        for bits in range(40, 129, 8):
            for password in ["", "78" * 32, "78" * 33, "e4f6fc"]:
                vector = dict(R=revision, Length=bits, P=-3392, ID="41" * 16, O="ab" * 32, EncryptMetadata=False, userPasswordHex=password)
                cases.append(vector)
                expected.append(reference_legacy_key(vector))
    public_data = json.loads((FIXTURES / "public-key-sha256.json").read_text(encoding="utf-8"))
    for recipient in public_data["recipients"]:
        assert hashlib.sha256((FIXTURES / recipient["filename"]).read_bytes()).hexdigest() == recipient["sha256"]
    public_cases = []
    for vector in public_data["vectors"]:
        recipients = [(FIXTURES / name).read_bytes() for name in vector["recipients"]]
        key = hashlib.sha256(bytes.fromhex(vector["seed"]) + b"".join(recipients) + (b"" if vector["EncryptMetadata"] else b"\xff" * 4)).hexdigest()
        assert key == vector["fileKey"]
        public_cases.append(dict(vector, recipients=[recipient.hex() for recipient in recipients]))
        expected.append(key)
    input_path = output / "key-derivation-input.json"
    input_path.write_text(json.dumps(dict(legacy=cases, publicKey=public_cases)), encoding="utf-8")
    actual = run([args.php, ROOT / "tools/fixtures/adobe_key_derivation.php", input_path]).splitlines()
    assert actual == expected, "A historical key derivation disagrees with the independent qpdf/hashlib oracle."
    return len(cases) + len(public_cases)


def build_fixtures(args, output):
    FIXTURES.mkdir(parents=True, exist_ok=True)
    vectors = []
    for clear in [False, True]:
        destination = FIXTURES / ("qpdf-r5-metadata-clear.pdf" if clear else "qpdf-r5-metadata-encrypted.pdf")
        options = ["--cleartext-metadata"] if clear else []
        run([args.qpdf, "--encrypt", "user", "owner", "256", "--force-R5", "--print=none", "--modify=none", "--extract=n", "--accessibility=n", *options, "--", output / "plain.pdf", destination])
        vectors.append(dictionary_vector(destination, args.qpdf))
    metadata = dict(producer=run([args.qpdf, "--version"]).strip(), passwords=dict(user="user", owner="owner"),
                    source="MIT test document built by tools/fixtures/adobe_encryption.php", vectors=vectors)
    (FIXTURES / "qpdf-r5.json").write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n", encoding="utf-8", newline="\n")


def check_fixture(args):
    metadata = json.loads((FIXTURES / "qpdf-r5.json").read_text(encoding="utf-8"))
    for vector in metadata["vectors"]:
        path = FIXTURES / vector["filename"]
        assert dictionary_vector(path, args.qpdf) == vector, f"The independent fixture changed: {path}"
        for password in ["user", "owner"]:
            run([args.qpdf, f"--password={password}", "--check", path])
        run([args.qpdf, "--password=wrong", "--check", path], expected=2)


def validate_written_files(args, output):
    for name in ["table-metadata-encrypted", "table-metadata-clear", "stream-metadata-encrypted", "stream-metadata-clear"]:
        path = output / f"{name}.pdf"
        raw = path.read_bytes()
        clear = name.endswith("-clear")
        assert b"R5 dictionary text" not in raw and b"R5 content stream" not in raw
        assert (b"R5 visible metadata" in raw) == clear
        assert b"R5 private page metadata" not in raw
        assert b"R5 private signature reason" not in raw
        assert b"5235207369676E617475726520636F6E7461696E6572206279746573" in raw
        assert b"41414141414141414141414141414141" in raw and b"42424242424242424242424242424242" in raw
        for password, expected_access in [("user", 1), ("owner", 2)]:
            run([args.qpdf, f"--password={password}", "--check", path])
            document = PdfReader(path)
            assert document.decrypt(password) == expected_access
            assert len(document.pages) == 1
            assert document.pages[0].extract_text() == "R5 content stream"
            assert document.pages[0]["/Metadata"].get_data() == b"<metadata>R5 private page metadata</metadata>"
            assert document.metadata.title == "R5 dictionary text"
            assert document.trailer["/Info"]["/Nested"] == ["R5 nested literal", "R5 nested hexadecimal"]
            assert document.trailer["/ID"][0].original_bytes == b"A" * 16
            assert document.trailer["/ID"][1].original_bytes == b"B" * 16
            # pypdf 6.20.1 decrypts every stream with StmF, including metadata
            # explicitly excluded by EncryptMetadata=false. qpdf honors this
            # exception; inspect its decrypted output using pypdf instead.
            if not clear:
                assert document.trailer["/Root"]["/Metadata"].get_data() == b"<metadata>R5 visible metadata</metadata>"
            assert document.get_object(IndirectObject(40, 0, document)).get_data() == bytes(range(256)) + b"\nendstream\nendobj\n"
            assert document.get_object(IndirectObject(41, 0, document)) == "R5 standalone string"
            encryption = document.trailer["/Encrypt"]
            assert encryption["/P"] == -3904 and metadata_flag(encryption) == (not clear)
            assert encryption["/CF"]["/StdCF"]["/CFM"] == "/AESV3"
        wrong = PdfReader(path)
        assert wrong.decrypt("wrong") == 0
        run([args.qpdf, "--password=wrong", "--check", path], expected=2)
        decrypted_path = output / f"{name}-decrypted.pdf"
        run([args.qpdf, "--password=user", "--decrypt", path, decrypted_path])
        decrypted = PdfReader(decrypted_path)
        assert not decrypted.is_encrypted
        assert decrypted.trailer["/Root"]["/Metadata"].get_data() == b"<metadata>R5 visible metadata</metadata>"
        assert decrypted.pages[0].extract_text() == "R5 content stream"
        assert decrypted.pages[0]["/Metadata"].get_data() == b"<metadata>R5 private page metadata</metadata>"
        signature = decrypted.trailer["/Root"]["/Perms"]["/DocMDP"]
        assert signature["/Contents"] == "R5 signature container bytes"
        assert signature["/Reason"] == "R5 private signature reason"


def reference_saslprep(value):
    mapped = "".join(" " if stringprep.in_table_c12(char) else char for char in value if not stringprep.in_table_b1(char))
    result = unicodedata.ucd_3_2_0.normalize("NFKC", mapped)
    forbidden = [stringprep.in_table_a1, stringprep.in_table_c12, stringprep.in_table_c21, stringprep.in_table_c22,
                 stringprep.in_table_c3, stringprep.in_table_c4, stringprep.in_table_c5, stringprep.in_table_c6,
                 stringprep.in_table_c7, stringprep.in_table_c8, stringprep.in_table_c9]
    if any(test(char) for char in result for test in forbidden):
        return dict(invalid=True)
    if any(stringprep.in_table_d1(char) for char in result):
        if any(stringprep.in_table_d2(char) for char in result) or not stringprep.in_table_d1(result[0]) or not stringprep.in_table_d1(result[-1]):
            return dict(invalid=True)
    return dict(value=result.encode("utf-8").hex())


def validate_saslprep(args, output):
    ucd = unicodedata.ucd_3_2_0
    samples = ["", "I\u00adX", "\u00aa", "\u2168", "\u06271\u0628", "\u06271", "a\u0315\u0300"]
    samples += [chr(point) for point in range(0x110000) if ucd.decomposition(chr(point)) or ucd.combining(chr(point))]
    tables = json.loads((ROOT / "resources/security-unicode32/unicode-3.2-saslprep.json").read_text(encoding="utf-8"))
    for name in ["mappedToNothing", "nonAsciiSpace", "unassigned", "prohibited", "randAL", "leftToRight"]:
        for first, last in tables[name]:
            samples.extend(chr(point) for point in {max(0, first - 1), first, last, min(0x10ffff, last + 1)})
    rng = random.Random(320004013)
    marks = [point for point in range(0x110000) if ucd.combining(chr(point))]
    for _ in range(5000):
        samples.append(rng.choice(["a", "e", "\u0627", "\u1100", "\u1161", ""]) + "".join(chr(rng.choice(marks)) for _ in range(rng.randrange(8))))
    vectors = output / "saslprep-input.json"
    vectors.write_text(json.dumps([value.encode("utf-8", errors="surrogatepass").hex() for value in samples]), encoding="utf-8")
    actual = run([args.php, ROOT / "tools/fixtures/adobe_encryption.php", "saslprep", vectors]).splitlines()
    assert len(actual) == len(samples)
    for index, (value, result) in enumerate(zip(samples, actual)):
        assert json.loads(result) == reference_saslprep(value), f"SASLprep case {index} disagrees with Python Unicode 3.2: {value!r}"
    return len(samples)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="php")
    parser.add_argument("--qpdf", default="qpdf")
    parser.add_argument("--output", type=Path, default=ROOT / "_build/adobe-encryption")
    parser.add_argument("--build-fixtures", action="store_true", help="Explicitly create new independent qpdf vectors; normal checks never rewrite fixtures.")
    parser.add_argument("--build-public-key-vectors", action="store_true", help="Record SHA-256 oracle vectors from the retained opaque CMS fixtures.")
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    run([args.php, ROOT / "tools/fixtures/adobe_encryption.php", args.output])
    if args.build_fixtures:
        build_fixtures(args, args.output)
    if args.build_public_key_vectors:
        build_public_key_vectors()
    check_fixture(args)
    validate_written_files(args, args.output)
    count = validate_saslprep(args, args.output)
    keys = validate_key_derivation(args, args.output)
    print(f"Adobe R=5: 4 writer files, 2 independent qpdf vectors, both access classes, incorrect passwords, binary data, metadata, object streams and {count} SASLprep cases validated. Historical key primitives: {keys} qpdf/hashlib/CMS-input cases validated.")


if __name__ == "__main__":
    main()
