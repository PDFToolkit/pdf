# Independent historical Adobe R=5 fixtures

English | [Français](README.fr.md)

These two PDFs were encrypted by qpdf 12.4.2 with `--force-R5` from the MIT test document built by `tools/fixtures/adobe_encryption.php`. Their public test passwords are `user` and `owner`. One encrypts the metadata stream; the other uses `--cleartext-metadata`.

`qpdf-r5.json` records each immutable PDF's SHA-256, encryption dictionary byte strings, permissions, metadata flag and qpdf's recovered file encryption key. The PHP tests authenticate against these independent values. The Python validator checks both files with qpdf and checks four PDFs written by this library using qpdf and pypdf.

Run `python tools/validate_adobe_encryption.py` to check the retained fixtures. `--build-fixtures` explicitly replaces them with fresh random qpdf vectors and their new hashes; normal validation never rewrites them. These examples exercise the historical protocol in `adobe_supplement_iso32000.pdf`, PDF pages 16–21. R=5 is deprecated; it is not the writer's default.

`qpdf-legacy-key-derivation.json` and four additional PDFs retain qpdf's independently recovered keys for R=2/40-bit, R=3/128-bit, and R=4/128-bit with document metadata encrypted or clear. They test only the MD5 key derivation from supplement Algorithm 3.2, including ISO 32000-1 Algorithm 2's inherited password padding. They do not enable a legacy encrypted writer or password authenticator. The validator also checks padding, truncation, little-endian permissions and every supported byte length against a Python `hashlib` oracle.

`public-key-recipient-1.der` and `public-key-recipient-2.der` are opaque CMS envelopes independently produced by OpenSSL 3.2.3 with `cms -encrypt -binary -aes-256-cbc -outform DER`, using two public test certificates and the non-secret seed `00..13` plus four sample permission bytes. `public-key-sha256.json` records their hashes and Python SHA-256 results for both recipient orders and metadata flags. The core receives these byte strings as explicit objects and hashes them; it does not process ASN.1, CMS, recipient certificates or private keys. Private fixture keys and certificates are not included in the repository.
