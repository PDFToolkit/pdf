# Security policy

English | [Français](SECURITY.fr.md)

## Reporting a vulnerability

Use [GitHub private vulnerability reporting](https://github.com/PDFToolkit/pdf/security/advisories/new) to report a suspected vulnerability. Include the affected version, a minimal reproduction, expected behaviour and the observed impact. Avoid real credentials, customer data and public exploit reports before a fix can be prepared.

The currently supported release series is **0.5**, requiring PHP 8.3 or later. Security fixes are applied to this series and to `master`. Earlier series retain their published requirements; consult the changelog when upgrading.

## Trust boundaries

This library constructs PDF objects and serializes documents. Its object graph and PHP extensions are application code. It is not a PDF reader or a sanitizer for arbitrary PDF content.

External PDF reading is provided by [pdftoolkit/pdf-reader](https://github.com/PDFToolkit/pdf-reader), image-file conversion by [pdftoolkit/pdf-images](https://github.com/PDFToolkit/pdf-images), and TrueType analysis by [pdftoolkit/pdf-fonts](https://github.com/PDFToolkit/pdf-fonts). These optional packages have their own source, tests and security policies. Report an issue in the affected package; installing only the core does not install their parsers.

- Supply untrusted text as `LiteralString` or `TextString` where appropriate, and names as `NameType`. Do not interpolate request data into raw content-stream strings.
- Choose output paths in the application. `writeTo()` rejects stream URIs, empty paths and NUL bytes, but does not confine paths to a directory or defend a directory controlled by another user.
- Keep generated documents in locations where the server will not execute their bytes as PHP or another program. PDF streams may contain arbitrary bytes.
- Authorize links, JavaScript, launch actions, attachments and external file specifications according to the application's policy. The PDF model can represent these features explicitly.
- Bound input size, image dimensions, graph depth, pages, filters, output size, execution time and memory for services processing user data. The writer currently builds the file in memory.
- Keep PHP and its native extensions current. `composer audit --locked` checks known advisories for Composer packages, including development dependencies; it does not audit the PHP runtime or operating system.

The runtime does not include uploaded font definitions, fetch image URLs, invoke a shell, deserialize PHP objects or execute PDF calculator programs. Custom implementations of `BasicType`, `Operator` or `Filter`, and the development generators, must be trusted code.

See [the security review](https://pdftoolkit.github.io/guides/security.html) for the checked surfaces, regression coverage and remaining application responsibilities.

## Historical encryption

The Adobe supplement's revision 5 security handler is an explicit compatibility mechanism. Its fast password derivation is historical, and AES-CBC does not authenticate document content. It is not a modern security default. The optional OpenSSL backend requires an up-to-date PHP OpenSSL extension. The writer rejects unsupported crypt-filter overrides, encrypted incremental revisions and direct trailer strings outside the file identifier. Only the catalog metadata stream may be exempted through `EncryptMetadata`; page and image metadata remain encrypted. See [the Adobe supplement guide](https://pdftoolkit.github.io/guides/adobe-supplement.html) for the exact scope.
