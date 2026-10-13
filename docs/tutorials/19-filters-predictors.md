# 19. Prédire les lignes avant la compression

Le [script complet](../../examples/filters-predictors.php) dessine trois fois les
mêmes pixels RGB : Flate/Paeth, LZW/TIFF et LZW/PNG adaptatif.

```sh
php examples/filters-predictors.php filters-predictors.pdf
```

Les octets sont des échantillons PDF, trois composantes de huit bits par pixel,
ligne après ligne. Ils ne constituent pas un fichier PNG ou TIFF.
`PredictorLayout` exprime `Colors`, `Columns` et `BitsPerComponent`.

TIFF soustrait l'échantillon précédent de la même composante, y compris aux
profondeurs 1, 2, 4 et 16 bits. PNG travaille sur des octets et préfixe chaque
ligne avec le filtre choisi. Paeth utilise les voisins gauche, supérieur et
supérieur gauche ; le choix adaptatif examine les possibilités par ligne.

`FlateDecode` ou `LZWDecode` compose la prédiction avant compression.
`StreamObject` utilise ces mêmes objets pour écrire `/Filter`, `/DecodeParms`
et la longueur. LZW agrandit ses codes de 9 à 12 bits puis réinitialise son
dictionnaire. `EarlyChange` choisit les frontières selon les deux conventions
permises ; les pixels décompressés restent identiques.

Changer la profondeur exige d'adapter données, empaquetage et dictionnaire image
ensemble. Changer seulement `BitsPerComponent` ne convertit pas les octets.
La CI compare un grand corpus à un oracle indépendant et à qpdf : changements
de largeur, resets, profondeurs et lignes partielles.

Référence : ISO 7.4.4, pages physiques 28–33.

[Index](README.md) · [Code complet](../../examples/filters-predictors.php)
