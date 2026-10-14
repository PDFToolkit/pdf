# ISO 32000-2 companion specifications

English | [Français](README.fr.md)

The eleven PDF originals supplied by the project owner are retained in
[`documents/`](documents/) without changing their filenames or bytes.
They come from the [PDF Association sponsored standards collection](https://www.pdfa.org/sponsored-standards/).
[`sources.json`](sources.json) records each copy's identity, SHA-256, byte count,
physical PDF page count and correction collection, where applicable.

| Reference | Subject |
| --- | --- |
| ISO 32000-2:2020 EC3 | PDF 2.0 base specification and industry-resolved errata |
| ISO/TS 32001:2022 EC3 | Additional hash algorithms |
| ISO/TS 32002:2022 EC3 | Additional digital signature algorithms |
| ISO/TS 32003:2023 | AES-GCM encryption |
| ISO/TS 32004:2024 | Integrity protection for encrypted documents (PDF MAC) |
| ISO/TS 32005:2023 | PDF 1.7 and PDF 2.0 structure namespace inclusion |
| PDF Declarations | XMP declarations of external standards or profiles |
| WTPDF 1.0.0 | Tagged PDF requirements for reuse and accessibility |
| Application Note 001 | Black point compensation |
| Application Note 002 | Associated files |
| Application Note 003 | Locations of object metadata streams |

The base specification's earlier supplied copy remains at the repository root.
The new copy, whose original name ends with `(1).pdf`, has a different fingerprint
and is retained separately. A file fingerprint identifies a particular supplied
copy; it does not identify a new edition of the specification.

EC3 annotations are part of the source: an `Accepted` resolution and a
`Completed` resolution do not have the same approval authority. Read the
annotations as well as the page text. The three application notes explain uses
of PDF; they do not replace the ISO text. WTPDF contains both mechanically
checkable requirements and requirements that need a human semantic assessment.
Writing a declaration of conformance is a separate operation from establishing
that the declaration is true.

The original copyright notices and licences remain in each PDF. The PDF
Association publications identify CC-BY-4.0; the ISO publications retain their
ISO copyright. Repository source availability does not change those notices.
The originals are excluded from Composer distribution archives, keeping runtime
installations independent of the reference collection.

[`coverage.json`](coverage.json) records implementation files, independent checks
and explicit limits for each of the eleven documents. It distinguishes the
base dictionary inventory from algorithms, graph validation and conformance.
The [shared bundle guide](https://pdftoolkit.github.io/guides/pdf-2-0-bundle.html)
describes the supported mechanisms and their limits.
