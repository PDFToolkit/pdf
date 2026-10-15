# Préparation des codes-barres et apparences natives

[English](README.md) | Français

## Source et procédure exécutée

Le [supplément Adobe conservé](../reference-documents/adobe_supplement_iso32000.pdf),
paragraphe 8.6.3, pages physiques 45–47, décrit les champs texte codes-barres et
la procédure reliant `V`, `DataPrep`, widgets, `PMD` et apparence normale. Le
[registre de couverture](coverage.json) conserve son identité exacte et le
périmètre exécuté. Le PDF source reste inchangé.

`BarcodeMessagePartition` conserve les octets déjà présents dans la chaîne
fournie. Il ne réencode pas le texte et ne retire pas son BOM. Avec `DataPrep=1`,
il compresse d’abord **toute la valeur une seule fois**, en Flate encadré par zlib
sans prédicteur d’image, puis divise ces octets préparés. Chaque widget reçoit
un message dans l’ordre. Leurs tailles diffèrent d’au plus un octet ; les premiers
reçoivent le reste. Chaque partie expose index commençant à zéro, total et offset.
Ces parties binaires peuvent couper une séquence d’encodage : elles ne constituent
pas de nouvelles chaînes de texte PDF.

Une valeur explicitement vide produit toujours un message par widget. Sa
représentation compressée est un flux zlib complet ordinaire. Aucune partie
n’est supprimée lorsque les widgets sont plus nombreux que les octets préparés.
Un `V` absent exige une décision de l’application au lieu de devenir implicitement
une valeur vide.

## Construction objet explicite

```php
$field = new BarcodeField(
    new PartialFieldName(new TextString('barcode')),
    new BarcodeDataPreparation(new IntegerType(1)),
    new TextString('Valeur choisie par l’application'),
);
$plan = new BarcodeFieldPreparation(
    $field,
    new BarcodeLimits(),
    new BarcodeWidgetParameters($firstRectangle, $firstPmd),
    new BarcodeWidgetParameters($secondRectangle, $secondPmd),
);
// $encoder est une implémentation BarcodeImageEncoder choisie explicitement.
$rendered = $plan->render($encoder);
foreach ($rendered as $rendering) {
    $field->addWidget($rendering->widget);
}
```

Les rectangles et objets `BarcodePaperMetadata` sont fournis explicitement.
Les requêtes lient les véritables octets de `V` à ces objets PMD dans cet ordre ;
les mêmes PMD sont inscrits dans les widgets produits. Les paramètres PMD gérés
par le constructeur, dont les options `ECC`/`XSymHeight` omises, ne peuvent pas
être remplacés par des `Entry` génériques. Les légendes restent des entrées
optionnelles explicites.
Une valeur encodée explicitement et héritée du véritable ancêtre du champ est
résolue par le modèle natif ; aucune valeur n’est déduite de la légende.

Le rendu renvoie des `BarcodeWidgetAppearance` sans les attacher ni modifier le
champ. Attacher les widgets dans l’ordre renvoyé, les lier à leur vraie page et
les inclure dans `Annots` et les `Kids` du champ avec les constructeurs habituels.
Le catalogue doit déclarer Adobe 1.7 extension level 3 ou ultérieur. Les valeurs
par défaut et règles ordinaires d’appartenance des champs/pages restent applicables.

## Capacité d’encodage et images acceptées

`BarcodeImageEncoder::encode(BarcodeEncodingRequest): ImageXObject` est une
capacité applicative explicite. La requête expose message, rectangle, PMD et
budgets. PDF417, QR Code ou Data Matrix, leur capacité, correction d’erreur,
géométrie des modules, zones de silence et protocole de lecture multi-symboles
relèvent de cet encodeur. Aucun symbole ni image de repli n’est fabriqué en cas
d’échec ; déclarer une symbologie PMD ne prouve pas un résultat lisible au scanner.

L’adaptateur natif accepte des images opaques normalisées en couleurs device.
Masques, images alternatives, contenu optionnel et graphes annexes de ressources
d’image restent hors contrat. Pour chaque image acceptée, il construit un
`FormAppearanceStream` et un `WidgetAppearance` natifs : `BBox=[0 0 largeur hauteur]`
local, `q`, matrice largeur/hauteur, `Do` puis `Q`. L’image remplit le rectangle
entier, sans marge ajoutée ni contrôle de zone de silence. Les étendues positives
sont calculées après normalisation des coins ; les coordonnées inversées sont acceptées.

## Budgets et cycle de vie du champ

`BarcodeLimits` borne les octets d’entrée/préparés et le nombre de widgets avant
partition ou appel de l’encodeur. Il borne aussi les pixels et octets bruts
d’échantillons cumulés de toutes les images renvoyées ; l’épuisement interrompt
avant l’appel suivant. La capacité externe doit borner son propre travail et
ses allocations dans `encode()`. Les filtres PDF et l’encodeur choisi sont des
capacités applicatives de confiance, pas un bac à sable pour du code arbitraire.

Produire une apparence exige des étendues normalisées finies et positives. Cette
opération refuse une aire nulle sans invalider universellement les rectangles PDF
d’aire nulle. Le plan porte sur un champ natif neuf sans widgets attachés. Changer
la valeur exige un nouveau champ/plan ; aucun calcul JavaScript ni observation
automatique des saisies n’est exécuté.

## Représentation des widgets et notes Acrobat

La page 46 permet de fusionner un widget unique avec son champ ; la page 47
décrit cette représentation fusionnée dans la procédure. Les deux représentations
légales restent possibles : `addWidget()` pour un widget unique séparé ou
`mergeWidget()` explicite pour le fusionner. Plusieurs widgets restent des
enfants séparés ordonnés. Cette rédaction n’impose pas une fusion universelle.

La note d’implémentation E-7, page physique 131, décrit la limite de 255 widgets
d’Acrobat 9 ainsi que des flags et un fond spécifiques. Le budget par défaut est
255, configurable ; il n’impose pas une cardinalité PDF universelle. Ces flags,
fond ou légende déduite du système de fichiers ne sont pas ajoutés implicitement.
La note E-6, page physique 130, décrit la prise en charge des anciens lecteurs,
sans fournir d’algorithme d’encodage de code-barres.

## Preuves et limites

Les tests dédiés exercent données binaires/BOM exactes, ordre compression puis
partition, parties vides, ordre des widgets, identité PMD, rectangles inversés ou
dégénérés, budgets, échecs d’encodage et widgets séparés/fusionnés. Lancer l’oracle :

```sh
python tools/validate_barcode_appearances.py --php php --qpdf qpdf --pdftoppm pdftoppm --work-dir /chemin/de/sortie
```

Il produit cinq PDF et dix widgets, vérifie les vrais `V`, PMD, `Kids`, `AP.N`,
octets d’image et flux zlib complets avec pypdf/Python. qpdf contrôle la syntaxe ;
Poppler réalise 45 vérifications de pixels sur les cinq pages. L’image est une
**mire de test deux par deux, pas un code-barres**, annoncée comme telle. Cela
prouve préparation, association et placement PDF, sans prouver de symbologie
ni de lecture effective au scanner.
