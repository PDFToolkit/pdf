# Architecture

Cette architecture décrit le core **0.4.0** et les modules optionnels **0.1.2**.
Le [guide des composants](components.md) présente leurs objets concrets ; la
[couverture](coverage.md) distingue constructions natives, règles vérifiées et
travail restant dans les deux références conservées.

## Domaine PDF

Le namespace `PDF\Syntax` suit les termes de la référence. `BasicType` est le contrat des valeurs PDF. Les sous-types représentent les huit familles d'objets ; les objets indirects et leurs références ont des classes distinctes. Les classes spécialisées, comme `Rectangle`, ajoutent des contraintes à ces valeurs sans convertir leur contenu en scalaires PHP.

Les arguments scalaires sont limités aux feuilles : une valeur numérique, une suite d'octets, un nom ou un booléen. Les compositions utilisent des objets. Une entrée de dictionnaire possède un `NameType` et un `BasicType` ; une instruction de contenu possède des opérandes typés.

Chaque classe porte une responsabilité propre au modèle PDF. Les noms et les abstractions suivent la norme ; l'API peut évoluer pour respecter ce modèle. Les raccourcis de composition et les fonctions de mise en page appartiennent aux futures bibliothèques de confort.

## Frontière des packages

Le core `roukmoute/pdf` contient les objets et les mécanismes PDF décrits par la référence Adobe/ISO. `pdftoolkit/pdf-reader`, `pdftoolkit/pdf-images` et `pdftoolkit/pdf-fonts` sont des modules optionnels séparés. Ils dépendent du core, qui ne dépend d'aucun d'eux. Leurs parseurs ne sont pas chargés par une application qui installe seulement le core.

Les noms, valeurs, références et structures sont partagés par composition. Le core définit notamment les échantillons et flux image, le programme incorporé `/FontFile2`, les métriques, les fontes CID et les CMaps PDF. L'inspection d'un JPEG, le décodage PNG et l'analyse des tables TrueType sont des traitements de formats externes dans leurs modules. Le [guide des packages](packages.md) détaille ces frontières et les installations.

## Définition et instance

`PDF\Specification` sépare une définition de norme et un objet PDF. `SourceReference`, `Requirement`, `ObjectTypeConstraint`, `EntryDefinition` et `TableDefinition` sont des valeurs immuables. Les définitions générées sont construites par des instances, sans lecture JSON à l'exécution ni registre global.

Les dictionnaires et entrées générés portent les termes du tableau d'origine. Le numéro de tableau stabilise les noms de classes lorsque plusieurs tableaux portent un titre similaire. Les noms de classes PHP sont uniques sans tenir compte de la casse ; les noms PDF restent sensibles à la casse. Cette différence est vérifiée dans les tests.

Une table d'« additional entries » peut composer les définitions communes de son type. Les entrées inconnues restent accessibles par un objet `Entry` explicite afin de permettre les extensions du format. Ce mécanisme n'autorise pas à contourner les contraintes d'une entrée connue.

`DocumentCatalog` et `ResourceDictionary` composent les définitions des tableaux 28 et 33 pour valider leurs entrées. Le catalogue ajoute les règles de propriété du graphe de pages. Ces règles complètent les contraintes de la définition, qui s'appliquent aussi aux entrées génériques.

## Assemblage des graphes

Les valeurs et définitions ne gèrent pas l'identité d'un fichier. Les références visent leurs objets. Pendant une écriture, `SerializationContext` parcourt les objets accessibles et réserve les identifiants explicites avant d'attribuer les identifiants automatiques. Un même objet reçoit un seul identifiant dans ce fichier.

Les cycles indirects, comme les liens `Parent`/`Kids`, sont permis. Les cycles directs sont refusés. Deux instances distinctes ne peuvent pas réclamer le même numéro, et une instance ne peut pas réclamer deux identifiants.

Les arbres de pages et plans sont assemblés par des builders qui contrôlent le rattachement à un seul parent. Les champs structurels ne peuvent pas être remplacés ou supprimés par une entrée générique. Les arbres de noms et nombres trient leurs clés et vérifient les intervalles de leurs descendants.

Les arbres de champs et de structure logique suivent la même règle de propriété.
Un widget appartient à son champ et à une page ; un contenu marqué appartient à
ses éléments et à son contexte de page ou de forme. Les identités permettent de
dériver les liens ParentTree/IDTree et de contrôler les références. Les groupes
de contenu optionnel ont leurs propres objets, configurations et états de
document ; deux groupes de même nom restent deux identités différentes.

Les constructeurs de valeurs restent immuables. Les builders qui assemblent un
graphe possèdent explicitement leur état local ; ils ne servent pas de registres
globaux et ne sélectionnent pas des pages ou ressources à la place de l'appelant.

## Mécanismes natifs

Les composants spécialisés composent les définitions PDF avec leurs contraintes
couplées : dimensions et composantes d'images, espaces et fonctions de teinte,
coordonnées et bits des maillages, programmes Type3, codespaces et CMaps, états
de champs, classes et attributs de structure. Les flux produits contiennent les
octets du mécanisme, par exemple un mesh, LZW ou des indices de linéarisation.
Une simple déclaration de dictionnaire conserve un rôle distinct.

Les fonctions arithmétiques PDF disponibles reçoivent des objets explicites :
transformation de couleurs calibrées, quantification Decode, précomposition
Matte, mélange local ou évaluation des conditions de visibilité. Elles ne
constituent pas un moteur de rendu, une mise en page ou une analyse de format
externe. Un profil ICC, programme de police ou média demeure une donnée fournie.

## Écriture

