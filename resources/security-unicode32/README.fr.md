# Données SASLprep Unicode 3.2 figées

[English](README.md) | Français

RFC 4013 utilise les tables et la normalisation Unicode **3.2.0** de RFC 3454.
Le supplément Adobe exige ce profil exact avant de tronquer un mot de passe
révision 5 à 127 octets UTF-8. `Unicode32SaslprepData` est un objet PHP généré
immuable ; le code de production ne charge pas de JSON, ne dépend pas d'ICU
et n'utilise pas la version Unicode actuelle de l'hôte.

`unicode-3.2-saslprep.json` contient les données équivalentes de maintenance,
générées par `tools/build_saslprep_tables.py` à partir de la base de normalisation
Python documentée `unicodedata.ucd_3_2_0` et des plages explicites du fichier
`rfc3454.txt` conservé. La classification ne dépend pas de l'implémentation
Python `stringprep` installée. Le générateur conserve les corrections historiques
de normalisation avec les résultats `normalize('NFKD', ...)` de la base figée.
Les syllabes Hangul algorithmiques sont toujours exclues des tables car le
normaliseur PHP les traite directement ; cela garde la sortie identique entre
versions Python qui exposent ces décompositions différemment.
`python tools/build_saslprep_tables.py --check` reproduit et compare les données
JSON et PHP.

Sources : [RFC 4013](https://www.rfc-editor.org/rfc/rfc4013),
[RFC 3454](https://www.rfc-editor.org/rfc/rfc3454),
[documentation de la base Unicode Python](https://docs.python.org/3/library/unicodedata.html),
[documentation Python stringprep](https://docs.python.org/3/library/stringprep.html)
et [licence des données Unicode](https://www.unicode.org/license.txt).

Les données Unicode dérivées conservent la [licence Unicode](UNICODE-LICENSE.txt).
Le RFC conservé garde ses avis originaux de copyright et permission. Le code
PHP et les tests restent sous la licence MIT du projet.
`tools/validate_adobe_encryption.py` analyse indépendamment les tables du RFC
et compare SASLprep sur 24 744 cas déterministes : chaque syllabe Hangul,
normalisation, correspondances, frontières de tables, interdictions, caractères
non attribués et BIDI.
