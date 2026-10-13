# Fichiers incorporés et collections

Cet exemple construit un dossier PDF avec un CSV, des notes et un export JSON
associé au CSV. La page du contenant explique ses relations ; les fichiers sont
réellement incorporés et peuvent être extraits par un lecteur compatible.

Exécuter [embedded-files.php](../../examples/embedded-files.php) :

```bash
php examples/embedded-files.php /tmp/embedded-files.pdf
```

## Fournir les octets, puis décrire le fichier

`EmbeddedFileStream` reçoit les octets originaux et un nom de sous-type MIME.
Son dictionnaire `Params` calcule `Size` et le checksum MD5 sur ces octets,
avant les filtres et le chiffrement. Ce checksum est une donnée du format ;
il ne certifie ni l'origine du fichier ni la confiance à lui accorder.

```php
$csv = new EmbeddedFileStream(
    "article,montant\nA,12.50\nB,7.25\n",
    new NameType('text/csv'),
    filters: new FlateDecode(),
);
```

`FileSpecification` décrit ensuite le nom `F`. `EmbeddedFileBindings` fournit
les références indirectes de `EF`. Si `EF.UF` est choisi, il faut aussi fournir
le nom Unicode `UF` avec `FileSpecificationName`. Plusieurs noms peuvent
pointer vers le même flux. Les noms restent des valeurs PDF ; le core n'ouvre
aucun chemin pour en deviner le contenu.

Les informations facultatives restent des objets séparés : description,
identifiants, dates, caractère volatile et informations Mac. `MacFileCode`
interprète explicitement quatre octets ASCII comme un entier de poids fort
en premier. Le fork de ressources est lui aussi un flux fourni par l'application.

## Relier un export à son fichier principal

`RelatedFiles` construit les couples nom/flux de `RF.F`. L'exemple y place
`total.json`. La clé `F` doit déjà être présente dans `EF`, et chaque valeur
de `RF` contient des couples complets dont les flux sont indirects.

`EmbeddedFiles` construit le vrai `NameTree` de `Names.EmbeddedFiles`.
Ses clés `Mesures` et `Notes` désignent des spécifications de fichiers,
elles-mêmes indirectes. Ces clés sont distinctes des noms `mesures.csv`
et `notes.txt`. L'ordre des clés et les limites des branches sont contrôlés
sur le graphe effectif, y compris après une mise à jour incrémentale.

## Construire les métadonnées de la collection

`CollectionSchema` associe des noms de champs à des `CollectionField`.
L'exemple distingue un texte, un nombre et une date. Un `CollectionItem`
est construit avec ce schéma ; le nom d'un champ inconnu ou un texte placé
dans le champ numérique est refusé. `FileSpecificationOptions` inscrit
l'item sous `CI` avec une référence indirecte.

`CollectionSubitem` sépare la donnée `D` du préfixe de présentation `P`.
Pour les mesures, `D` vaut `19.75` et `P` vaut `EUR `. Le préfixe n'est pas
ajouté au nombre. Pour les notes, le préfixe existe sans `D` : la norme
autorise cette absence et aucun montant n'est inventé.

`CollectionSort` nomme ses champs dans `S`. Un booléen `A` s'applique à tous
les champs ; un tableau fournit les directions une à une. Une direction
manquante vaut `true` et les directions supplémentaires sont ignorées.
Ici, `Amount` descend et `Title` prend la direction ascendante par défaut.

`Collection.D` vaut `Mesures`, une clé du véritable arbre des fichiers
incorporés. Si cette clé manque, la sélection initiale revient au contenant,
comme le prévoit la norme. Le mode de vue et le schéma décrivent une interface
que le lecteur PDF peut présenter ; la page dessinée reste consultable même
dans un lecteur qui n'affiche pas cette interface.

## Déclarer un flux dont les données sont externes

`ExternalStreamObject` traite l'autre cas de la table 5 : `F` désigne un fichier
externe, `FFilter` et `FDecodeParms` décrivent son décodage. Le core ne charge
pas ce fichier et n'exécute aucun encodeur de `ExternalStreamFilters`.
Les octets locaux entre `stream` et `endstream` sont ignorés, mais leur longueur
reste exacte. Ils restent en clair sous chiffrement PDF ; les chaînes du
dictionnaire, dont le nom de fichier, suivent le chiffrement normal.

Ce mécanisme est couvert par les tests ; l'exemple consultable emploie des
fichiers incorporés pour que ses données accompagnent le PDF téléchargé.

## Vérifications et références

Le corpus indépendant vérifie 90 historiques, soit 180 PDF, avec trois formats
xref, des objets comprimés, la linéarisation et les chiffrements R2 à R5.
Les contenus extraits, `Size`, le MD5, les forks Mac, les liens `EF`/`RF`/`CI`,
les limites de l'arbre et les mises à jour de valeurs sont vérifiés avec
pypdf, `hashlib` et qpdf. Les pages et le rendu de l'exemple sont aussi inspectés.

Sources conservées : [table 5, page 28](../../PDF32000_2008.pdf#page=28),
[spécifications et flux de fichiers, pages 107–115](../../PDF32000_2008.pdf#page=107),
[collections, pages 378–381](../../PDF32000_2008.pdf#page=378).

[Parcours des tutoriels](README.md) · [Couverture vérifiée](../coverage.md)
