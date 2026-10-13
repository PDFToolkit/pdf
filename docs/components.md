# Guide des composants

Ce guide explique les familles d'objets, leurs responsabilités et leur composition. La [référence API exhaustive](https://roukmoute.github.io/pdf/reference/index.html) donne ensuite les signatures de chaque classe, interface, enum, méthode et propriété. Les [tutoriels](tutorials/README.md) montrent des documents complets, leurs instructions PDF et leur résultat visuel ; les [recettes](recipes/README.md) combinent plusieurs familles pour un usage concret.

La documentation publiée depuis `master` suit le code de développement. La dernière version stable est actuellement **0.1.1**. Pour consulter exactement son API, utiliser le [code du tag v0.1.1](https://github.com/roukmoute/pdf/tree/v0.1.1). Pendant la série 0.x, les noms et signatures peuvent évoluer pour améliorer le modèle.

Le format décrit est **PDF 1.7, ISO 32000-1:2008**. La version PHP minimale, PHP 8.3, est indépendante de la version du format PDF. La [couverture de la spécification](specification.md) détaille ce qui est modélisé et ce qui nécessite encore un traitement spécialisé.

## Comment lire le modèle

Un fichier se construit en reliant des objets : une page possède un contenu et des ressources, un arbre possède des pages, un catalogue désigne cet arbre, puis un writer sérialise le graphe. Les numéros d'objets et les positions dans le fichier sont déterminés pendant l'écriture.

| Famille | Question à laquelle elle répond | Point d'entrée |
| --- | --- | --- |
| Valeurs PDF | Quelle valeur est écrite : nombre, nom, chaîne, tableau… ? | `BasicType`, `PDF\Syntax\Object` |
| Dictionnaires et définitions | Quelles entrées décrivent cet objet et quels types sont autorisés ? | `Entry`, `SchemaDictionary`, `PDF\Specification` |
| Identité et fichier | Où vivent les objets indirects et comment le lecteur les retrouve-t-il ? | `IndirectReference`, `FileStructure`, `FileWriter` |
| Flux et filtres | Quels octets sont stockés et comment sont-ils encodés ? | `StreamObject`, `Filter` |
| Instructions de contenu | Que doit dessiner ou afficher le lecteur PDF ? | `ContentStreams`, `Operator` |
| Documents et pages | Comment les pages, ressources et informations sont-elles reliées ? | `DocumentCatalog`, `PageTreeNode`, `PageObject` |
| Navigation | Où mènent les signets, liens et destinations nommées ? | `Destination`, `OutlineItem`, `NameTree` |
| Fonctions | Quelle transformation numérique le lecteur doit-il appliquer ? | `FunctionObject`, `PDF\Functions` |

Les valeurs composent d'autres objets PDF. Un scalaire PHP intervient principalement à une feuille : nombre, booléen, nom ou octets d'une chaîne. L'[architecture](architecture.md) décrit plus précisément les règles de construction.

## 1. Valeurs primitives et conventions lexicales

### Le contrat `BasicType`

[`BasicType`](../src/Syntax/FileStructure/BasicType.php) est le contrat des valeurs PDF. Il expose `toPdf(SerializationContext $context): string` et le contrat PHP `Stringable`. Le contexte permet de sérialiser une valeur qui contient des références et d'empêcher les cycles directs.

L'emplacement du contrat dans `Syntax\FileStructure` ne transforme pas toutes les valeurs en fichiers. Un `NameType` reste un nom, et un `ArrayType` reste un tableau de valeurs. Un document complet s'écrit à travers `FileStructure`.

### Les huit familles d'objets PDF

| Terme PDF | Classes | Rôle et contraintes principales |
| --- | --- | --- |
| Boolean | [`BooleanType`](../src/Syntax/Object/BooleanType.php) | Valeur `true` ou `false`. |
| Numeric | [`NumericType`](../src/Syntax/Object/NumericType.php), [`IntegerType`](../src/Syntax/Object/IntegerType.php), [`RealType`](../src/Syntax/Object/RealType.php) | Valeur numérique finie ; écriture sans notation exponentielle. Les sous-types expriment un integer ou un real lorsque cette distinction est utile. |
| String | [`StringType`](../src/Syntax/Object/StringType.php), [`LiteralString`](../src/Syntax/Object/StringObject/LiteralString.php), [`HexadecimalString`](../src/Syntax/Object/StringObject/HexadecimalString.php) | Suite d'octets représentée par une chaîne littérale ou hexadécimale. |
| Name | [`NameType`](../src/Syntax/Object/NameType.php) | Identifiant PDF sensible à la casse ; le `/` et l'échappement des octets sont produits à l'écriture. |
| Array | [`ArrayType`](../src/Syntax/Object/ArrayType.php) | Suite ordonnée de `BasicType`. |
| Dictionary | [`DictionaryType`](../src/Syntax/Object/DictionaryType.php) | Collection d'entrées nommées ; une clé ne peut pas être déclarée deux fois au constructeur. |
| Stream | [`StreamObject`](../src/Syntax/Object/StreamObject.php) | Dictionnaire accompagné d'octets ; le flux est écrit comme objet indirect. |
| Null | [`NullType`](../src/Syntax/Object/NullType.php) | Valeur PDF `null`, notamment utilisée pour certains paramètres de destination. |

Par exemple, un rectangle PDF contient quatre nombres ; son type dédié exprime cette structure :

```php
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\NumericType;

$mediaBox = new Rectangle(
    new NumericType(0),
    new NumericType(0),
    new NumericType(612),
    new NumericType(792),
);
```

[`Rectangle`](../src/Syntax/DataStructure/Rectangle.php) décrit `[x_min y_min x_max y_max]`, et non `[x y largeur hauteur]`. Les coordonnées du contenu dépendent ensuite de l'espace courant et de ses transformations. Le [tutoriel dessin](tutorials/03-graphics.md) explique cette distinction avec un rendu.

### Octets d'une chaîne et texte Unicode

Une `LiteralString` reçoit des **octets**. Elle échappe les parenthèses, les barres obliques inverses et les caractères nécessaires à la syntaxe. Une `HexadecimalString` reçoit également des octets et produit leur représentation hexadécimale. Ces deux classes ne choisissent pas l'encodage d'une police.

[`TextString`](../src/Syntax/DataStructure/TextString.php) représente le *text string* de la norme : elle accepte de l'UTF-8 valide et le convertit en ASCII ou UTF-16BE avec BOM. Elle convient, par exemple, aux titres documentaires et aux titres de signets.

La chaîne affichée par l'opérateur `Tj` doit correspondre à l'encodage de la police sélectionnée. Passer un `TextString` Unicode à `ShowText` ne fournit donc pas automatiquement une police Unicode ni le codage de ses glyphes. Le [tutoriel pages et texte](tutorials/02-pages-and-text.md) explicite les choix d'encodage des exemples.

[`Date`](../src/Syntax/DataStructure/Date.php) construit une date PDF depuis un `DateTimeImmutable`, en conservant son décalage UTC. Les types de [`LexicalConvention`](../src/Syntax/LexicalConvention) représentent les fins de ligne, espaces blancs et délimiteurs du langage PDF. Ce sont des éléments de syntaxe ; une application compose généralement les objets de niveau supérieur qui les utilisent.

## 2. Entrées, dictionnaires et schémas

### Une entrée est elle-même un objet

[`Entry`](../src/Syntax/Object/DictionaryObject/Entry.php) relie un `NameType` à un `BasicType`. La clé possède sa propre identité de valeur PDF ; la valeur peut être un dictionnaire, un tableau ou une référence.

```php
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

$original = new DictionaryType(
    new Entry(new NameType('ExampleNumber'), new NumericType(12)),
);
$modified = $original->with(
    new Entry(new NameType('ExampleNumber'), new NumericType(24)),
);
```

`with()` remplace ou ajoute l'entrée dans une **nouvelle instance**. `$original` conserve sa valeur 12. `without()` produit aussi une nouvelle instance. Il faut conserver la valeur retournée. `get()` retourne une valeur PDF ; `entries()` permet d'examiner les objets `Entry`.

Le dictionnaire générique accepte des noms arbitraires. Un dictionnaire spécialisé ajoute les contraintes de son rôle : types autorisés, champs structurels calculés ou entrées obligatoires.

### Définition de norme et instance PDF

[`SchemaDictionary`](../src/Specification/SchemaDictionary.php) compose un [`TableDefinition`](../src/Specification/TableDefinition.php). Il vérifie les types des entrées connues au constructeur, à l'ajout par `with()` et pendant `validate()`. La sérialisation appelle `validate()` et vérifie les exigences inconditionnelles.

[`SchemaEntry`](../src/Specification/SchemaEntry.php) compose une [`EntryDefinition`](../src/Specification/EntryDefinition.php). Il vérifie la valeur avant de l'associer à la clé normalisée. Les entrées à clé variable demandent un `NameType` explicite.

Une entrée générique permet une extension explicite. Lorsqu'elle utilise une clé déjà définie, elle reste soumise à la contrainte de cette clé. Les exigences conditionnelles et héritées ainsi que les relations entre objets doivent être traitées par les composants spécialisés et par l'application lorsque leur validation n'est pas encore implémentée.

### Lire et composer les classes générées

Les trois parties d'une table ont des rôles distincts :

| Exemple de classe | Responsabilité |
| --- | --- |
| [`AnnotationDictionaryTable164Definition`](../src/Specification/Definition/AnnotationDictionaryTable164Definition.php) | Définition immuable de la table 164, avec ses sources et contraintes. |
| [`AnnotationDictionaryTable164`](../src/Specification/Dictionary/AnnotationDictionaryTable164.php) | Instance PDF des entrées communes aux annotations. |
| [`SubtypeEntry`](../src/Specification/Dictionary/AnnotationDictionaryTable164/SubtypeEntry.php), [`RectEntry`](../src/Specification/Dictionary/AnnotationDictionaryTable164/RectEntry.php) | Entrées nommées `/Subtype` et `/Rect`, avec leurs types autorisés. |

Un dictionnaire commun d'annotation peut ainsi être construit avec des objets d'entrée :

```php
use PDF\Specification\Dictionary\AnnotationDictionaryTable164;
use PDF\Specification\Dictionary\AnnotationDictionaryTable164\ContentsEntry;
use PDF\Specification\Dictionary\AnnotationDictionaryTable164\RectEntry;
use PDF\Specification\Dictionary\AnnotationDictionaryTable164\SubtypeEntry;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

$annotation = new AnnotationDictionaryTable164(
    new SubtypeEntry(new NameType('Text')),
    new RectEntry(new Rectangle(
        new NumericType(72), new NumericType(600),
        new NumericType(96), new NumericType(624),
    )),
    new ContentsEntry(new TextString('Une note associée à cette page.')),
);
```

Cet extrait construit l'objet ; il faut encore l'insérer, par référence, dans le tableau `/Annots` d'une page pour le rendre accessible au lecteur. Le [tutoriel liens](tutorials/05-links.md) montre cet assemblage complet avec un autre sous-type d'annotation.

La table indiquée dans le nom rattache chaque classe à la norme et distingue des tableaux portant des titres proches. Les dictionnaires d'entrées supplémentaires composent leurs définitions communes : le [`Type2ShadingDictionaryTable80`](../src/Specification/Dictionary/Type2ShadingDictionaryTable80.php) utilise, par exemple, les entrées communes de la table 78 et les entrées spécifiques de la table 80. Le [tutoriel dégradé](tutorials/07-gradient.md) les assemble.

Les 263 dictionnaires et leurs 1 405 classes d'entrée couvrent les tables d'entrées recensées. Les noms PDF restent sensibles à la casse : `/OP` et `/op` sont deux clés différentes, même lorsque les classes PHP doivent employer un suffixe pour conserver cette distinction.

## 3. Références, identité et sérialisation

### Une référence vise une instance

[`IndirectReference`](../src/Syntax/Object/IndirectReference.php) désigne un objet. Deux références vers **la même instance** permettent de partager cet objet dans le fichier. Deux objets de même contenu restent deux instances distinctes ; le writer n'effectue pas de déduplication par comparaison de leurs valeurs.

```php
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Syntax\Object\IndirectReference;

$font = new Type1Font(StandardFont::Helvetica);
$firstUse = new IndirectReference($font);
$secondUse = new IndirectReference($font);
```

Lorsque ces références sont accessibles dans le même graphe de fichier, elles désignent le même numéro d'objet. Ce numéro est attribué pendant l'écriture. La référence peut également recevoir un [`ObjectIdentifier`](../src/Syntax/Object/IndirectObject/ObjectIdentifier.php) explicite, composé d'un [`ObjectNumber`](../src/Syntax/Object/IndirectObject/ObjectNumber.php) et d'un [`GenerationNumber`](../src/Syntax/Object/IndirectObject/GenerationNumber.php).

[`IndirectObject`](../src/Syntax/Object/IndirectObject.php) représente une valeur munie de son identifiant lorsqu'un contrôle explicite du corps du fichier est nécessaire.

### Le contexte appartient à une écriture

[`SerializationContext`](../src/Serialization/SerializationContext.php) tient la correspondance entre instances et identifiants. Il réserve les identifiants explicites avant d'attribuer les identifiants automatiques et contrôle les conflits. Il existe pour une écriture donnée ; aucun registre global ne partage les numéros entre documents.

Les cycles passent par des références indirectes. Ainsi, une page référence son parent et le parent référence ses enfants. Un cycle de valeurs directement imbriquées est refusé. Le contexte vérifie aussi que deux instances ne réclament pas le même numéro et qu'une instance ne réclame pas deux identifiants.

Les objets de graphe doivent être configurés avant l'écriture. Modifier l'arbre pendant sa sérialisation empêcherait d'obtenir un fichier cohérent.

## 4. Fichier, références croisées et objets compressés

[`FileStructure`](../src/FileStructure.php) compose un [`FileHeader`](../src/Syntax/FileStructure/FileHeader.php), un [`FileBody`](../src/Syntax/FileStructure/FileBody.php), la référence de racine et d'éventuelles entrées de trailer. Le header exprime la version PDF ; [`PdfVersion`](../src/Syntax/FileStructure/PdfVersion.php) expose les versions 1.0 à 1.7.

[`FileWriter`](../src/Serialization/FileWriter.php) transforme ce graphe en octets. Il parcourt les références accessibles, écrit les objets et mesure les offsets. Il produit les références croisées et le trailer. `startxref` désigne la position réelle de la section correspondante.

`FileStructure::toPdf()` retourne les octets du fichier. `writeTo()` les écrit dans un chemin de fichier choisi par l'application. Ces opérations construisent le document en mémoire ; la limite de mémoire de l'application reste donc un élément du dimensionnement.

### Révisions et identités historiques

[`FileRevision`](../src/Syntax/FileStructure/FileRevision.php) est l'instantané produit par `FileStructure::toRevision()`. Ses octets restent figés. Ses [`RevisionObject`](../src/Syntax/FileStructure/RevisionObject.php) conservent la valeur effective, les alias d'identité et une copie des entrées du dictionnaire au moment de l'écriture. Un builder mutable n'est donc pas reparcouru lorsqu'une référence historique est réutilisée.

[`IncrementalUpdate`](../src/Syntax/FileStructure/IncrementalUpdate.php) compose la révision précédente, un `FileBody` de modifications explicites et d'éventuelles entrées de trailer. [`IncrementalWriter`](../src/Serialization/IncrementalWriter.php) ajoute les nouveaux objets, garde les numéros des remplacements, hérite le trailer et produit `/Prev`. [`CrossReferenceSection`](../src/Syntax/FileStructure/CrossReferenceSection.php) représente les enregistrements de cette section ; [`CrossReferenceLimits`](../src/Syntax/FileStructure/CrossReferenceLimits.php) borne la capacité cumulée. Voir le [tutoriel des révisions](tutorials/11-incremental-update.md).

| Composant | Représentation PDF |
| --- | --- |
| [`CrossReferenceFormat`](../src/Syntax/FileStructure/CrossReferenceFormat.php) | Choix explicite entre table et stream. |
| [`CrossReferenceTable`](../src/Syntax/FileStructure/CrossReferenceTable.php), [`CrossReferenceEntry`](../src/Syntax/FileStructure/CrossReferenceEntry.php) | Section `xref` classique et ses entrées. |
| [`CrossReferenceStream`](../src/Syntax/FileStructure/CrossReferenceStream.php) | Flux de références croisées. |
| [`CrossReferenceStreamFreeEntry`](../src/Syntax/FileStructure/CrossReferenceStreamFreeEntry.php), [`CrossReferenceStreamInUseEntry`](../src/Syntax/FileStructure/CrossReferenceStreamInUseEntry.php), [`CrossReferenceStreamCompressedEntry`](../src/Syntax/FileStructure/CrossReferenceStreamCompressedEntry.php) | Entrées de type 0, 1 et 2 : libre, objet autonome ou membre d'un flux d'objets. |
| [`ByteOffset`](../src/Syntax/FileStructure/ByteOffset.php) | Position mesurée en octets. |
| [`FileTrailer`](../src/Syntax/FileStructure/FileTrailer.php) | Dictionnaire du trailer et fin de fichier. |
| [`ObjectStream`](../src/Syntax/FileStructure/ObjectStream.php), [`ObjectStreamEntry`](../src/Syntax/FileStructure/ObjectStreamEntry.php) | Groupe d'objets embarqués dans un flux `/ObjStm`. |

Un `ObjectStream` exige une version PDF 1.5 au minimum et des références croisées au format stream. Ses membres reçoivent les entrées de type 2 correspondantes et ne sont pas écrits une seconde fois comme objets autonomes. Le [tutoriel objets compressés](tutorials/08-object-stream.md) montre le corps du fichier et ces relations.

Le premier [tutoriel](tutorials/01-first-document.md) expose l'assemblage classique jusqu'à l'appel de `writeTo()`.

## 5. Flux et filtres

Un [`StreamObject`](../src/Syntax/Object/StreamObject.php) reçoit des octets ou un `ContentStreams`, un dictionnaire et une suite d'objets [`Filter`](../src/Syntax/Filters/Filter.php). Il calcule `/Length`, `/Filter` et `/DecodeParms`. Ces entrées sont gérées par le flux ; les fournir manuellement est refusé.

`/Length` compte les octets **après encodage**, en excluant la fin de ligne de délimitation. [`StreamExtent`](../src/Syntax/Object/StreamObject/StreamExtent.php) porte ce nombre. Un flux est écrit indirectement ; on utilise une `IndirectReference` lorsqu'il est la valeur d'une entrée de dictionnaire. `PageObject` crée cette référence pour le flux de contenu reçu directement au constructeur.

Les filtres natifs sont [`ASCIIHexDecode`](../src/Syntax/Filters/ASCIIHexDecode.php), [`ASCII85Decode`](../src/Syntax/Filters/ASCII85Decode.php), [`FlateDecode`](../src/Syntax/Filters/FlateDecode.php) et [`RunLengthDecode`](../src/Syntax/Filters/RunLengthDecode.php). Leurs noms expriment le **décodage effectué par le lecteur PDF** ; leurs objets appliquent l'encodage nécessaire à l'écriture.

La suite de filtres est donnée dans l'ordre du décodage. Les encodages s'appliquent en sens inverse. Par exemple, une suite ASCII85 puis Flate déclare que le lecteur retire d'abord ASCII85, puis décompresse Flate.

[`EncodedData`](../src/Syntax/Filters/EncodedData.php) déclare des données **déjà encodées**, avec un [`FilterName`](../src/Syntax/Filters/FilterName.php). Il peut, par exemple, déclarer des octets JPEG associés à `DCTDecode`. Il ne lit pas d'image, n'applique pas de codec et ne convertit pas un bitmap en JPEG. Il doit être le dernier filtre de décodage et recevoir des données binaires ; le traitement `Crypt` exige une prise en charge de la sécurité du document.

Le [tutoriel images](tutorials/06-images.md) compare une image XObject réutilisable et une inline image. Une [`InlineImage`](../src/Syntax/ContentStreamsResources/Operators/InlineImage.php) regroupe `BI`, `ID` et `EI` en un objet atomique ; les restrictions sur son dictionnaire et ses octets évitent une délimitation ambiguë.

### Fichiers JPEG et PNG

Les composants de [`Document\Images`](../src/Document/Images) reçoivent un [`ImageFileBytes`](../src/Document/Images/ImageFileBytes.php), sans lire de chemin ni d'URL. [`ImageImportLimits`](../src/Document/Images/ImageImportLimits.php) borne les octets, pixels, dimensions et chunks. [`PngImageDecoder`](../src/Document/Images/PngImageDecoder.php) reconstruit des [`ImageSamples`](../src/Document/Images/ImageSamples.php) : les échantillons couleur et alpha restent distincts. [`JpegImageReader`](../src/Document/Images/JpegImageReader.php) inspecte la structure et les couleurs d'un [`JpegImage`](../src/Document/Images/JpegImage.php), puis conserve ses données DCT.

[`ImageXObjectFactory`](../src/Document/Images/ImageXObjectFactory.php) compose le dictionnaire normalisé de la table 89 et ses flux. Il crée un `/SMask` indirect pour l'alpha et fournit les paramètres DCT ou Flate appropriés. Il ne choisit ni le nom de ressource ni la matrice de peinture. Le [tutoriel des fichiers image](tutorials/10-image-files.md) montre ces opérations et les modes supportés.

## 6. Instructions de contenu, dessin et texte

### `ContentStreams` contient des instructions

[`ContentStreams`](../src/Syntax/ContentStreamsResources/ContentStreams.php) compose une suite ordonnée d'objets [`Operator`](../src/Syntax/ContentStreamsResources/Operator.php). Il ne représente pas un dictionnaire de flux. `StreamObject` prend en charge le stockage de ses instructions dans le fichier.

Chaque opérateur décrit un token PDF et ses opérandes typés. `with()` retourne un nouveau `ContentStreams` contenant les instructions supplémentaires. `validate()` contrôle les états et les imbrications avant l'émission des octets.

```php
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

$instructions = new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
    new MoveTextPosition(new NumericType(72), new NumericType(720)),
    new ShowText(new LiteralString('Bonjour PDF')),
    new EndTextObject(),
);
$contents = new StreamObject($instructions);
```

Cet extrait produit `BT`, `/F1 18 Tf`, `72 720 Td`, `(Bonjour PDF) Tj`, puis `ET`. `/F1` doit être défini dans les ressources de la page. La validation du contenu ne résout pas tous les noms de ressources et ne vérifie pas toutes les relations avec un espace colorimétrique ; leur assemblage reste explicite.

### Familles d'opérateurs

| Famille | Exemples de classes et tokens | Effet |
| --- | --- | --- |
| Graphics state | `SaveGraphicsState` (`q`), `RestoreGraphicsState` (`Q`), `SetLineWidth` (`w`), `SetGraphicsStateParameters` (`gs`) | Sauvegarder, restaurer ou configurer l'état graphique. |
| Transformations | `ConcatenateMatrix` (`cm`) | Modifier la transformation courante. |
| Path construction | `MoveTo` (`m`), `LineTo` (`l`), `CurveTo` (`c`), `AppendRectangle` (`re`), `CloseSubpath` (`h`) | Construire un chemin sans encore le peindre. |
| Path painting et clipping | `StrokePath` (`S`), `FillPathNonzero` (`f`), `ClipPathNonzero` (`W`), `EndPath` (`n`) | Peindre le chemin ou terminer l'application d'un clipping. |
| Couleurs | `SetStrokingRGBColor` (`RG`), `SetNonstrokingRGBColor` (`rg`), variantes gray, CMYK, espaces et patterns | Définir les couleurs de contour et de remplissage. |
| Text state | `SetTextFontAndSize` (`Tf`), `SetCharacterSpacing` (`Tc`), `SetWordSpacing` (`Tw`), `SetTextLeading` (`TL`) | Configurer police, taille, espacements et interligne. |
| Text positioning et showing | `SetTextMatrix` (`Tm`), `MoveTextPosition` (`Td`), `ShowText` (`Tj`), `ShowTextWithIndividualGlyphPositioning` (`TJ`) | Positionner et afficher les octets de texte. |
| XObjects et shadings | `InvokeXObject` (`Do`), `PaintShading` (`sh`) | Invoquer une ressource nommée. |
| Marked content | `BeginMarkedContent` (`BMC`), `BeginMarkedContentWithProperties` (`BDC`), `EndMarkedContent` (`EMC`) | Associer une structure marquée à une séquence de contenu. |
| Compatibility | `BeginCompatibilitySection` (`BX`), `EndCompatibilitySection` (`EX`) | Représenter les sections de compatibilité prévues par PDF. |
| Type 3 glyph metrics | `SetGlyphWidth` (`d0`), `SetGlyphWidthAndBoundingBox` (`d1`) | Déclarer les métriques initiales d'un flux de glyphe Type 3. |

Les classes se trouvent dans [`Operators`](../src/Syntax/ContentStreamsResources/Operators). La référence API détaille chacune de leurs signatures. L'inventaire représente les 73 tokens, dont le token historique `F` et les trois délimiteurs de l'inline image.

La validation empêche notamment les objets texte imbriqués, les chemins inachevés, les restaurations `Q` sans sauvegarde `q`, les scopes marqués qui se croisent et les instructions de dessin incompatibles avec un objet texte. Un clipping `W` ou `W*` doit être suivi d'un opérateur qui termine le chemin.

[`Type3GlyphContentStream`](../src/Syntax/ContentStreamsResources/Type3GlyphContentStream.php) représente un flux `CharProcs`. Il exige `d0` ou `d1` au début et applique les contraintes d'un glyphe non coloré lorsque `d1` est utilisé. Ce composant ne construit pas à lui seul toute une police Type 3.

### Valeurs de graphics state et text state

[`Matrix`](../src/Graphics/CoordinateSystems/Matrix.php) contient les six composantes d'une transformation. [`LineDashPattern`](../src/Graphics/GraphicsState/LineDashPattern.php), [`LineCapStyle`](../src/Graphics/GraphicsState/LineCapStyle.php), [`LineJoinStyle`](../src/Graphics/GraphicsState/LineJoinStyle.php) et [`RenderingIntent`](../src/Graphics/GraphicsState/RenderingIntent.php) donnent des objets ou enums aux paramètres correspondants. [`TextRenderingMode`](../src/Text/TextRenderingMode.php) exprime les modes de peinture et de clipping du texte.

Ces objets décrivent les valeurs passées aux instructions. Ils ne calculent pas de mise en page et ne dessinent pas dans un canvas PHP. Le lecteur PDF exécute les instructions une fois le document ouvert. Les [tutoriels dessin](tutorials/03-graphics.md) et [texte](tutorials/02-pages-and-text.md) montrent leurs effets.

## 7. Catalogue, pages et héritage

[`DocumentCatalog`](../src/Syntax/DocumentStructure/DocumentCatalog.php) est le dictionnaire racine `/Catalog`. Son constructeur reçoit la racine de l'arbre de pages et crée `/Type` et `/Pages`. Il compose la définition du tableau 28 pour vérifier ses autres entrées. Le graphe protège les champs structurels contre le remplacement et la suppression par des entrées génériques.

[`PageTree`](../src/Syntax/DocumentStructure/PageTree.php) est le contrat des éléments de l'arbre : [`PageTreeNode`](../src/Syntax/DocumentStructure/PageTree/PageTreeNode.php) représente `/Pages`, et [`PageObject`](../src/Syntax/DocumentStructure/PageTree/PageObject.php) représente une feuille `/Page`.

`PageTreeNode::addKid()` rattache un enfant au builder existant. Chaque enfant appartient à un seul parent. L'arbre produit `/Kids`, `/Parent` et `/Count`, et empêche les cycles. Une page doit être configurée avant son rattachement : elle ne peut plus ensuite être clonée ou recevoir une modification de dictionnaire par `with()`.

`PageObject` reçoit sa `Rectangle` de `/MediaBox`, ses ressources et son contenu. `/MediaBox` et `/Resources` peuvent aussi être hérités d'un ancêtre. Leur résolution effective est vérifiée au moment où la page est parcourue pour l'écriture. Un tableau `/Contents` doit contenir des références vers des flux.

Les objets d'entrée de [`PageDictionary`](../src/Syntax/DocumentStructure/PageDictionary) expriment notamment `MediaBox`, `CropBox`, `BleedBox`, `TrimBox`, `ArtBox`, `Rotate`, `UserUnit` et `Resources`. Les constructeurs spécialisés conservent la responsabilité des champs qu'ils gèrent eux-mêmes.

Les entrées de [`CatalogDictionary`](../src/Syntax/DocumentStructure/CatalogDictionary) relient les structures documentaires : `Names`, `Outlines`, `Metadata`, `PageLabels`, `AcroForm`, `ViewerPreferences` et les autres clés normalisées. [`PageLayout`](../src/Document/PageLayout.php) et [`PageMode`](../src/Document/PageMode.php) représentent les valeurs d'affichage du catalogue. Une définition d'`AcroForm`, par exemple, permet de construire ses entrées ; elle n'implémente pas automatiquement toutes les règles de comportement des formulaires.

Le [premier document](tutorials/01-first-document.md) présente une page ; le [tutoriel pages et texte](tutorials/02-pages-and-text.md) montre des pages partageant des ressources et des propriétés héritées.

## 8. Ressources, polices et informations documentaires

### Ressources nommées

[`ResourceDictionary`](../src/Document/ResourceDictionary.php) compose le tableau 33. Les objets de [`Document\Resources`](../src/Document/Resources) définissent les catégories `ExtGState`, `ColorSpace`, `Pattern`, `Shading`, `XObject`, `Font`, `Properties` et `ProcSet`.

Chaque catégorie associe des noms locaux à des objets PDF. Le nom `/F1` dans un opérateur `Tf` désigne donc une entrée du dictionnaire `/Font` des ressources accessibles à cette page. Il ne désigne pas directement le nom de base `/Helvetica` de la police.

Réutiliser la même instance de police, d'image ou de shading par référence permet de la partager entre pages. Les noms de ressource et les objets vers lesquels ils pointent restent explicites. `ProcSet` représente une entrée prévue par PDF 1.7 et dépréciée dans cette spécification ; les nouveaux exemples n'ont pas besoin de l'utiliser.

### Polices

[`StandardFont`](../src/Document/Fonts/StandardFont.php) enumère les quatorze polices Type 1 standard. [`Type1Font`](../src/Document/Fonts/Type1Font.php) construit leur dictionnaire `/Type /Font`, `/Subtype /Type1` et `/BaseFont`, tout en permettant des entrées supplémentaires explicites. Les trois champs gérés ne peuvent être remplacés ou supprimés.

Pour une fonte incorporée, [`FontProgram`](../src/Document/Fonts/TrueType/FontProgram.php) décrit un TrueType statique et ses tables. [`CharacterMap`](../src/Document/Fonts/TrueType/CharacterMap.php) associe des valeurs Unicode à des CID distincts ; [`TextEncoder`](../src/Document/Fonts/TrueType/TextEncoder.php) produit les codes à deux octets attendus par Identity-H. Les Unicode distincts restent séparés même s'ils pointent le même glyphe, afin de préserver leur extraction.

[`CIDFontProgram`](../src/Document/Fonts/TrueType/CIDFontProgram.php) prépare le programme complet pour FontFile2, avec les tables et checksums appropriés au CIDFont. [`FontDescriptor`](../src/Document/Fonts/FontDescriptor.php), [`CIDFontType2`](../src/Document/Fonts/CIDFontType2.php) et [`Type0Font`](../src/Document/Fonts/Type0Font.php) composent les dictionnaires normalisés. [`CIDToGIDMap`](../src/Document/Fonts/TrueType/CIDToGIDMap.php), les largeurs et [`ToUnicodeCMap`](../src/Document/Fonts/TrueType/ToUnicodeCMap.php) donnent leurs correspondances propres. Le [tutoriel TrueType et Unicode](tutorials/09-embedded-font.md) montre le graphe complet.

Les autres tables de polices, CMaps et encodages existent dans le modèle généré. Le sous-ensemble de glyphes, les écritures verticales et la composition des scripts complexes ne sont pas fournis par cette incorporation. Les polices et leurs droits d'utilisation restent explicitement choisis par l'application.

### `/Info` et metadata streams

[`DocumentInformation`](../src/Document/DocumentInformation.php) compose les objets de [`Document\Information`](../src/Document/Information) : `Title`, `Author`, `Subject`, `Keywords`, `Creator`, `Producer`, `CreationDate`, `ModDate` et `Trapped`. Les champs textuels utilisent un `TextString`, les dates un `Date`, et `Trapped` un enum dédié. Une référence vers ce dictionnaire est ajoutée sous `/Info` dans le trailer.

L'entrée [`Metadata`](../src/Syntax/DocumentStructure/CatalogDictionary/Metadata.php) relie un metadata stream au catalogue. La construction d'un flux typé `Metadata` ne valide pas automatiquement un vocabulaire XMP ni sa cohérence avec `/Info`. Ces traitements demandent des composants dédiés.

## 9. Destinations, plans et arbres de clés

### Destinations et actions

[`Destination`](../src/Document/Destinations/Destination.php) est la base des destinations explicites. [`XYZ`](../src/Document/Destinations/XYZ.php), [`Fit`](../src/Document/Destinations/Fit.php), [`FitH`](../src/Document/Destinations/FitH.php), [`FitV`](../src/Document/Destinations/FitV.php), [`FitR`](../src/Document/Destinations/FitR.php), [`FitB`](../src/Document/Destinations/FitB.php), [`FitBH`](../src/Document/Destinations/FitBH.php) et [`FitBV`](../src/Document/Destinations/FitBV.php) décrivent une page cible et un mode de cadrage.

La page cible est un objet, directement reçu par le constructeur ou désigné par une référence. Les paramètres absents de `XYZ` deviennent des valeurs PDF `null`, qui ont un sens défini par la norme.

Une destination décrit une vue ; une action décrit une opération à effectuer. Les tables générées modélisent les dictionnaires d'actions et les annotations de lien. Le [tutoriel liens](tutorials/05-links.md) relie une annotation à une destination interne et à une URI externe.

### Document outline

[`OutlineDictionary`](../src/Document/OutlineDictionary.php) représente la racine du plan. [`OutlineItem`](../src/Document/OutlineItem.php) reçoit un titre `TextString`, une destination ou un dictionnaire d'action, son état ouvert ou fermé et ses entrées supplémentaires. [`OutlineParent`](../src/Document/OutlineParent.php) porte le contrat d'un parent de plan.

`addItem()` rattache les éléments et les sous-éléments. Les builders calculent les liens `/Parent`, `/Prev`, `/Next`, `/First`, `/Last` et les comptes de descendants visibles ; ces champs ne doivent pas être fabriqués par des entrées génériques. Un élément ne peut être rattaché à plusieurs parents et les cycles sont refusés. Le [tutoriel navigation](tutorials/04-navigation.md) explique le résultat dans le panneau des signets.

### Name trees et number trees

[`NameTree`](../src/Syntax/DataStructure/NameTree.php) et [`NameTreeEntry`](../src/Syntax/DataStructure/NameTreeEntry.php) associent des clés `StringType` à des valeurs PDF. [`NumberTree`](../src/Syntax/DataStructure/NumberTree.php) et [`NumberTreeEntry`](../src/Syntax/DataStructure/NumberTreeEntry.php) emploient des clés `IntegerType`.

Le constructeur crée une feuille, trie les clés et refuse les doublons. La factory `branch()` construit une branche à partir de descendants non vides, trie leurs intervalles et refuse les recouvrements. `/Kids` et `/Limits` sont dérivés de ces objets ; les méthodes `with()` et `without()` ne permettent pas d'altérer cette structure.

[`NameDictionary`](../src/Syntax/DocumentStructure/NameDictionary.php) associe des catégories nommées à des name trees. Il peut, par exemple, relier des destinations nommées au catalogue. Les number trees servent entre autres aux labels de pages. Ce sont des structures de classement définies par PDF, distinctes des noms locaux du dictionnaire de ressources.

## 10. Les quatre types de fonctions PDF

[`FunctionObject`](../src/Functions/FunctionObject.php) expose le domaine, la plage éventuelle et les nombres de composantes d'entrée et de sortie. [`Domain`](../src/Functions/Domain.php) et [`Range`](../src/Functions/Range.php) composent des [`NumberInterval`](../src/Functions/NumberInterval.php). [`NumberArray`](../src/Functions/NumberArray.php) exprime les tableaux numériques complémentaires.

Ces composants produisent une définition de fonction que le lecteur PDF interprète. La bibliothèque ne fournit pas un moteur d'évaluation numérique universel des fonctions.

| FunctionType | Classe | Composition et contrôles principaux |
| --- | --- | --- |
| 0 — Sampled | [`SampledFunction`](../src/Functions/SampledFunction.php) | Grille multidimensionnelle, nombre de sorties et valeurs échantillonnées. [`SamplingSize`](../src/Functions/SamplingSize.php), [`SampleBitDepth`](../src/Functions/SampleBitDepth.php), [`SampleValues`](../src/Functions/SampleValues.php) et [`InterpolationOrder`](../src/Functions/InterpolationOrder.php) décrivent la grille et son empaquetage. Le nombre de composantes doit correspondre au produit des tailles et des sorties. |
| 2 — Exponential interpolation | [`ExponentialInterpolationFunction`](../src/Functions/ExponentialInterpolationFunction.php) | Une entrée et des vecteurs `C0`, `C1` de même taille ; `N` définit l'exposant. Les restrictions du domaine tiennent compte des exposants fractionnaires et négatifs. |
| 3 — Stitching | [`StitchingFunction`](../src/Functions/StitchingFunction.php) | Une entrée partagée entre des sous-fonctions compatibles ; `Bounds` et `Encode` décrivent les subdivisions et les mappings. Les fonctions doivent avoir le même nombre de sorties. |
| 4 — PostScript calculator | [`PostScriptCalculatorFunction`](../src/Functions/PostScriptCalculatorFunction.php) | Un flux contenant une procédure typée du sous-ensemble calculator autorisé par PDF. |

Pour le type 2, une interpolation de 0 à 1 s'écrit :

```php
use PDF\Functions\Domain;
use PDF\Functions\ExponentialInterpolationFunction;
use PDF\Functions\NumberInterval;
use PDF\Syntax\Object\NumericType;

$function = new ExponentialInterpolationFunction(
    new Domain(new NumberInterval(new NumericType(0), new NumericType(1))),
    new NumericType(1),
);
```

Les valeurs par défaut sont `C0 = [0]` et `C1 = [1]`. Avec `N = 1`, la fonction est linéaire. Le [tutoriel dégradé](tutorials/07-gradient.md) utilise trois composantes de sortie pour produire une couleur RGB.

Pour le type 4, [`Calculator\Procedure`](../src/Functions/Calculator/Procedure.php) compose des objets [`Instruction`](../src/Functions/Calculator/Instruction.php), dont [`Number`](../src/Functions/Calculator/Number.php), l'enum [`Operator`](../src/Functions/Calculator/Operator.php) et des procédures imbriquées. Il n'existe pas d'exécution de code PHP ou de programme PostScript libre ; les objets produisent les instructions calculator autorisées. La validité complète d'un calcul, de ses effets de pile et de ses résultats demande toutefois une validation sémantique supplémentaire.

## 11. Sources et objets de définition

Les définitions conservent les informations nécessaires pour remonter à la norme :

| Composant | Informations portées |
| --- | --- |
| [`SourceReference`](../src/Specification/SourceReference.php) | Standard, tableau, clause éventuelle, page physique du PDF et empreinte du document source. |
| [`Requirement`](../src/Specification/Requirement.php) | Exigence de présence, avec son caractère inconditionnel ou conditionnel. |
| [`ObjectTypeConstraint`](../src/Specification/ObjectTypeConstraint.php) | Familles de valeurs, règles d'objet direct ou indirect et autres contraintes de type prises en charge. |
| [`EntryDefinition`](../src/Specification/EntryDefinition.php) | Nom, type, présence, description et source d'une entrée. |
| [`TableDefinition`](../src/Specification/TableDefinition.php) | Collection d'entrées et composition explicite de tables communes. |
| [`ImplementationCorrection`](../src/Specification/ImplementationCorrection.php) | Décision d'implémentation documentée lorsque le texte source est incohérent. |
| [`Catalogue`](../src/Specification/Catalogue.php) | Collection immuable de définitions choisies et construite par l'appelant. |

Ces objets ne chargent pas de JSON à l'exécution. Les inventaires sous [`resources/iso32000-1`](../resources/iso32000-1) servent aux outils de génération et de vérification. Les classes générées se construisent avec leurs définitions immuables. La [documentation de spécification](specification.md) explique la génération reproductible et la correction documentée de `/Prev`.

Une classe de dictionnaire d'encryption, de signature, de font descriptor, de multimédia ou de structure balisée décrit des entrées du format. Les traitements sont portés par leurs mécanismes dédiés lorsqu'ils existent, comme le programme TrueType et l'incorporation CID. La présence d'un dictionnaire ne réalise pas le chiffrement, la signature, la lecture multimédia ou la conformité d'une structure balisée. La référence API documente les objets disponibles ; la couverture annoncée reste celle de leurs comportements effectivement implémentés.

## 12. Choisir un composant et poursuivre

Pour créer un document, commencer par les objets de domaine : pages, ressources, contenu et catalogue. Utiliser les entrées générées pour les concepts encore dépourvus d'objet spécialisé ou pour ajouter une entrée normalisée explicite. Employer un `Entry` générique pour une extension dont le nom et le sens sont maîtrisés par l'application.

Les constructeurs vérifient les valeurs locales, les builders vérifient la propriété des graphes, et l'écriture vérifie les états qui nécessitent un graphe complet. L'application choisit les données, les chemins, les ressources accessibles et les actions qu'elle accepte. La [documentation de sécurité](security.md) précise les limites et les protections disponibles.

Pour passer à des documents complets, suivre les [huit tutoriels](tutorials/README.md), comparer leur aperçu au PDF téléchargeable sur le site, puis étudier la [recette de tableau](recipes/table.md). Pour ajouter un comportement au modèle, consulter l'[architecture](architecture.md), la [couverture de spécification](specification.md) et la [référence API](https://roukmoute.github.io/pdf/reference/index.html).