`FileWriter` et les objets `CrossReferenceTable`, `CrossReferenceStream` et `ObjectStream` portent la représentation du fichier. Le format de références croisées est choisi par un objet enum `CrossReferenceFormat` : table, stream ou hybride. Les flux d'objets demandent des références stream ou hybrides et PDF 1.5 au minimum.

Le sérialiseur compte des octets, pas des caractères Unicode. Le header utilise le tiret ASCII prescrit, les chaînes échappent leur syntaxe, les noms échappent leurs octets, et les nombres n'utilisent jamais de notation exponentielle. La longueur d'un flux est calculée après l'application des filtres.

Les membres d'un flux d'objets reçoivent des entrées de type 2 dans le flux de références et ne sont pas écrits une seconde fois comme objets autonomes. `startxref` vise le début réel de la section de références.

`LinearizedWriter` planifie les dépendances, l'ordre physique et les tables
d'indices de l'annexe F. `SignatureWriter` prépare une révision dont les
ByteRange sont fixes avant le calcul cryptographique, puis la signature remplit
sa réservation. Le chiffrement des chaînes et flux passe par un `FileEncryption`
et ses backends explicitement choisis. Les offsets sont ceux des octets écrits,
avec les filtres, réservations et paramètres de sécurité applicables.

## Validation

Les constructeurs refusent les états localement invalides. Les validations de graphe interviennent au moment de la sérialisation, lorsque les parents et ressources peuvent être établis. Les contraintes conditionnelles de la norme ne sont pas présentées comme automatiquement couvertes tant qu'une validation et un test dédiés ne les prennent pas en charge.

`DocumentGraphValidation` traverse le graphe réel du writer, y compris les
dictionnaires de flux et opérandes typés. Le contrat `CatalogComponent` permet
à un composant de vérifier ses relations avec le catalogue ; `VersionedComponent`
contrôle les fonctions sélectionnées contre la version PDF effective du header
et du catalogue. Les déclarations Adobe et identités de sous-ensemble de fontes
font aussi l'objet de contrôles au niveau du document. La validation s'appuie
sur les valeurs effectives figées lorsqu'une révision est réutilisée.

Ces contrats couvrent les familles et conditions annoncées, sans prétendre
vérifier chaque phrase de la norme. Les tests de graphe ne sont pas une
certification PDF/UA ; une signature valide ne remplace pas la politique de
confiance du certificat. Les [inventaires](coverage.md) conservent ces frontières
et les contraintes ouvertes.

Les tests PHPUnit vérifient des comportements et des contre-exemples ; des lecteurs indépendants contrôlent aussi les fichiers produits. L'analyse PHPStan au niveau 8 et le format PSR-12 font partie de `composer check`.

Le modèle requiert PHP 8.3 ou supérieur, avec des constantes typées, des classes `readonly` et des enums. La CI exécute les vérifications sous PHP 8.3, 8.4 et 8.5. Sous PHP 8.5, l'attribut natif `NoDiscard` signale les résultats ignorés des méthodes immuables qui le déclarent ; ces méthodes construisent les mêmes objets sous les trois versions.

## Extension

Une bibliothèque de confort peut transformer ses propres objets de mise en page en ce modèle. Une nouvelle entrée normalisée peut recevoir un type spécialisé et sa validation, en conservant la définition et sa source. Un nouveau filtre doit réaliser l'encodage qu'il déclare ; un nom de filtre ou une définition de dictionnaire ne suffit pas à revendiquer un codec.

Dans `pdftoolkit/pdf-fonts`, les polices TrueType se composent d'un programme, de tables et métriques, d'une correspondance caractère/CID et d'un programme CIDFont incorporable. Ces données alimentent les objets du core : FontFile2, descripteur, fonte CID, fonte Type0, métriques et ToUnicode. L'encodage du texte reste un service distinct. Le module fournit des octets ; il ne sélectionne pas une police système et ne compose pas les scripts complexes.

Dans `pdftoolkit/pdf-images`, les lecteurs d'images reçoivent un `ImageFileBytes`. Le PNG devient des échantillons couleur et éventuellement alpha ; le JPEG devient un `DCTImage` du core avec ses octets encodés et sa transformation de couleur. `ImageXObjectFactory`, dans le core, compose leurs dictionnaires et leurs flux sans connaître le fichier d'origine. Les noms de ressources et matrices de peinture restent explicites. Les limites de lecture sont fournies par un `ImageImportLimits` du module.

Dans `pdftoolkit/pdf-reader`, `DocumentReader` lit un `FileBytes` avec des budgets `ReaderLimits`. Un `ReadDocument` possède ses références, son index xref et son cache ; l'application appelle explicitement `resolve()` et `streamData()`. Les valeurs PDF réutilisent les primitives du core. Les références et flux externes ne se sérialisent pas dans un nouveau document sans import et remappage, mécanismes encore distincts de la lecture.

`FileRevision` fige les octets et les liaisons d'identité d'un document. `RevisionObject` conserve ses alias historiques et une copie des entrées de son dictionnaire, pour ne pas reparcourir un builder mutable après écriture. `IncrementalUpdate` sélectionne les changements dans un `FileBody` ; `IncrementalWriter` ajoute les nouveaux octets, hérite le trailer et relie les sections xref. Une référence historique ne déclenche pas de nouvelle sérialisation. Les conteneurs structuraux anciens sont conservés et les membres compressés remplacés utilisent un nouveau conteneur ou un objet autonome.

Les handlers par mot de passe et certificat, identités, permissions et signatures
possèdent leurs objets dans `PDF\Security` et `PDF\Document\Signatures`. Les
backends OpenSSL sont choisis explicitement ; le core n'effectue pas de recherche
automatique de certificats ou de clés sur le disque ou le réseau. La lecture de
fichiers externes, la composition de texte, les moteurs ICC/XFA/3D/multimédia et
la confiance des certificats conservent leurs responsabilités propres.
