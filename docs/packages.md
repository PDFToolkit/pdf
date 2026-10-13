# Core et modules optionnels

PDFToolkit sépare le modèle PDF des traitements de fichiers externes. Les quatre
packages utilisent PHP 8.3 ou supérieur et une CI sur PHP 8.3, 8.4 et 8.5.
Le core conserve son nom Composer `roukmoute/pdf` après son transfert dans
l'organisation ; les nouveaux modules utilisent le préfixe `pdftoolkit/`.

## La frontière du core

Le [core](https://github.com/PDFToolkit/pdf) représente les objets et mécanismes
décrits par **PDF 1.7 / ISO 32000-1:2008** : valeurs, références, dictionnaires,
flux, opérateurs, pages, polices, images, fonctions, écriture et révisions.
Les définitions gardent leurs noms PDF et leurs références au document Adobe.

PDF décrit, par exemple, les entrées `/Width`, `/ColorSpace`, `/SMask`,
`/FontFile2`, `/CIDToGIDMap` et `/ToUnicode`. Il permet d'incorporer des programmes
de fonte et des octets DCT ; il ne définit pas tout le format PNG ou OpenType.
Leurs lecteurs demandent d'autres spécifications et appartiennent aux modules.
L'écriture reste dans le core, car elle produit la syntaxe et la structure PDF.

| Dépôt et package | Entrée | Objets ou données produits |
| --- | --- | --- |
| [PDFToolkit/pdf](https://github.com/PDFToolkit/pdf) — `roukmoute/pdf` | Objets PDF assemblés par l'application | Fichier PDF, références croisées et révisions |
| [PDFToolkit/pdf-images](https://github.com/PDFToolkit/pdf-images) — `pdftoolkit/pdf-images` | Octets PNG ou JPEG et limites explicites | Échantillons couleur/alpha ou représentation DCT du core |
| [PDFToolkit/pdf-fonts](https://github.com/PDFToolkit/pdf-fonts) — `pdftoolkit/pdf-fonts` | Octets TrueType, texte et paramètres explicites | Programme incorporable, métriques et correspondances pour le core |
| [PDFToolkit/pdf-reader](https://github.com/PDFToolkit/pdf-reader) — `pdftoolkit/pdf-reader` | Octets PDF et budgets de lecture | Graphe lu, références à résoudre et flux encodés ou décodés |

Les dépendances vont **des modules vers le core**. Les modules ne se dépendent
pas entre eux. Le core ne connaît aucun de leurs parseurs : une application peut
construire directement ses objets PDF ou les alimenter avec un autre convertisseur.
Le modèle objet est commun, sans copie parallèle des primitives ni registre global.

## Installer ce dont l'application a besoin

Les packages sont actuellement distribués depuis GitHub, sans publication
Packagist. Les versions de développement compatibles PHP 8.3 sont `dev-master` ;
le tag du core `v0.1.1` conserve sa contrainte PHP 8.5.

Pour générer seulement des PDF :

```sh
composer config repositories.pdf vcs https://github.com/PDFToolkit/pdf
composer require roukmoute/pdf:dev-master
```

Pour ajouter la lecture de PDF existants :

```sh
composer config repositories.pdf-reader vcs https://github.com/PDFToolkit/pdf-reader
composer require roukmoute/pdf:dev-master pdftoolkit/pdf-reader:dev-master
```

Pour convertir des images, ajouter le dépôt `https://github.com/PDFToolkit/pdf-images`
et demander `pdftoolkit/pdf-images:dev-master`. Pour les fontes, utiliser
`https://github.com/PDFToolkit/pdf-fonts` et `pdftoolkit/pdf-fonts:dev-master`.
Dans chaque cas, le projet consommateur doit aussi déclarer le dépôt du core et
autoriser explicitement `roukmoute/pdf:dev-master` : Composer ne reprend pas les
dépôts déclarés par les dépendances. Conserver le `composer.lock` de l'application
pour fixer les révisions.

## Lire un PDF externe

Le lecteur reçoit ses octets ; l'application choisit comment les obtenir.
`DocumentReader`, `FileBytes` et `ReaderLimits` appartiennent au namespace
`PDF\Reading` du module, tandis que les dictionnaires et nombres utilisent
les mêmes classes `PDF\Syntax\Object` que le writer.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ReaderLimits;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;

$reader = new DocumentReader(new ReaderLimits(maxFileBytes: 8_388_608));
$document = $reader->read(new FileBytes($pdfBytes));
$catalog = $document->catalog();
$pages = $document->resolve(
    $catalog->get('Pages') ?? throw new UnexpectedValueException('Missing Pages.'),
);
if (!$pages instanceof DictionaryType) {
    throw new UnexpectedValueException('Pages must resolve to a dictionary.');
}
$count = $pages->get('Count');
if (!$count instanceof IntegerType) {
    throw new UnexpectedValueException('Count must be an integer.');
}
printf("Declared page count: %d\n", $count->integer());
```

`get()` conserve une référence comme référence. `resolve()` demande son chargement
dans ce document. Les objets lus sont conservés dans un cache propre au document ;
il n'y a ni singleton ni résolution cachée dans les valeurs. `/Count` est la valeur
déclarée par le PDF : cet exemple ne recompte pas les pages et ne valide pas tout
l'arbre.

Le lecteur gère les tables et flux xref, les révisions `/Prev`, les flux d'objets,
Flate, ASCIIHex, ASCII85 et RunLength, ainsi que certains prédicteurs TIFF/PNG.
Le chargement d'un flux garde ses octets encodés ; `streamData()` demande son
décodage. Il ne rend pas une page et n'interprète pas ses opérateurs pour extraire
du texte. PDF 2.0, le chiffrement, les références hybrides et les codecs non
implémentés sont refusés explicitement.

Les valeurs lues ne sont pas directement réinjectables dans le writer : leurs
références appartiennent au fichier d'origine. L'import et le remappage du graphe,
la réécriture, la fusion et la réparation restent à construire. Consulter
[la documentation du lecteur](https://github.com/PDFToolkit/pdf-reader#readme)
et [ses contrôles de sécurité](https://github.com/PDFToolkit/pdf-reader/blob/master/docs/security.md)
pour choisir ses budgets. Par défaut, les octets binaires conservés et le total
des flux décodés sont chacun bornés à 128 MiB, en plus des autres limites.

## Une documentation centrale, des installations indépendantes

Cette documentation présente l'API des quatre packages. Les fiches des modules
portent un badge **optionnel** et leurs liens de source utilisent le commit
du module fixé par le lock de construction. Cela n'ajoute aucune dépendance aux
applications qui installent uniquement le core.

Le checkout de développement du core installe les modules en `require-dev`
pour générer les tutoriels [TrueType](tutorials/09-embedded-font.md) et
[PNG/JPEG](tutorials/10-image-files.md) et exporter toutes les signatures.
Les objets PDF correspondants restent dans le core ; seuls les lecteurs de
formats externes et leurs services de conversion sont dans les modules.
