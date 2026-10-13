# 12. Lire les pages d'un PDF externe

Les premières leçons construisent un graphe et écrivent un PDF. Celle-ci ouvre
un fichier existant avec le module optionnel `pdftoolkit/pdf-reader` **0.1**, qui utilise le core **0.2**. Elle
parcourt son arbre de pages, consulte ses attributs effectifs et demande
séparément le décodage de ses flux. Les dictionnaires, nombres et rectangles
restent des objets du core.

Le [PDF d'entrée](https://github.com/PDFToolkit/pdf-reader/blob/master/tests/Fixtures/page-tree/classic.pdf)
est une fixture originale créée avec pypdf et qpdf, indépendamment du writer PHP.
Le [script complet](https://github.com/PDFToolkit/pdf-reader/blob/master/examples/pages.php)
appartient au dépôt du lecteur. Sur le site, ses trois pages, sa sortie console
réelle et son code commenté sont présentés après les explications.

## Exécuter l'exemple

Depuis le checkout du core, ses dépendances de développement incluent le lecteur :

```sh
composer install
php vendor/pdftoolkit/pdf-reader/examples/pages.php vendor/pdftoolkit/pdf-reader/tests/Fixtures/page-tree/classic.pdf
```

Le premier argument est un **fichier d'entrée**. Le script n'écrit aucun nouveau
PDF. Pour utiliser la variante à flux xref et objets comprimés :

```sh
php vendor/pdftoolkit/pdf-reader/examples/pages.php vendor/pdftoolkit/pdf-reader/tests/Fixtures/page-tree/object-streams.pdf
```

Les identifiants des objets changent dans cette variante, mais l'ordre et les
valeurs effectives des pages sont les mêmes. Pour une application indépendante,
le [guide des packages](../packages.md#installer-ce-dont-lapplication-a-besoin)
explique comment installer uniquement le core et le lecteur.

## 1. Ouvrir le document sans parcourir ses pages

L'application choisit le fichier et fournit ses octets :

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;

$document = (new DocumentReader())->read(new FileBytes($bytes));
```

`DocumentReader` contrôle le header, les références croisées, les révisions
et le catalogue. Son `ReadDocument` résout ensuite les objets à la demande,
avec des caches et des budgets propres à ce document. Cette ouverture ne
vérifie pas encore toutes ses pages ou ses ressources.

## 2. Parcourir et vérifier l'arbre

```php
use PDF\Reading\PageTreeLimits;
use PDF\Reading\PageTreeReader;

$tree = (new PageTreeReader($document, new PageTreeLimits(
    maxDepth: 64,
    maxNodes: 10_000,
    maxPages: 5_000,
)))->read();
```

Le catalogue désigne une racine `/Pages`. Ses branches possèdent `/Kids` et
`/Count` ; ses feuilles sont des dictionnaires `/Page`. Le parcours suit
l'ordre de `/Kids`, vérifie les relations `/Parent` et compare chaque compte
déclaré au nombre de feuilles réellement rencontré. Les cycles et identités
répétées sont refusés.

`ReadPageTree` est comptable et itérable. `count($tree)` vaut trois pour cette
entrée ; `$tree->page(0)` donne la première page. Chaque `ReadPage` garde son
document propriétaire, sa référence et son dictionnaire source. Les limites
de profondeur, de nœuds et de feuilles appartiennent à ce parcours, en plus
des budgets de chargement du document.

## 3. Distinguer valeurs écrites et attributs effectifs

Quatre entrées sont héritables : `/Resources`, `/MediaBox`, `/CropBox` et
`/Rotate`. Le lecteur cherche l'ancêtre le plus proche qui les définit, puis
construit les `PageAttributes` de la feuille. CropBox utilise MediaBox par
défaut ; Rotate utilise zéro si aucun ancêtre ou page ne le définit.
Null est traité comme une absence.

| Page | Origine des attributs et particularité | Rotation brute / orientation |
| --- | --- | --- |
| 1 | Hérite des ressources Courier et des deux rectangles de la branche. | `-90` / `270` |
| 2 | Définit un MediaBox, une rotation zéro et des ressources vides ; hérite du CropBox de la sous-branche. | `0` / `0` |
| 3 | Hérite de la racine, sans reprendre les valeurs de la branche précédente. | `90` / `90` |

Un dictionnaire de ressources local remplace celui de l'ancêtre dans son
ensemble : il n'y a pas de fusion des catégories. C'est pourquoi les ressources
vides de la deuxième page n'utilisent aucune police héritée.
Les propriétés effectives sont un `DictionaryType`, deux `Rectangle` et un
`IntegerType`. `normalizedRotation()` calcule une orientation sans remplacer
l'entier original. Le dictionnaire source conserve ses seules entrées locales.

## 4. Consulter le contenu puis demander son décodage

`/Contents` est une entrée locale, jamais héritée. Elle peut désigner un flux
unique ou un tableau de flux. Sa résolution appartient à un service séparé :

```php
use PDF\Reading\PageContentLimits;
use PDF\Reading\PageContentsReader;

$contentReader = new PageContentsReader($document, new PageContentLimits(
    maxStreams: 128,
));

foreach ($tree as $page) {
    $contents = $contentReader->read($page);
    foreach ($contents as $stream) {
        $encodedBytes = $stream->encodedBytes();
        $decodedBytes = $document->streamData($stream);
    }
}
```

`PageContents` conserve les `EncodedStream` séparément, dans leur ordre.
Ce service ne concatène pas les données et n'applique aucun filtre.
`streamData()` demande le décodage sous les budgets cumulatifs de
`ReadDocument` ; les demandes suivantes réutilisent son cache.

Les pages ont respectivement deux, deux et zéro flux. La première associe
un flux brut et un flux Flate. La deuxième utilise un tableau indirect,
dont un contenu possède des données binaires d'image en ligne. Ces données
contiennent même des mots tels que `endstream` : leur présence ne termine
pas le flux, dont les limites sont définies par `/Length`.
La troisième page omet `/Contents` et donne une collection vide.

## Comprendre l'aperçu et la sortie console

La première page affiche deux lignes, avec la rotation héritée de sa branche.
La deuxième apparaît vide : son image en ligne occupe une petite région près
de l'origine, hors du CropBox. La troisième est vide puisqu'elle n'a aucun
contenu. Cette entrée sert à vérifier des structures et des données binaires ;
le nombre de flux ne prédit pas le nombre d'éléments visibles sur une page.

La sortie console indique trois pages vérifiées, leurs rectangles effectifs,
leurs rotations et le nombre de catégories de ressources. Pour chaque flux,
elle compare la longueur encodée et la longueur décodée. Ces octets restent du
langage PDF : le lecteur ne les interprète pas pour extraire le texte ou rendre
une page. Les aperçus du site sont produits indépendamment avec Poppler.

## À essayer

- Lire la variante `object-streams.pdf` et comparer ses références avec celles du fichier traditionnel.
- Réduire `maxPages` à deux pour constater le refus de ce document à trois pages.
- Consulter `$tree->page(0)->dictionary->get('Rotate')` puis son attribut effectif : la première page n'écrit pas de rotation locale.
- Conserver les flux sous leur forme encodée en omettant la demande `streamData()`.

## Vérification et limites

Les [fixtures indépendantes](https://github.com/PDFToolkit/pdf-reader/blob/master/tests/Fixtures/page-tree/README.md)
ont des valeurs attendues vérifiées avec pypdf et qpdf, puis avec les tests PHP.
La documentation conserve exactement les octets de l'entrée, exécute le script
du module verrouillé et rend ses trois pages.

Le parcours ne valide pas toutes les ressources, annotations ou actions du PDF.
Le chiffrement, les références hybrides, PDF 2.0 et les codecs non implémentés
restent refusés. L'import dans le writer, la fusion, la réécriture, l'extraction
de texte et le rendu nécessitent leurs mécanismes dédiés. Le
[guide du lecteur](https://github.com/PDFToolkit/pdf-reader/blob/master/docs/pages.md)
et ses [contrôles de sécurité](https://github.com/PDFToolkit/pdf-reader/blob/master/docs/security.md)
précisent les responsabilités et les budgets.

Références : ISO 32000-1:2008, **7.7.3** et tableaux **29–30** pour l'arbre,
les pages et l'héritage ; **7.8.2** pour les flux de contenu ; **7.9.5** pour
les rectangles.

[← 11. Révisions incrémentales](11-incremental-update.md) · [Tous les tutoriels](README.md)
