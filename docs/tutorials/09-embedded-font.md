# 09 — Incorporer une fonte TrueType et conserver Unicode

Ce tutoriel correspond à [`examples/embedded-font.php`](../../examples/embedded-font.php).
Il construit une police composite `/Type0` utilisant `/Identity-H`, une fonte
descendante `/CIDFontType2` et tous les glyphes TrueType de Noto Sans.
Le PDF présente du français et du grec ; le lecteur peut sélectionner et
extraire les caractères d'origine grâce au flux `/ToUnicode`.

La fonte de démonstration et sa licence SIL Open Font License sont conservées
dans [`examples/assets/fonts`](../../examples/assets/fonts/README.md).

## Exécuter l'exemple

Depuis la racine du dépôt, après `composer install` :

```sh
php examples/embedded-font.php embedded-font.pdf
```

Le chemin de sortie est facultatif ; par défaut le script écrit dans
`examples/embedded-font.pdf`. La police Noto Sans et sa licence sont déjà
présentes dans le clone. Le lecteur peut rechercher et copier le français
et le grec grâce aux correspondances Unicode construites par le script.

## 1. Le programme de fonte est un objet distinct

L'application lit le fichier et donne ses **octets** à
[`FontProgram`](../../src/Document/Fonts/TrueType/FontProgram.php).
Le modèle ne lit aucun chemin et ne contacte aucune URL. Le programme conserve
les octets complets, son nom PostScript, son `cmap`, ses métriques horizontales
et les boîtes de ses glyphes.

```php
$program = new FontProgram($fontBytes);
$heading = new UnicodeText('Une police incorporée, objet par objet.');
$body = new UnicodeText('Français : été, cœur, Noël. Ελληνικά : Αθήνα.');
```

Le budget d'entrée est un objet explicite
[`FontProgramLimits`](../../src/Document/Fonts/TrueType/FontProgramLimits.php),
par défaut 32 MiB. L'application peut imposer une limite plus basse, par exemple :

```php
$program = new FontProgram($fontBytes, new FontProgramLimits(maxBytes: 1048576));
```

[`UnicodeText`](../../src/Document/Fonts/TrueType/UnicodeText.php) valide UTF-8
et conserve une liste de valeurs scalaires Unicode. Il ne normalise pas le
texte et ne transforme pas sa direction ou sa composition typographique.

Les unités de la fonte ne sont pas directement les unités du dictionnaire PDF.
Une avance de 1 024 dans une fonte à 2 048 unités par em devient une largeur
PDF de 500. Les largeurs fractionnaires sont conservées. Le parseur respecte
aussi `hmtx` lorsqu'un dernier enregistrement d'avance s'applique à plusieurs
glyphes.

## 2. Déclarer le répertoire avant de construire le graphe

[`CharacterMap`](../../src/Document/Fonts/TrueType/CharacterMap.php) reçoit le
programme et tous les caractères qui seront encodés avec cette instance.
Chaque caractère Unicode distinct obtient un CID distinct, à partir de 1 ;
le CID 0 est réservé au glyphe `.notdef`. Une répétition du même caractère
réutilise son CID. Deux caractères partageant le même glyphe gardent deux CID
pour conserver leur identité lors de l'extraction.

```php
$map = new CharacterMap($program, new UnicodeText($heading->utf8 . $body->utf8));
$encoder = new TextEncoder($map);
```

Un caractère absent de la fonte provoque une exception à la construction du
répertoire. Un caractère existant mais absent du répertoire provoque une
exception à l'encodage. Il n'y a ni ajout implicite, ni remplacement silencieux
par `.notdef`, ni état partagé entre les documents.

## 3. Assembler les objets définis par PDF

[`CIDFontProgram`](../../src/Document/Fonts/TrueType/CIDFontProgram.php) prépare
le programme destiné à une fonte CID. ISO 32000-1, 9.9, exige de retirer la table
`cmap` d'un programme TrueType incorporé par `/FontFile2` pour une fonte CID :
la correspondance vers les glyphes vient de `/CIDToGIDMap`. Tous les glyphes
et toutes les autres tables sont conservés, à l'exception d'une éventuelle
signature `DSIG` devenue invalide après transformation. Le répertoire sfnt,
ses offsets, ses checksums et `checksumAdjustment` sont recalculés.

Ce programme est incorporé par
[`FontFile2`](../../src/Document/Fonts/TrueType/FontFile2.php), avec son `/Length1`
non compressé. Le filtre `/FlateDecode` compresse le flux sans modifier le
programme CID qui sera restitué après décodage.

```php
$cidProgram = new CIDFontProgram($program);
$fontFile = new FontFile2($cidProgram, new FlateDecode());
$descriptor = new FontDescriptor(
    $fontFile,
    new FontDescriptorFlags(4),
    new NumericType(80),
);
$glyphMap = new CIDToGIDMap($map, new FlateDecode());
$widths = new CIDWidths($map);
$descendant = new CIDFontType2($descriptor, $glyphMap, $widths);
$toUnicode = new ToUnicodeCMap($map, new FlateDecode());
$font = new Type0Font($descendant, $toUnicode);
```

Les responsabilités sont séparées :

| Objet | Entrée ou rôle PDF |
| --- | --- |
| `CIDFontProgram` | Programme TrueType à tous ses glyphes, préparé pour une fonte CID |
| `FontFile2` | Programme CID TrueType incorporé et `/Length1` |
| `FontDescriptor` | Nom, boîte, ascension, descente, angle, flags et `/FontFile2` |
| `CIDToGIDMap` | Correspondance CID → index de glyphe, en mots big-endian de deux octets |
| `CIDWidths` | `/W`, exprimé dans les 1 000 unités du système de glyphes PDF |
| `CIDFontType2` | Fonte descendante, métriques et correspondance vers les glyphes |
| `ToUnicodeCMap` | Correspondance code de caractère → UTF-16BE pour l'extraction |
| `Type0Font` | Police composite `/Identity-H` qui relie la fonte descendante et `/ToUnicode` |

