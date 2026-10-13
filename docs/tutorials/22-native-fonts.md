# 22. Fontes simples, Type3 et écriture verticale

Le [script complet](../../examples/native-fonts.php) réunit des glyphes Type3
dessinés avec les opérateurs PDF, une police simple incorporée et une fonte
composite dont les métriques placent les glyphes verticalement.

```sh
php examples/native-fonts.php native-fonts.pdf
```

Une fonte Type3 contient des `CharProcs`, chacun étant un flux de dessin.
`d0` ou `d1` déclare sa largeur et, selon le cas, sa boîte. `Type3CharProcs`,
`EncodingDifferences` et `SimpleWidths` relient noms, codes et métriques. La
matrice du font transforme les coordonnées du glyphe vers l'espace textuel.
Le programme de dessin ne devient pas un moteur de mise en page.

Une fonte simple utilise des codes d'un octet, `FirstChar`, `LastChar` et les
largeurs correspondantes. Son `FontDescriptor` décrit les métriques et référence
un programme incorporé. `FontFile`, `FontFile2` et `FontFile3` ont des formats et
longueurs distincts ; le programme est fourni normalisé. Le script utilise le
module optionnel pour préparer les données TrueType, puis les compose dans
les objets PDF du core.

Une fonte composite utilise des CID. `DW`/`W` donne les largeurs horizontales ;
`DW2`/`W2` fournit déplacement et position d'origine verticale. Leur représentation
en plages ne développe pas tous les CID en mémoire. L'encodage choisit le mode
vertical ; `/ToUnicode` conserve les caractères pour l'extraction.

Les noms de sous-ensembles ont leur objet `SubsetFontName` et un tag explicite.
Le writer partage un registre par document et rejette une collision entre deux
programmes distincts. Le core ne découpe pas automatiquement les programmes ni
ne réalise le shaping des écritures complexes.

Modifier les glyphes Type3 ou les métriques verticales permet de voir directement
leur rôle. La CI vérifie encodages, programmes, CID/GID et texte extrait avec
pypdf et Poppler. Certains lecteurs classent le FontFile3/OpenType à contours
glyf comme un TrueType ; cette déclaration est permise par la table 126 et
le rendu de l'exemple est vérifié séparément.

Référence : ISO 9.5–9.10, tables 110–127, pages physiques 261–303 ; annexe D.

[Index](README.md) · [Code complet](../../examples/native-fonts.php)
