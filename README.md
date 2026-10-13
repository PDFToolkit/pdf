# PDF

Un modèle objet explicite de **PDF 1.7 / ISO 32000-1:2008**, pour PHP 8.5.

Cette bibliothèque représente la syntaxe et les structures du format, jusque dans ses noms, nombres, références, entrées de dictionnaire et opérateurs. Elle ne cherche pas à fournir une API de mise en page. Une bibliothèque de plus haut niveau peut construire ces objets et conserver le contrôle de leur composition.

La [documentation en ligne](https://roukmoute.github.io/pdf/) réunit les guides, la référence de tous les composants et les tutoriels avec leur code et leurs PDF consultables. Elle suit la branche `master` ; les différences avec la dernière version publiée sont indiquées dans les guides et le changelog.

La série `0.x` est une première publication destinée aux développeurs qui souhaitent construire et explorer ce modèle. L'API peut évoluer avant la version `1.0`. Le périmètre implémenté et les limites figurent dans [la couverture de la spécification](docs/specification.md).

La référence est le document Adobe/ISO `PDF32000_2008.pdf` déjà présent dans ce dépôt. PDF 2.0 et les profils PDF/A, PDF/X et PDF/UA constituent des spécifications supplémentaires.

## Installation

PHP 8.5 et les extensions `ctype`, `iconv` et `zlib` sont nécessaires.

La version corrective est identifiée par le tag Git `v0.1.1`. Avec le dépôt déclaré comme source Composer :

```sh
composer config repositories.roukmoute-pdf vcs https://github.com/roukmoute/pdf
composer require roukmoute/pdf:^0.1
```

## Développement et vérifications

Depuis un clone du dépôt :

```sh
composer install
composer check
php examples/hello-world.php hello-world.pdf
php examples/object-stream.php object-stream.pdf
```

Le code de production dépend uniquement de PHP et de ces trois extensions. PHPUnit, PHPStan et PHP-CS-Fixer sont des dépendances de développement verrouillées dans `composer.lock`. Les tests échouent aussi sur les dépréciations et avertissements PHP. Le [changelog](CHANGELOG.md) décrit chaque version publiée.

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

Le [guide des composants](docs/components.md) explique chaque famille d'objets. La [référence API](https://roukmoute.github.io/pdf/reference/index.html) donne les signatures et les liens vers le code et la norme. Les [instructions de construction du site](docs/site/README.md) décrivent la génération et les vérifications locales.

## Couverture et limites

Le catalogue recense les **392 tableaux**, **823 sections indexées** et **73 tokens d'opérateur** de la référence. Les tableaux de dictionnaires donnent lieu à des classes nommées pour leurs dictionnaires, leurs entrées et leurs définitions immuables. Les noms PDF conservent exactement leur casse, qui est significative dans le format.

Les implémentations exécutables couvrent les objets fondamentaux, l'écriture des fichiers, les tables et flux de références croisées, les objets compressés, les opérateurs de contenu, les arbres de pages/noms/nombres, les destinations et plans, les polices standard et les quatre représentations de fonction PDF.

**La présence d'une définition n'est pas une déclaration de conformité complète.** Le catalogue valide les types, références exigées, exigences inconditionnelles et certaines formes de valeurs. Les conditions, relations entre dictionnaires et règles de version qui ne disposent pas encore d'une validation spécialisée restent dans leurs définitions et dans le document de couverture.

L'incorporation de TrueType statique, l'encodage Unicode via CID et ToUnicode, l'import JPEG/PNG et les révisions incrémentales de fichiers générés disposent de mécanismes dédiés et de tutoriels. Les limites détaillées figurent dans [la couverture](docs/specification.md). Les moteurs de chiffrement et de signature, le sous-ensemble de polices, les scripts complexes, les codecs d'image supplémentaires, l'import de PDF externes et la linéarisation restent à implémenter. `EncodedData` permet de déclarer explicitement des octets déjà encodés. Le writer refuse les traitements de sécurité qu'il ne sait pas exécuter.

Le writer assemble actuellement le fichier en mémoire. Un lecteur PDF, un moteur de rendu et une API de mise en page ne font pas partie de cette bibliothèque de création.

## Sécurité

La [politique de sécurité](SECURITY.md) décrit les signalements privés et les frontières de confiance. La [revue de sécurité](docs/security.md) explique les protections de sérialisation et les responsabilités de l'application pour les chemins, les actions PDF et les limites de ressources.

## Génération des définitions

Les ressources structurées et les classes générées sont versionnées. La génération est reproductible à partir de ces ressources ; elle n'a pas besoin de relire le PDF à l'exécution de la bibliothèque.

```sh
python tools/generate_specification.py
```

L'extracteur `tools/extract_spec.py` conserve la source et les localisations dans le PDF. Son utilisation demande les dépendances Python indiquées dans la documentation de la spécification. Les descriptions normatives servent à tracer les contraintes encore à implémenter ; les algorithmes ne sont pas remplacés par une génération de classes.