`StemV` est explicitement fourni : 80 unités PDF dans cet exemple. TrueType ne
définit pas de champ universel donnant l'épaisseur des fûts verticaux. La valeur
ne résulte donc pas d'une estimation cachée du constructeur. `CapHeight` vient
de `OS/2` ou de la boîte du glyphe `H` ; lorsqu'elle n'est pas disponible, le
constructeur exige une valeur explicite. Les flags sont aussi explicites :
4 correspond au bit `Symbolic` de la table 123, adapté à ce répertoire comprenant
du grec. Les bits `Symbolic` et `Nonsymbolic` ne peuvent pas être combinés.

Les objets vérifient qu'ils utilisent **la même instance de programme et de
répertoire**. Les entrées qu'ils déterminent (`/Encoding`, `/FontFile2`, `/W`,
`/ToUnicode`, etc.) ne peuvent être remplacées ou supprimées avec `with()` ou
`without()`. Des entrées d'extension restent des objets explicites et immuables.

## 4. Encoder la chaîne présentée à Tj

`/Identity-H` prend un code de caractère sur deux octets. Ce code est ici le
CID attribué dans le répertoire, pas le point de code Unicode et pas le GID.

```php
new ShowText($encoder->encode($heading));
```

[`TextEncoder`](../../src/Document/Fonts/TrueType/TextEncoder.php) retourne un
`HexadecimalString`. Les objets `Tf`, `Td`, `Tj`, `/Resources`, `/Page`, `/Pages`
et `/Catalog` s'assemblent ensuite comme dans les premiers tutoriels. Aucun
moteur de mise en page ne s'intercale dans ce graphe.

Le flux `/ToUnicode` convertit les codes vers UTF-16BE. Les caractères hors du
plan multilingue de base utilisent une paire de substituts. Les blocs
`beginbfchar` contiennent au plus 100 correspondances, conformément à la
syntaxe CMap utilisée par la norme.

## 5. Limites explicites de cette première incorporation

Cette implémentation incorpore **tous les glyphes de la fonte** ; elle ne produit
pas de sous-ensemble. Le binaire sfnt est reconstruit pour les contraintes de
la fonte CID décrites ci-dessus. Elle accepte un programme sfnt TrueType statique avec contours
`glyf`, `cmap` Unicode format 4 ou 12 et métriques horizontales. Les fontes CFF,
CFF2, variables, les collections TTC, WOFF et WOFF2 sont refusées. Les séquences
de variation Unicode, le shaping OpenType, le crénage automatique, les
ligatures automatiques et la composition bidirectionnelle restent à construire.
Les caractères sont présentés dans l'ordre fourni à l'encodeur.

Les tables, segments, groupes, métriques, délimitations `loca` et structures
des contours sont bornés. Les références des glyphes composés sont vérifiées,
ainsi que leurs cycles et leur profondeur. Les limites sont par défaut 32 MiB par fonte,
256 tables, 65 535 références de glyphes composés, 1 024 composants par glyphe,
une profondeur de 64 et 1 MiB par objet `UnicodeText`. `/Identity-H` permet
65 535 caractères distincts dans le répertoire, en plus du CID 0.
Les lecteurs de tables utilisent des vues bornées sur les octets du programme,
sans copie de chaque table ou sous-table. Les groupes de `cmap` format 12 et le graphe des glyphes restent en stockage
binaire compact ; leur nombre ne multiplie pas les allocations de tableaux PHP.
La limite d'octets porte sur le programme d'entrée, pas sur le tas du processus :
l'application doit aussi prévoir son budget mémoire pour le graphe et l'écriture.

Les instructions TrueType sont conservées comme données ; ce projet ne les
exécute pas et n'est pas un moteur de fontes ni un assainisseur de toutes les
tables OpenType. Une application qui accepte des fontes externes doit choisir
sa politique de confiance et, si nécessaire, un assainissement spécialisé avant
de fournir les octets au modèle.

Le champ `OS/2.fsType` est contrôlé : les fontes à incorporation restreinte ou
réservées aux bitmaps sont refusées. L'interdiction de sous-ensemble est respectée
par l'incorporation complète. Les modes `Preview & Print` et `Editable` permettent
l'incorporation et restent exposés dans `embeddingRights` ; l'application doit
respecter les usages autorisés et la licence de la fonte. Ce champ ne remplace
pas la licence de redistribution des fichiers de fonte.

## Références

- ISO 32000-1:2008, 9.7.4, 9.7.6, 9.8, 9.9 et 9.10.3 ; tables 117, 121–123, 126 et 127.
- [Microsoft OpenType : cmap](https://learn.microsoft.com/en-us/typography/opentype/spec/cmap).
- [Microsoft OpenType : métriques horizontales](https://learn.microsoft.com/en-us/typography/opentype/spec/hmtx).
- [Microsoft OpenType : OS/2 et droits d'incorporation](https://learn.microsoft.com/en-us/typography/opentype/spec/os2).
- [Microsoft OpenType : glyf](https://learn.microsoft.com/en-us/typography/opentype/spec/glyf).

[← 08. Objets compressés](08-object-stream.md) · [Tous les tutoriels](README.md) · [10. Images JPEG et PNG →](10-image-files.md)
