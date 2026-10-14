# Fixtures indépendantes du chiffrement historique Adobe R=5

[English](README.md) | Français

Ces deux PDF ont été chiffrés par qpdf 12.4.2 avec `--force-R5` depuis le document
MIT créé par `tools/fixtures/adobe_encryption.php`. Leurs mots de passe publics
de test sont `user` et `owner`. L'un chiffre le flux de métadonnées ; l'autre
utilise `--cleartext-metadata`.

`qpdf-r5.json` enregistre pour chaque PDF immuable le SHA-256, chaînes d'octets
du dictionnaire de chiffrement, permissions, flag de métadonnées et clé de
fichier récupérée par qpdf. Les tests PHP s'authentifient sur ces valeurs
indépendantes. Le validateur Python vérifie les deux fichiers avec qpdf et
quatre PDF écrits par cette bibliothèque avec qpdf et pypdf.

Exécuter `python tools/validate_adobe_encryption.py` pour vérifier les fixtures.
`--build-fixtures` les remplace explicitement par de nouveaux vecteurs qpdf
aléatoires et leurs empreintes ; la validation ordinaire ne les réécrit jamais.
Ces exemples exercent le protocole historique de `adobe_supplement_iso32000.pdf`,
pages PDF 16–21. R=5 est déprécié ; ce n'est pas le choix par défaut du writer.

`qpdf-legacy-key-derivation.json` et quatre PDF supplémentaires conservent les
clés récupérées indépendamment par qpdf pour R=2/40 bits, R=3/128 bits et
R=4/128 bits avec métadonnées chiffrées ou claires. Ils testent seulement la
dérivation MD5 de l'algorithme 3.2 du supplément, avec le padding de mot de
passe hérité de l'algorithme 2 d'ISO 32000-1. Ils n'activent ni writer chiffré
historique ni authentificateur de mot de passe. Le validateur vérifie aussi
padding, troncature, permissions little-endian et chaque longueur d'octets
prise en charge face à un oracle Python `hashlib`.

`public-key-recipient-1.der` et `public-key-recipient-2.der` sont des enveloppes
CMS opaques produites indépendamment par OpenSSL 3.2.3 avec
`cms -encrypt -binary -aes-256-cbc -outform DER`, deux certificats publics de
test et le seed non secret `00..13` suivi de quatre octets de permissions.
`public-key-sha256.json` enregistre leurs empreintes et les SHA-256 Python pour
les deux ordres de destinataires et flags de métadonnées. Le core reçoit ces
chaînes comme objets explicites et les hache ; il ne traite pas ASN.1, CMS,
certificats de destinataires ni clés privées. Les clés privées et certificats
de ces fixtures ne sont pas inclus dans le dépôt.
