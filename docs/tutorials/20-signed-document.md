# 20. Signer sans déplacer les objets

Le [script complet](../../examples/signed-document.php) produit une page visible
et une signature CMS détachée associée à un champ `/Sig` invisible.

```sh
php examples/signed-document.php signed-document.pdf
```

L'extension PHP OpenSSL est nécessaire. Le certificat et la clé du script sont
**publics et réservés aux tests** : ils permettent de reproduire une signature,
sans prouver une identité ni une confiance.

`X509Certificate`, `CertificateChain` et `OpenSslIdentity` reçoivent des octets.
L'identité vérifie la correspondance certificat/clé ; le backend ne cherche aucun
fichier ni URL. Le script choisit les fixtures. Une application réelle fournit
ses propres credentials et sa politique de confiance.

`PendingSignature` réserve la capacité de `/Contents` et de `/ByteRange`.
`SignatureField` l'associe à `/V` ; `AcroForm` référence le champ. Une apparence
visible est un objet séparé et n'est pas nécessaire à cette association.

`SignatureWriter::prepare()` écrit le fichier puis fixe ByteRange avant le calcul
cryptographique. Les plages couvrent la révision entière, sauf le token Contents
avec ses délimiteurs. `PreparedSignature::sign()` signe ces octets et remplit
uniquement la réservation. Une signature trop grande est refusée ; les offsets
et la longueur du fichier restent fixes.

`SignatureBackend` permet de choisir un autre composant. Les trois formats ISO
ont leurs obligations propres. `prepareRevision()` peut signer une révision
incrémentale en conservant les octets de la première signature.

Le backend PHP OpenSSL utilise SHA-256 pour les messages CMS et SHA-1 pour le
format PKCS#1 proposé. Son API ne permet pas de choisir tous les condensats
énumérés par la norme. Les paramètres de la clé et la version PDF effective sont
contrôlés : ce backend CMS demande PDF 1.6 ; une clé RSA de plus de 1 024 bits
demande au moins PDF 1.5. Le catalogue peut relever la version du header.

Les seeds obligatoires et les verrous de champs nécessitent une orchestration
dédiée encore à terminer. La signature les refuse explicitement au lieu de
les ignorer. Les objets de politique existent, mais ne prouvent pas à eux seuls
que les modifications d'une révision respectent DocMDP ou FieldMDP.

La vérification mathématique et la confiance sont distinctes. Certificats,
révocation, horodatage et permissions des révisions demandent leurs contrôles.
La CI vérifie ByteRange, CMS, condensats et signatures RSA indépendamment,
et rejette un message modifié.

Référence : ISO 12.8, pages physiques 474–487.

[Index](README.md) · [Code complet](../../examples/signed-document.php)
