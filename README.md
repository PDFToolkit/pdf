# PDF

Un modèle objet explicite de **PDF 1.7 / ISO 32000-1:2008**, pour PHP 8.3 ou supérieur.

Cette bibliothèque représente la syntaxe et les structures du format, jusque dans ses noms, nombres, références, entrées de dictionnaire et opérateurs. Elle ne cherche pas à fournir une API de mise en page. Une bibliothèque de plus haut niveau peut construire ces objets et conserver le contrôle de leur composition.

Le projet fait partie de l'organisation [PDFToolkit](https://github.com/PDFToolkit). Ce dépôt est le **core** : il contient les objets et mécanismes décrits par la norme Adobe/ISO, dont l'écriture. La lecture de PDF externes, la conversion des fichiers PNG/JPEG et l'analyse des fontes TrueType appartiennent à trois packages optionnels. Chaque module dépend du core ; installer le core pour générer un PDF n'installe aucun de ces modules.

La [documentation en ligne](https://pdftoolkit.github.io/pdf/) réunit les guides, la référence de tous les composants et les tutoriels avec leur code et leurs PDF consultables. Elle suit la branche `master` ; les différences avec la dernière version publiée sont indiquées dans les guides et le changelog.

La série `0.x` est destinée aux développeurs qui souhaitent construire et explorer ce modèle. Avant la version `1.0`, une nouvelle version mineure peut modifier les noms et signatures ; les versions correctives conservent l'API de leur série. Le périmètre implémenté et les limites figurent dans [la couverture de la spécification](docs/specification.md).

Les définitions se fondent sur les documents Adobe/ISO `PDF32000_2008.pdf` et `adobe_supplement_iso32000.pdf`, conservés dans ce dépôt. Le [guide du supplément](docs/adobe-supplement.md) explique les extensions Adobe du PDF 1.7, niveau 3, leur provenance et leurs mécanismes. Le [guide des références Adobe](docs/adobe-references.md) présente aussi la référence de 2006, ses errata, l'addendum de caviardisation et leur portfolio. Tous les PDF Adobe fournis au projet sont conservés avec leur nom et leurs octets d'origine ; les quatre références archivées sont exclues des archives Composer. PDF 2.0 et les profils PDF/A, PDF/X et PDF/UA constituent des spécifications supplémentaires.

## Installation

Le core **0.5** nécessite PHP 8.3 ou supérieur et les extensions `ctype`, `iconv` et `zlib`. Les backends AES et CMS sont optionnels et nécessitent `openssl`. Les packages sont distribués depuis GitHub ; déclarer leur dépôt VCS dans le projet consommateur :

```sh
composer config repositories.roukmoute-pdf vcs https://github.com/PDFToolkit/pdf
composer require 'roukmoute/pdf:^0.5'
```

Les modifications d'API figurent dans le [changelog](CHANGELOG.md) et le
[guide de migration 0.5](docs/migration-0.5.md). Pour suivre les développements
après cette série, remplacer la contrainte stable par `dev-master`.

## Modules optionnels

| Package | Responsabilité |
| --- | --- |
| [`pdftoolkit/pdf-reader`](https://github.com/PDFToolkit/pdf-reader) | Lire les objets et révisions d'un PDF externe, vérifier ses pages et leur héritage, inspecter leurs flux. |
| [`pdftoolkit/pdf-images`](https://github.com/PDFToolkit/pdf-images) | Inspecter des fichiers JPEG et décoder des fichiers PNG en objets image du core. |
| [`pdftoolkit/pdf-fonts`](https://github.com/PDFToolkit/pdf-fonts) | Analyser un TrueType statique et préparer ses métriques et correspondances pour les objets de police du core. |

Chaque module dispose d'une version stable **0.1.3**, compatible avec les séries 0.2, 0.3, 0.4 et 0.5 du core et PHP 8.3 ou supérieur. Déclarer le dépôt du core et celui du module choisi dans le projet consommateur. Par exemple, pour importer des fichiers image :

```sh
composer config repositories.pdf vcs https://github.com/PDFToolkit/pdf
composer config repositories.pdf-images vcs https://github.com/PDFToolkit/pdf-images
composer require 'pdftoolkit/pdf-images:^0.1'
```

Le module installe le core avec sa contrainte stable. Le [guide des packages](docs/packages.md) explique leur frontière, les installations et le fonctionnement du lecteur. Conserver le `composer.lock` de l'application pour fixer les versions et les commits utilisés.

## Développement et vérifications

Depuis un clone du dépôt :

```sh
composer install
composer check
php examples/hello-world.php hello-world.pdf
php examples/object-stream.php object-stream.pdf
```

Le code de production dépend uniquement de PHP et de ces trois extensions. PHPUnit 12, PHPStan, PHP-CS-Fixer et les modules utilisés par les exemples et la documentation sont des dépendances de développement verrouillées dans `composer.lock`. Leur présence dans ce checkout n'ajoute aucune dépendance de production aux applications utilisant le core. La CI exécute les tests et valide les PDF d'exemple sous PHP 8.3, 8.4 et 8.5. Les tests échouent aussi sur les dépréciations et avertissements PHP. Le [changelog](CHANGELOG.md) décrit chaque version publiée.

Pour valider ce checkout, y compris depuis son tag, définir
`COMPOSER_ROOT_VERSION=0.5.x-dev` avant `composer install`. Cette variable identifie
le core courant comme appartenant à la série 0.5 attendue par les modules. Les
dépendances de développement utilisent les modules stables 0.1, dont les versions
et les sources exactes sont verrouillées dans `composer.lock`. Les CI définissent
également cette variable pour les branches, les tags et les pull requests.

## Construction objet par objet

```php
$helvetica = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(
    new Font(new DictionaryType(
        new Entry(new NameType('F1'), new IndirectReference($helvetica)),
    )),
);
$contents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
    new MoveTextPosition(new NumericType(72), new NumericType(720)),
    new ShowText(new LiteralString('Super !')),
    new EndTextObject(),
));
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog));
$file->writeTo('hello-world.pdf');
```

L'exemple complet, avec les imports et les métadonnées, se trouve dans [`examples/hello-world.php`](examples/hello-world.php).

## Tutoriels et recettes

Le [parcours de tutoriels](docs/tutorials/README.md) accompagne chaque script d'une explication en français, d'un aperçu et de variantes à essayer. Les commentaires du code relient les objets aux instructions et structures du PDF.

| Sujet | Ce que l'exemple explique |
| --- | --- |
| [Premier document](docs/tutorials/01-first-document.md) | Police, ressources, contenu, page, catalogue et informations documentaires. |
| [Pages et texte](docs/tutorials/02-pages-and-text.md) | Héritage des ressources, plusieurs pages, positions et interligne. |
| [Dessin](docs/tutorials/03-graphics.md) | Couleurs, chemins, courbes et transformations de l'état graphique. |
| [Navigation](docs/tutorials/04-navigation.md) | Signets hiérarchiques et destinations vers les pages. |
| [Liens](docs/tutorials/05-links.md) | Annotations cliquables internes et actions URI. |
| [Images](docs/tutorials/06-images.md) | Image XObject, image inline, octets RGB et filtres. |
| [Dégradé](docs/tutorials/07-gradient.md) | Fonction PDF de type 2 et shading axial. |
| [Objets compressés](docs/tutorials/08-object-stream.md) | Flux de contenu, conteneur d'objets et références croisées. |
| [Police TrueType et Unicode](docs/tutorials/09-embedded-font.md) | Programme incorporé, CID, largeurs et correspondance ToUnicode. |
| [Images JPEG et PNG](docs/tutorials/10-image-files.md) | Couleurs, décodage PNG, JPEG conservé et masque de transparence. |
| [Révisions incrémentales](docs/tutorials/11-incremental-update.md) | Remplacements explicites, octets historiques conservés et chaînage des xref. |

La [recette de tableau](docs/recipes/table.md) assemble ces notions dans une présentation concrète. La [collection de recettes](docs/recipes/README.md) explique aussi comment proposer de nouveaux exemples.

## Identité et responsabilités

- Les valeurs PDF sont des objets. Les chaînes représentent des octets ; `TextString` réalise explicitement l'encodage du texte Unicode.
- Une `IndirectReference` vise une instance. Un `SerializationContext` attribue les identifiants pendant l'écriture d'un seul fichier. Il n'existe aucun singleton ni compteur global.
- `FileStructure` décrit le fichier ; `FileWriter` l'écrit. Chaque écriture crée un contexte neuf et produit les offsets et longueurs à partir des octets réellement émis.
- Les dictionnaires utilisent des objets `Entry`. `with()` et `without()` construisent des copies et préservent les contraintes des modèles spécialisés.
- Les arbres de pages et les plans utilisent des constructeurs de graphe mutables, avec un seul parent et des références explicites. Leur structure calcule les liens et compteurs ; les objets de définition de la norme sont immuables.
- Les filtres s'appliquent dans l'ordre inverse de leur ordre de décodage. Les flux sont toujours référencés comme objets indirects.

Voir [`docs/architecture.md`](docs/architecture.md) pour les choix de construction et [`docs/specification.md`](docs/specification.md) pour la traçabilité et les limites.

Le [guide des composants](docs/components.md) explique chaque famille d'objets. La [référence API](https://pdftoolkit.github.io/pdf/reference/index.html) donne les signatures et les liens vers le code et la norme. Les [instructions de construction du site](docs/site/README.md) décrivent la génération et les vérifications locales.

## Couverture et limites

Le catalogue recense les **392 tableaux**, **823 sections indexées** et **73 tokens d'opérateur** de la référence. Les tableaux de dictionnaires donnent lieu à des classes nommées pour leurs dictionnaires, leurs entrées et leurs définitions immuables. Les noms PDF conservent exactement leur casse, qui est significative dans le format.

Les implémentations exécutables couvrent les objets fondamentaux, l'écriture des fichiers, les tables et flux de références croisées, les objets compressés, les opérateurs de contenu, les arbres de pages/noms/nombres, les destinations et plans, les polices standard et les quatre représentations de fonction PDF.

**La présence d'une définition n'est pas une déclaration de conformité complète.** Le catalogue valide les types, références exigées, exigences inconditionnelles et certaines formes de valeurs. Les conditions, relations entre dictionnaires et règles de version qui ne disposent pas encore d'une validation spécialisée restent dans leurs définitions et dans le document de couverture.

Le core compose les objets de polices, les CMaps, les images et leurs masques, les formulaires, la structure balisée et les révisions incrémentales. Il écrit aussi les filtres, le chiffrement explicitement choisi, les signatures par plages d'octets, les références hybrides et la linéarisation. Les modules optionnels fournissent l'analyse TrueType, la conversion JPEG/PNG et la lecture de PDF externes. Les mécanismes vérifiés et les règles restantes figurent dans [l'inventaire de couverture](docs/coverage.md). La fabrication de sous-ensembles de programmes de police, les scripts complexes et l'import avec remappage d'un graphe externe demandent des composants dédiés. `EncodedData` permet de déclarer explicitement des octets déjà encodés. Les obligations de sécurité non prises en charge sont refusées.

Le writer assemble actuellement le fichier en mémoire. Le lecteur est fourni par `pdftoolkit/pdf-reader`. Un moteur de rendu et une API de mise en page ne font pas partie du core.

## Sécurité

La [politique de sécurité](SECURITY.md) décrit les signalements privés et les frontières de confiance. La [revue de sécurité](docs/security.md) explique les protections de sérialisation et les responsabilités de l'application pour les chemins, les actions PDF et les limites de ressources.

## Génération des définitions

Les ressources structurées et les classes générées sont versionnées. La génération est reproductible à partir de ces ressources ; elle n'a pas besoin de relire le PDF à l'exécution de la bibliothèque.

```sh
python tools/generate_specification.py
```

L'extracteur `tools/extract_spec.py` conserve la source et les localisations dans le PDF. Son utilisation demande les dépendances Python indiquées dans la documentation de la spécification. Les descriptions normatives servent à tracer les contraintes encore à implémenter ; les algorithmes ne sont pas remplacés par une génération de classes.
