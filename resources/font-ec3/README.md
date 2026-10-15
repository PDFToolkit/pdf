# EC3 font descriptors and ToUnicode metadata

English | [Français](README.fr.md)

The unchanged `ISO_32000-2_sponsored_EC3.pdf`, SHA-256
`90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230`,
physical page 364, issue #5, permits class-specific metric/classification entries
from Table 120. `CIDFontMetricOverride` accepts `FontBBox`, `FontFamily`,
`FontStretch` and `FontWeight` as well as existing metrics. Font programs and
CID-only Table 122 entries remain prohibited. `CIDFontDescriptors::effective()`
applies these local overrides while retaining unspecified font-wide defaults.
PDF2 weights are integers from 1 through 1000; historical weights retain their
100-step rule.

Issue #462, physical pages 372 and 1022 (Table 125a), aligns ToUnicode stream
metadata with its CMap program. `UnicodeCMap` accepts optional `systemInfo` and
`mode` after its existing `base` argument. Defaults remain Adobe/UCS/0 and
horizontal writing. `ToUnicodeCMap` emits Type, CMapName, CIDSystemInfo and WMode
from those same immutable values, and retains UseCMap inheritance. A ToUnicode
collection need not match the font's Identity-H/Identity-V collection.

These objects construct mappings from explicitly supplied codes and Unicode
sequences. They do not download named CMaps or execute arbitrary PostScript.
Native tests exercise overrides, forbidden keys, edition boundaries, vertical
metadata and inherited mappings. `tools/validate_cmaps.py` compares emitted
metadata/program bytes and verifies extracted text independently with pypdf and
Poppler.
