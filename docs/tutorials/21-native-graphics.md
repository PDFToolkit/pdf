# 21. Images, transparence et calques explicites

Le [script complet](../../examples/native-graphics.php) produit deux pages avec
sept images, des masques, un groupe de transparence et des calques. Les données
sont des échantillons PDF construits directement, sans fichier image externe.

```sh
php examples/native-graphics.php native-graphics.pdf
```

`SampleLayout` donne l'espace couleur et la profondeur. Aux profondeurs 1, 2 et
4 bits, les composantes sont empaquetées de poids fort en premier ; les lignes
sont arrondies séparément à l'octet. À 16 bits, chaque composante occupe deux
octets. `ImageSamples` vérifie la longueur contre ces dimensions.

`Decode` transforme les valeurs des échantillons en composantes. Un stencil
`ImageMask` utilise une profondeur d'un bit et la couleur courante. Un masque
par intervalle rend transparents les pixels de certaines valeurs ; un masque
explicite ou un `/SMask` fournit une autre image. `Matte` décrit une couleur
précomposée qui doit être prise en compte pour retrouver les couleurs originales.

Un XObject Form possède ses coordonnées, sa `BBox`, sa matrice et ses ressources.
`Group` déclare un groupe de transparence, son espace de mélange et ses options
d'isolation. `GraphicsStateParameters` compose les paramètres ExtGState tels
qu'alpha, mode de mélange et masque de luminosité. Le dessin choisit ensuite
cette ressource par l'opérateur `gs`.

`OptionalContentGroup` donne une identité à un calque. `OCProperties` énumère
les groupes du document et sa configuration initiale. `OCMD` et les expressions
de visibilité combinent leurs états avec And, Or et Not. Le writer vérifie les
groupes réellement atteints, y compris dans les images et les Forms.

Modifier les échantillons, Decode, l'alpha ou la configuration des calques permet
de comparer ces mécanismes. Le lecteur choisit leur rendu ; le core fournit
leurs objets et des opérations numériques testées. Les seuils de halftone
illustrés sont des données PDF, sans conversion d'une image externe.

La CI vérifie les images, flux de seuils, ressources et expressions de visibilité,
ainsi que des vecteurs indépendants de mélange RGB et CMYK.

Référence : ISO 8.8–8.11 et 11, pages physiques 215–261 et 321–380.

[Index](README.md) · [Code complet](../../examples/native-graphics.php)
