# 11. Mettre à jour un PDF par révisions

Une mise à jour incrémentale ajoute des objets et une nouvelle section de références croisées à la fin du fichier. Les octets déjà écrits restent intacts. Cet exemple crée un document, remplace deux fois son flux de contenu et conserve la police, la page et le catalogue initiaux.

## Exécuter l'exemple

```sh
php examples/incremental-update.php incremental-update.pdf
```

Le [script complet](../../examples/incremental-update.php) produit une page affichant « Revision 2 : contenu mis a jour. ». Le fichier contient aussi les octets des révisions 0 et 1, mais le lecteur utilise les derniers enregistrements xref pour résoudre le flux de la page.

![Aperçu de la dernière révision](previews/incremental-update.png)

## 1. Figer une révision initiale

`FileStructure::toRevision()` confie le graphe au `FileWriter` et reçoit un `FileRevision`. Cet objet contient les octets sérialisés, la position du dernier xref, le `/Size`, le catalogue, les entrées additionnelles du trailer et les identités documentaires. Il ne lit pas un fichier PDF existant.

```php
$initial = $file->toRevision();
$identifier = $initial->binding($initialContents)->identifier;
```

Un numéro d'objet appartient à cette révision. `binding()` retrouve son identifiant à partir d'une instance ou d'un `ObjectIdentifier` explicite. `reference()` retourne une `IndirectReference` ordinaire vers la valeur effective ; les contraintes de type des dictionnaires restent donc disponibles.

## 2. Sélectionner le remplacement

Une nouvelle instance de `StreamObject` représente le nouveau contenu. L'`IndirectObject` conserve le numéro et la génération de l'ancien flux :

```php
$update = new IncrementalUpdate(
    $initial,
    new FileBody(new IndirectObject($newContents, $identifier)),
);
$next = $update->toRevision();
```

Le `FileBody` sélectionne les objets à écrire. Une référence vers un objet d'une révision précédente produit seulement `n g R` : elle ne reparcourt pas son graphe et ne le réécrit pas. Une instance historique explicitement placée dans le `FileBody` est en revanche réécrite sous son identifiant existant. Modifier un builder après l'instantané ne change pas les octets déjà figés.

Les alias historiques sont conservés lors des remplacements. Une référence PHP ancienne au flux initial reste une référence vers le même numéro PDF, même après plusieurs mises à jour ; `reference()` retrouve désormais la nouvelle valeur effective. Les objets nouvellement référencés reçoivent des numéros à partir du `/Size` précédent, sans réutiliser les trous anciens, et commencent à génération zéro. Un remplacement conserve la famille PDF de la valeur et son `/Type` lorsqu'il était défini : les références historiques ne peuvent pas transformer silencieusement un flux en nombre ou un arbre de pages en autre dictionnaire.

## 3. Relier les références croisées

Le `IncrementalWriter` commence par les octets de `$initial`. Il mesure les nouveaux offsets depuis le début du fichier complet. La nouvelle entrée `/Prev` vise exactement la section xref de la révision précédente ; `startxref` vise celle qui vient d'être ajoutée.

Le format xref est hérité de la révision initiale. L'exemple utilise des flux xref ; les tables traditionnelles sont également prises en charge. Un nouveau `ObjectStream` peut contenir des objets nouveaux ou remplacés avec génération zéro. Un ancien conteneur n'est pas remplacé : on en construit un nouveau. Un membre auparavant compressé peut aussi devenir un objet autonome sous le même numéro.

`CrossReferenceSection` distingue l'index initial complet et les sections incrémentales partielles. Les numéros nouveaux inutilisés sont représentés par des entrées libres et chaînés à la liste libre existante. `CrossReferenceLimits` borne la capacité cumulée à 100 000 entrées par défaut ; un writer peut recevoir une limite explicitement différente.

## 4. Hériter du trailer

Toutes les entrées additionnelles du trailer précédent sont reprises, y compris les extensions privées. Les entrées fournies par la nouvelle révision les remplacent individuellement. Dans l'exemple, `/Info` vise un nouveau titre, tandis que le catalogue reste inchangé.

`/Size`, `/Root`, `/Prev` et les structures xref sont gérés par les writers. L'original `/ID[0]` est préservé. Si `/ID` existe et n'est pas remplacé explicitement, le second identifiant est renouvelé avec une empreinte déterministe du contenu de la révision. Cette empreinte est un identifiant de fichier ; elle ne constitue pas une signature. Un `/ID` explicite doit conserver le premier identifiant.

## À essayer

- Utiliser `CrossReferenceFormat::Table` pour obtenir des sections `xref` textuelles.
- Ajouter une troisième mise à jour et compter les `startxref` et les `/Prev`.
- Remplacer uniquement `/Info` pour modifier les métadonnées sans réécrire le contenu de page.
- Conserver une ancienne référence au flux et l'utiliser dans une nouvelle valeur pour observer la conservation de son numéro.

## Vérification et limites

```sh
qpdf --check incremental-update.pdf
qpdf --show-xref incremental-update.pdf
```

Les révisions prises en charge proviennent des objets `FileRevision` produits par la bibliothèque. L'import et l'analyse de PDF externes restent à implémenter. La suppression et le réemploi d'objets, les changements de format xref, les références hybrides, les fichiers chiffrés, les signatures et la linéarisation demandent leurs mécanismes propres. Une mise à jour incrémentale ne retire pas les contenus anciens du fichier : elle ne doit pas servir à effacer une information confidentielle.

Références : ISO 32000-1:2008, **7.5.4–7.5.8** pour xref, trailers, révisions et conteneurs d'objets, et **14.4** pour les identifiants de fichier.

[← 10. Images JPEG et PNG](10-image-files.md) · [Tous les tutoriels](README.md)
