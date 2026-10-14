# Public cryptographic test fixtures

`keys.json` contains synthetic, publicly distributed private keys and self-signed
certificates for the complete ISO/TS 32002 curve/digest matrix and RSA SHA-3 tests.
They assert no trusted identity and must never be used for production signatures.
The certificates were created with Python cryptography 50.0.2; fixed EC and EdDSA
test seeds are derived from the string `PDFToolkit public fixture`, while the RSA
test key was generated once. There are no production credentials in this file.

The independent oracle verifies the resulting CMS with cryptography and
asn1crypto. EdDSA explicitly selects an OpenSSL executable because PHP 8.3's
`openssl_sign` API cannot perform these operations. Native RSA/ECDSA stay in
process. Core users may supply another explicit cryptographic provider.

`timestamp.json` contains a separate public test TSA identity with a critical
`id-kp-timeStamping` extended key usage. It is self-signed and establishes no
trusted time or public identity. The offline RFC 3161 fixtures explicitly select
OpenSSL and an authority-scoped serial source; real authorities require a durable
serial allocator and an independently accepted time and trust policy.

## Jeux d'essai cryptographiques publics

`keys.json` contient des clés privées synthétiques publiques et des certificats
autosignés pour la matrice complète courbes/empreintes d'ISO/TS 32002 et les tests
RSA SHA-3. Ils ne prouvent aucune identité de confiance et ne doivent jamais servir
à signer des documents de production. Les certificats ont été créés avec Python
cryptography 50.0.2 ; les graines fixes EC et EdDSA proviennent de la chaîne
`PDFToolkit public fixture`, et la clé RSA de test a été générée une seule fois.
Ce fichier ne contient aucun identifiant de production.

L'oracle indépendant vérifie le CMS obtenu avec cryptography et asn1crypto.
EdDSA sélectionne explicitement un exécutable OpenSSL, car l'API
`openssl_sign` de PHP 8.3 ne sait pas effectuer ces opérations. RSA/ECDSA restent
dans le processus PHP. Le core accepte un autre fournisseur cryptographique
explicitement choisi par l'application.

`timestamp.json` contient une identité TSA d'essai publique distincte, avec l'usage
étendu critique `id-kp-timeStamping`. Son certificat autosigné ne prouve aucune
heure ni identité de confiance. Les tests RFC 3161 hors ligne sélectionnent
explicitement OpenSSL et une source de numéros de série propre à l'autorité ; une
autorité réelle exige une allocation persistante et une politique indépendante
de confiance et de temps.
