# PDF

Un modèle objet explicite de **PDF 1.7 / ISO 32000-1:2008**, pour PHP 8.5.

Cette bibliothèque représente la syntaxe et les structures du format, jusque dans ses noms, nombres, références, entrées de dictionnaire et opérateurs. Elle ne cherche pas à fournir une API de mise en page. Une bibliothèque de plus haut niveau peut construire ces objets et conserver le contrôle de leur composition.

La série `0.x` est une première publication destinée aux développeurs qui souhaitent construire et explorer ce modèle. L'API peut évoluer avant la version `1.0` ; elle ne garantit pas la compatibilité avec l'ancien prototype PHP 7.4. Le périmètre implémenté et les limites figurent dans [la couverture de la spécification](docs/specification.md).

La référence est le document Adobe/ISO `PDF32000_2008.pdf` déjà présent dans ce dépôt. PDF 2.0 et les profils PDF/A, PDF/X et PDF/UA constituent des spécifications supplémentaires.

## Installation

PHP 8.5 et les extensions `ctype`, `iconv` et `zlib` sont nécessaires.

La version est identifiée par le tag Git `v0.1.0`. Avec le dépôt déclaré comme source Composer :

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

## Identité et responsabilités

- Les valeurs PDF sont des objets. Les chaînes représentent des octets ; `TextString` réalise explicitement l'encodage du texte Unicode.
- Une `IndirectReference` vise une instance. Un `SerializationContext` attribue les identifiants pendant l'écriture d'un seul fichier. Il n'existe aucun singleton ni compteur global.
- `FileStructure` décrit le fichier ; `FileWriter` l'écrit. Chaque écriture crée un contexte neuf et produit les offsets et longueurs à partir des octets réellement émis.
- Les dictionnaires utilisent des objets `Entry`. `with()` et `without()` construisent des copies et préservent les contraintes des modèles spécialisés.
- Les arbres de pages et les plans utilisent des constructeurs de graphe mutables, avec un seul parent et des références explicites. Leur structure calcule les liens et compteurs ; les objets de définition de la norme sont immuables.
- Les filtres s'appliquent dans l'ordre inverse de leur ordre de décodage. Les flux sont toujours référencés comme objets indirects.

Voir [`docs/architecture.md`](docs/architecture.md) pour les choix de construction et [`docs/specification.md`](docs/specification.md) pour la traçabilité et les limites.

## Couverture et limites

Le catalogue recense les **392 tableaux**, **823 sections indexées** et **73 tokens d'opérateur** de la référence. Les tableaux de dictionnaires donnent lieu à des classes nommées pour leurs dictionnaires, leurs entrées et leurs définitions immuables. Les noms PDF conservent exactement leur casse, qui est significative dans le format.

Les implémentations exécutables couvrent les objets fondamentaux, l'écriture des fichiers, les tables et flux de références croisées, les objets compressés, les opérateurs de contenu, les arbres de pages/noms/nombres, les destinations et plans, les polices standard et les quatre représentations de fonction PDF.

**La présence d'une définition n'est pas une déclaration de conformité complète.** Le catalogue valide les types, références exigées, exigences inconditionnelles et certaines formes de valeurs. Les conditions, relations entre dictionnaires et règles de version qui ne disposent pas encore d'une validation spécialisée restent dans leurs définitions et dans le document de couverture.

Les moteurs de chiffrement et de signature, l'incorporation et le sous-ensemble de polices, les codecs d'image spécialisés, les mises à jour incrémentales et la linéarisation restent à implémenter. Les dictionnaires concernés peuvent être construits ; cela ne réalise pas ces traitements. `EncodedData` permet de déclarer explicitement des octets déjà encodés. Le writer refuse les options de sécurité et de mise à jour qu'il ne sait pas exécuter.

Le writer assemble actuellement le fichier en mémoire. Un lecteur PDF, un moteur de rendu et une API de mise en page ne font pas partie de cette bibliothèque de création.

## Génération des définitions

Les ressources structurées et les classes générées sont versionnées. La génération est reproductible à partir de ces ressources ; elle n'a pas besoin de relire le PDF à l'exécution de la bibliothèque.

```sh
python tools/generate_specification.py
```

L'extracteur `tools/extract_spec.py` conserve la source et les localisations dans le PDF. Son utilisation demande les dépendances Python indiquées dans la documentation de la spécification. Les descriptions normatives servent à tracer les contraintes encore à implémenter ; les algorithmes ne sont pas remplacés par une génération de classes.
