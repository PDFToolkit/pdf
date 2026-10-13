# Architecture

## Domaine PDF

Le namespace `PDF\Syntax` suit les termes de la référence. `BasicType` est le contrat des valeurs PDF. Les sous-types représentent les huit familles d'objets ; les objets indirects et leurs références ont des classes distinctes. Les classes spécialisées, comme `Rectangle`, ajoutent des contraintes à ces valeurs sans convertir leur contenu en scalaires PHP.

Les arguments scalaires sont limités aux feuilles : une valeur numérique, une suite d'octets, un nom ou un booléen. Les compositions utilisent des objets. Une entrée de dictionnaire possède un `NameType` et un `BasicType` ; une instruction de contenu possède des opérandes typés.

Chaque classe porte une responsabilité propre au modèle PDF. Les noms et les abstractions suivent la norme ; l'API peut évoluer pour respecter ce modèle. Les raccourcis de composition et les fonctions de mise en page appartiennent aux futures bibliothèques de confort.

## Définition et instance

`PDF\Specification` sépare une définition de norme et un objet PDF. `SourceReference`, `Requirement`, `ObjectTypeConstraint`, `EntryDefinition` et `TableDefinition` sont des valeurs immuables. Les définitions générées sont construites par des instances, sans lecture JSON à l'exécution ni registre global.

Les dictionnaires et entrées générés portent les termes du tableau d'origine. Le numéro de tableau stabilise les noms de classes lorsque plusieurs tableaux portent un titre similaire. Les noms de classes PHP sont uniques sans tenir compte de la casse ; les noms PDF restent sensibles à la casse. Cette différence est vérifiée dans les tests.

Une table d'« additional entries » peut composer les définitions communes de son type. Les entrées inconnues restent accessibles par un objet `Entry` explicite afin de permettre les extensions du format. Ce mécanisme n'autorise pas à contourner les contraintes d'une entrée connue.

`DocumentCatalog` et `ResourceDictionary` composent les définitions des tableaux 28 et 33 pour valider leurs entrées. Le catalogue ajoute les règles de propriété du graphe de pages. Ces règles complètent les contraintes de la définition, qui s'appliquent aussi aux entrées génériques.

## Assemblage des graphes

Les valeurs et définitions ne gèrent pas l'identité d'un fichier. Les références visent leurs objets. Pendant une écriture, `SerializationContext` parcourt les objets accessibles et réserve les identifiants explicites avant d'attribuer les identifiants automatiques. Un même objet reçoit un seul identifiant dans ce fichier.

Les cycles indirects, comme les liens `Parent`/`Kids`, sont permis. Les cycles directs sont refusés. Deux instances distinctes ne peuvent pas réclamer le même numéro, et une instance ne peut pas réclamer deux identifiants.

Les arbres de pages et plans sont assemblés par des builders qui contrôlent le rattachement à un seul parent. Les champs structurels ne peuvent pas être remplacés ou supprimés par une entrée générique. Les arbres de noms et nombres trient leurs clés et vérifient les intervalles de leurs descendants.

## Écriture

`FileWriter` et les objets `CrossReferenceTable`, `CrossReferenceStream` et `ObjectStream` portent la représentation du fichier. Le format de références croisées est choisi par un objet enum `CrossReferenceFormat`. Les flux d'objets demandent le format stream et PDF 1.5 au minimum.

Le sérialiseur compte des octets, pas des caractères Unicode. Le header utilise le tiret ASCII prescrit, les chaînes échappent leur syntaxe, les noms échappent leurs octets, et les nombres n'utilisent jamais de notation exponentielle. La longueur d'un flux est calculée après l'application des filtres.

Les membres d'un flux d'objets reçoivent des entrées de type 2 dans le flux de références et ne sont pas écrits une seconde fois comme objets autonomes. `startxref` vise le début réel de la section de références.

## Validation

Les constructeurs refusent les états localement invalides. Les validations de graphe interviennent au moment de la sérialisation, lorsque les parents et ressources peuvent être établis. Les contraintes conditionnelles de la norme ne sont pas présentées comme automatiquement couvertes tant qu'une validation et un test dédiés ne les prennent pas en charge.

Les tests PHPUnit vérifient des comportements et des contre-exemples ; des lecteurs indépendants contrôlent aussi les fichiers produits. L'analyse PHPStan au niveau 8 et le format PSR-12 font partie de `composer check`.

Le modèle requiert PHP 8.3 ou supérieur, avec des constantes typées, des classes `readonly` et des enums. La CI exécute les vérifications sous PHP 8.3, 8.4 et 8.5. Sous PHP 8.5, l'attribut natif `NoDiscard` signale les résultats ignorés des méthodes immuables qui le déclarent ; ces méthodes construisent les mêmes objets sous les trois versions.

## Extension

Une bibliothèque de confort peut transformer ses propres objets de mise en page en ce modèle. Une nouvelle entrée normalisée peut recevoir un type spécialisé et sa validation, en conservant la définition et sa source. Un nouveau filtre doit réaliser l'encodage qu'il déclare ; un nom de filtre ou une définition de dictionnaire ne suffit pas à revendiquer un codec.

Les polices TrueType se composent d'un programme, de tables et métriques, d'une correspondance caractère/CID, d'un programme CIDFont incorporable, d'un descripteur, d'une fonte CID et d'une fonte Type0. L'encodage du texte et ToUnicode restent des objets séparés. Le programme fournit des octets ; il ne sélectionne pas une police système et ne compose pas les scripts complexes.

Les lecteurs d'images reçoivent un `ImageFileBytes`. Le PNG devient des échantillons couleur et éventuellement alpha ; le JPEG conserve des données DCT inspectées. `ImageXObjectFactory` compose leurs dictionnaires et leurs flux. Les noms de ressources et matrices de peinture restent explicites. Les limites de lecture sont fournies par un `ImageImportLimits`.

`FileRevision` fige les octets et les liaisons d'identité d'un document. `RevisionObject` conserve ses alias historiques et une copie des entrées de son dictionnaire, pour ne pas reparcourir un builder mutable après écriture. `IncrementalUpdate` sélectionne les changements dans un `FileBody` ; `IncrementalWriter` ajoute les nouveaux octets, hérite le trailer et relie les sections xref. Une référence historique ne déclenche pas de nouvelle sérialisation. Les conteneurs structuraux anciens sont conservés et les membres compressés remplacés utilisent un nouveau conteneur ou un objet autonome.

Les futurs traitements de sécurité et de signatures devront également disposer d'objets et de services explicites. Ils ne doivent pas introduire d'état partagé entre documents.
