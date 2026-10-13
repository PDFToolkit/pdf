# 17. Couleurs, motifs et sept types de dégradés

Le [script complet](../../examples/colours-patterns.php) présente sur deux pages
des espaces couleur, des motifs répétitifs et les sept types de shading ISO.

```sh
php examples/colours-patterns.php colours-patterns.pdf
```

L'espace couleur définit le sens des composantes. `CalGray`, `CalRGB` et `Lab`
prennent des paramètres calibrés ; `ICCBased` reçoit un profil explicite et son
nombre de composantes. `Indexed` possède une palette. `Separation` et `DeviceN`
associent les colorants à une transformation vers un espace alternatif.
Le core ne génère ni n'analyse un profil ICC externe.

`TilingPattern` définit une cellule, sa `BBox`, les pas de répétition et ses
ressources. Un motif coloré peint ses couleurs. Un motif non coloré est un
stencil dont la couleur est choisie à l'emploi. La validation suit les
dépendances pour refuser les opérations de couleur incompatibles.

Les shadings 1, 2 et 3 utilisent une fonction, un axe ou deux cercles. Les types
4 et 5 décrivent des triangles ; 6 et 7 des patches de Coons ou tensoriels.
Le format binaire des maillages fixe coordonnées et composantes quantifiées,
bits de poids fort en premier, flags de reprise et `/Decode` explicite.
Ces objets produisent réellement les octets du maillage.

`ShadingPattern` transforme un shading en ressource de motif. Les matrices
placent les vignettes dans la page en conservant l'identité des ressources.
Modifier points, couleurs ou bits du format change le résultat et sa précision.

La CI vérifie les sept dictionnaires, les flux et des vecteurs de continuation
de patches. Le profil de test possède sa propre provenance dans les fixtures.

Référence : ISO 8.6–8.7, pages physiques 146–215.

[Index](README.md) · [Code complet](../../examples/colours-patterns.php)
