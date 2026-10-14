# Public signing fixtures

English | [Français](README.fr.md)

Public, self-signed test fixtures generated solely for CMS/signature interoperability tests.
The committed private keys are intentionally public and must never be used outside tests.
No real user credentials or production certificates are included.

The RSA1024, RSA4098, DSA1024 and EC-P256 fixtures exercise Table257's actual key-size and algorithm boundaries.
RSA4098 is intentionally outside PDF1.7's normalized4096-bit limit; EC is intentionally outside the three modeled ISO formats.
They are self-signed fixtures valid2026-10-13 through2036-10-13, not trust anchors or production identities.
