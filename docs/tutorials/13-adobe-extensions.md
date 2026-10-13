# Extensions Adobe et numérotation Bates

Le [supplément Adobe de juin 2008](../../adobe_supplement_iso32000.pdf)
étend PDF 1.7 au niveau Adobe 3. Une extension conserve le format PDF de base
et déclare les propriétés supplémentaires que le lecteur peut rencontrer.

Cet exemple utilise les déclarations d'extension et un artefact Bates. Les numéros
Bates sont des identifiants choisis par l'application pour indexer des documents,
par exemple `DOC-000001` et `EXH-000019`. Ils ne sont pas nécessairement les
numéros des pages du fichier.

## Exécuter l'exemple

```sh
php examples/adobe-extensions.php adobe-extensions.pdf
```

Le [script complet](../../examples/adobe-extensions.php) est commenté étape par
étape. Le résultat contient une page et deux identifiants affichés en pied de page.
Ce tutoriel dépend uniquement du core.

## Déclarer l'extension

```php
$extensions = new DeveloperExtensions(new DeveloperExtensionEntry(
    new DeveloperPrefix(new NameType('ADBE')),
    new DeveloperExtension(new NameType('1.7'), new IntegerType(3)),
));
$catalog = new DocumentCatalog($pages, new Extensions($extensions));
```

`DeveloperPrefix` conserve le préfixe sensible à la casse. `ADBE` identifie Adobe.
`DeveloperExtension` assemble `/BaseVersion /1.7` et `/ExtensionLevel 3`.
`DeveloperExtensionEntry` associe le préfixe à cette instance. Les déclarations
d'extension et toutes leurs entrées restent directes, comme l'exige ISO 32000-1,
§7.12.1 : aucun objet indirect n'intervient dans cette partie du catalogue.
Enfin, `Extensions` place ce dictionnaire dans le catalogue du document.

La version de base déclarée décrit la spécification étendue. Elle n'est pas
calculée à partir de l'en-tête du fichier. Le modèle permet plusieurs préfixes,
avec des versions de base et des niveaux indépendants. La bibliothèque ne
procède pas à une inscription automatique dans un registre de préfixes.

## Construire l'artefact Bates

```php
$numbers = new ArrayType(
    new LiteralString('DOC-000001'),
    new LiteralString('EXH-000019'),
);
$bates = new BatesArtifact($numbers);
```

L'objet produit `/Type /Pagination`, `/Subtype /BatesN` et `/Contents`.
`Contents` accepte une chaîne pour un seul identifiant, ou un tableau de chaînes
pour plusieurs identifiants. Leur ordre reste celui choisi par l'application.
Les valeurs sont des objets PDF ; elles ne peuvent pas être remplacées par des
entiers ou des dictionnaires via une entrée générique.

## Relier les propriétés au dessin

```php
new BeginMarkedContentWithProperties(new NameType('Artifact'), $bates),
// BT, Tf, Td et Tj dessinent ici le pied de page.
new EndMarkedContent(),
```

`BDC` ouvre la séquence marquée `/Artifact` avec ses propriétés ; `EMC` la ferme.
Les opérateurs texte ordinaires placent et dessinent les identifiants. Les
propriétés Bates décrivent ce contenu, mais ne créent aucun texte visible à elles
seules. L'exemple fournit donc les numéros aux propriétés et au texte affiché.

`ContentStreams` contrôle l'équilibre des séquences marquées à l'écriture.
Le contrôle indépendant de l'exemple vérifie aussi la déclaration `/ADBE`, la
classification `/BatesN`, l'ordre des deux chaînes et leur présence visible.

## Expériences et références

- Remplacer le tableau de chaînes par une seule chaîne et modifier le texte affiché.
- Ajouter un préfixe de développeur avec son propre niveau pour observer les deux déclarations.
- Déplacer le pied de page en modifiant les coordonnées fournies à `MoveTextPosition`.

Le mécanisme de déclaration figure aux pages 23–25 du supplément et dans
ISO 32000-1, §7.12. L'artefact Bates figure au tableau 10.17, page 100 du
[supplément conservé dans le dépôt](../../adobe_supplement_iso32000.pdf#page=100).
Le [guide du supplément](../adobe-supplement.md) détaille la couverture des autres
extensions et les règles de provenance.
