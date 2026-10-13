# Tutoriels : construire et lire un PDF objet par objet

Ces tutoriels expliquent comment un fichier PDF relie ses valeurs, dictionnaires, ressources et instructions de dessin. Chaque leçon accompagne un script complet, commenté et exécutable. Les exemples utilisent le core 0.2 et les modules 0.1, avec les révisions exactes fixées dans le `composer.lock` de ce checkout.

Les leçons 09 et 10 utilisent les modules optionnels `pdftoolkit/pdf-fonts` et
`pdftoolkit/pdf-images`, installés comme dépendances de développement dans ce
checkout. Leurs objets PDF restent dans le core. Le [guide des packages](../packages.md)
explique comment installer seulement les mécanismes nécessaires à une application
et présente le lecteur externe `pdftoolkit/pdf-reader`, utilisé par la leçon 12.

Le parcours s'inspire de l'organisation des [tutoriels FPDF](https://www.fpdf.org/en/tutorial/index.php) et de sa [collection de scripts](https://www.fpdf.org/en/script/index.php). Ici, les objets du format restent visibles : une police est une ressource, une page appartient à un arbre et une ligne de texte est une séquence d'opérateurs.

## Préparer le dépôt

Il faut PHP 8.3 ou supérieur et les extensions `ctype`, `iconv` et `zlib`. Depuis la racine d'un clone :

```sh
composer install
php examples/hello-world.php
```

Le script écrit `examples/hello-world.pdf` et affiche son chemin. Ouvrir ce fichier dans un lecteur PDF permet de comparer le résultat au code. Les scripts de génération sous `examples/` acceptent un chemin de sortie comme premier argument :

```sh
php examples/hello-world.php mon-premier-document.pdf
```

Le dossier parent du chemin choisi doit déjà exister. Les scripts chargent `vendor/autoload.php` ; ils peuvent être exécutés depuis un autre dossier en donnant leur chemin complet. Les PDF générés dans `examples/` sont ignorés par Git.

La leçon 12 utilise un script installé avec le module lecteur. Son argument
désigne le PDF à lire ; le résultat est une sortie console. Son tutoriel
présente le PDF d'entrée, ses trois pages et les objets lus.

## Parcours

| Étape | Résultat et notions | Code complet |
| --- | --- | --- |
| [01. Premier document](01-first-document.md) | Une phrase, une police, une page, un catalogue et un titre documentaire. | [hello-world.php](../../examples/hello-world.php) |
| [02. Pages et texte](02-pages-and-text.md) | Plusieurs pages, ressources partagées, héritage, positions et interligne. | [pages-and-text.php](../../examples/pages-and-text.php) |
| [03. Dessin et transformations](03-graphics.md) | Couleurs, chemins, courbes, état graphique et matrices. | [graphics.php](../../examples/graphics.php) |
| [04. Signets et destinations](04-navigation.md) | Un plan hiérarchique et des destinations vers les pages. | [navigation.php](../../examples/navigation.php) |
| [05. Liens cliquables](05-links.md) | Annotations avec destination interne et action URI. | [links.php](../../examples/links.php) |
| [06. Images](06-images.md) | Le même motif en image XObject et en image incorporée au contenu. | [images.php](../../examples/images.php) |
| [07. Dégradé et fonction](07-gradient.md) | Une fonction de type 2 alimente un shading axial. | [gradient.php](../../examples/gradient.php) |
| [08. Objets compressés](08-object-stream.md) | Filtres, flux d'objets et flux de références croisées. | [object-stream.php](../../examples/object-stream.php) |
| [09. Police TrueType et Unicode](09-embedded-font.md) | Programme incorporé, CID, largeurs et correspondance ToUnicode. | [embedded-font.php](../../examples/embedded-font.php) |
| [10. Images JPEG et PNG](10-image-files.md) | Inspection JPEG, décodage PNG, échantillons et transparence. | [image-files.php](../../examples/image-files.php) |
| [11. Révisions incrémentales](11-incremental-update.md) | Conservation des octets, remplacements et chaînage des xref. | [incremental-update.php](../../examples/incremental-update.php) |
| [12. Lire un PDF externe](12-reading-pages.md) | Arbre vérifié, valeurs héritées et flux de contenu inspectés puis décodés. | [pages.php du module lecteur](https://github.com/PDFToolkit/pdf-reader/blob/master/examples/pages.php) |

Les leçons 03 à 07 peuvent se lire dans l'ordre des sujets qui vous intéressent, après le premier document. Les leçons 08 et 11 concernent l'organisation du fichier ; les leçons 09 et 10 ajoutent des programmes de police et des fichiers image réels au graphe. La leçon 12 consulte les structures d'un PDF existant avec les objets du lecteur.

## Quelques repères avant de lire le code

| Objet ou terme | Rôle |
| --- | --- |
| `NameType('F1')` | Le nom PDF `/F1`. Dans une ressource de police, c'est une clé locale choisie par l'auteur. |
| `NumericType(72)` | Un nombre PDF. Dans l'espace utilisateur par défaut, 72 unités correspondent à un pouce. |
| `LiteralString` | Des octets destinés à une chaîne PDF. Pour `Tj`, la police et son encodage déterminent les glyphes. |
| `TextString` | Une chaîne documentaire, avec encodage Unicode explicite lorsque nécessaire, utilisée par exemple pour un titre ou un signet. |
| `Entry` | Une clé `NameType` et une valeur PDF dans un dictionnaire. Les entrées spécialisées expriment des contraintes supplémentaires. |
| `IndirectReference` | Une référence vers une instance d'objet. Le writer détermine son identifiant dans le fichier. |
| `ContentStreams` | La suite des opérateurs qui décrivent le dessin ou le texte d'une page. |
| `StreamObject` | Un flux d'octets avec son dictionnaire et ses éventuels filtres. Sa longueur est calculée à l'écriture. |
| `ResourceDictionary` | Les ressources nommées que les opérateurs peuvent utiliser : polices, images, shadings, etc. |
| `PageTreeNode` / `DocumentCatalog` | L'arbre des pages et le point d'entrée du document. |

Une page neuve utilise normalement une origine en bas à gauche et un axe vertical orienté vers le haut. Une matrice ou une rotation de page peut changer la relation entre ces coordonnées et le résultat affiché. Les exemples montrent leurs coordonnées explicitement.

`with()` construit une nouvelle valeur : il faut conserver son retour. Les arbres de pages et de signets s'assemblent avec `addKid()` et `addItem()`, qui rattachent un enfant à un seul parent. Cette différence de responsabilité est détaillée dans [l'architecture](../architecture.md).

## Recettes et contributions

La [collection de recettes](../recipes/README.md) commence par un [tableau](../recipes/table.md) combinant texte, rectangles et traits. Elle montre comment construire une présentation concrète avec les objets déjà étudiés. Les positions sont calculées dans le script ; le cœur de la bibliothèque reste le modèle PDF.

Pour proposer un exemple de génération, ajouter un script autonome dans `examples/`, un guide et une entrée dans cet index. Un exemple de lecture conserve son script et ses fixtures dans le dépôt du module ; la construction du site déclare leur provenance. Le guide doit expliquer le résultat attendu, les objets employés, les paramètres à modifier et les limites. Un exemple communautaire peut créditer son auteur et citer ses sources. Les fichiers présents ici sont les exemples du projet ; cette liste pourra accueillir les contributions au fil des pull requests.

La CI exécute tous les scripts `examples/*.php`, puis contrôle chaque PDF avec qpdf. En local, avec qpdf installé :

```sh
qpdf --check examples/hello-world.pdf
qpdf --qdf --object-streams=disable examples/hello-world.pdf document-lisible.pdf
```

La seconde commande réécrit le PDF dans une forme utile pour examiner ses objets. Un contrôle syntaxique doit être complété par l'ouverture du document : un PDF valide peut encore avoir un texte hors de la page ou une mauvaise mise en page.

## Aller vers la norme

Les guides citent les sections de [PDF 1.7 / ISO 32000-1:2008](../../PDF32000_2008.pdf). La [couverture de la spécification](../specification.md) distingue les définitions disponibles des traitements réellement implémentés. Le sous-ensemble de polices, la composition de scripts complexes, le retour automatique à la ligne, le chiffrement, les signatures et l'import de PDF externes restent hors de ce parcours.

[Commencer par le premier document](01-first-document.md) · [Retour au README](../../README.md)
