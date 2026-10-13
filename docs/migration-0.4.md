# Migration vers le core 0.4

PHP 8.3 reste la version minimale, avec une CI 8.3, 8.4 et 8.5. Le core garde
ses trois extensions de production et ne dépend d'aucun module optionnel.
Les modules 0.1.2 acceptent les séries 0.2, 0.3 et 0.4 du core.

Les cinq dernières fabriques statiques sont remplacées par des constructions
explicites. Les valeurs et les enfants ont leurs objets propres.

| Ancienne construction | Construction explicite |
| --- | --- |
| `Matrix::identity()` | `new Matrix()` |
| `LineDashPattern::solid()` | `new LineDashPattern()` |
| `NameTree::branch($a, $b)` | `new NameTree(new NameTreeChildren($a, $b))` |
| `NumberTree::branch($a, $b)` | `new NumberTree(new NumberTreeChildren($a, $b))` |
| `HexadecimalString::fromHex($digits)` | `new HexadecimalString(new HexadecimalDigits($digits))` |

`HexadecimalString($bytes)` continue de recevoir des octets bruts. La forme avec
`HexadecimalDigits` reçoit des chiffres hexadécimaux : ces deux contrats sont
distincts. Les branches d'arbres vérifient leurs limites et ne mélangent pas
enfants et paires de feuilles dans un même nœud.

`TextString` sélectionne PDFDocEncoding lorsque le texte est représentable,
puis UTF-16BE sinon. `PDFDocEncodedString` impose PDFDocEncoding ; une
`LiteralString` peut recevoir des octets UTF-16BE explicitement préfixés par le
BOM. `PdfDocEncodedPassword` conserve son contrat d'octets explicites
pour les algorithmes historiques ; encoder du texte avec `PDFDocEncoding` avant
de le fournir si nécessaire.

Les fontes ont des programmes, métriques, encodages et options explicites.
Une identité de sous-ensemble utilise `SubsetFontName`, avec `FontSubsetTag` et
le nom original. Le writer partage un registre par fichier ; un simple nom
imitant le préfixe `ABCDEF+` ne remplace pas cet objet.

Les `SampleLayout` peuvent maintenant déclarer d'autres profondeurs et espaces
couleur. Les convertisseurs existants continuent de fournir les mêmes échantillons
de huit bits. Pour traiter un layout générique, utiliser ses méthodes `space()`
et `components()` au lieu de supposer que sa propriété est toujours un enum.

Les writers contrôlent le catalogue, les pages effectives et les composants
atteignables, ainsi que les versions connues des fonctionnalités sélectionnées.
`Catalog.Version` peut relever la version annoncée dans le header. Un nouvel
objet doit donc respecter le contexte documentaire où il est réellement écrit.

`FileWriter::revision()` conserve désormais l'opération de chiffrement. Une
mise à jour chiffrée reprend cette opération et protège l'identité de Encrypt.
Les signatures et la linéarisation ont des writers séparés et des états propres
au document. Aucun registre global ni recherche implicite de fichiers n'est ajouté.

[Couverture](coverage.md) · [Architecture](architecture.md) · [Versions](../CHANGELOG.md)
