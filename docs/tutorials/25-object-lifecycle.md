# Supprimer et réutiliser un objet indirect

Un objet indirect est identifié par son **numéro et sa génération**. Une mise
à jour peut libérer son numéro puis l'affecter à une nouvelle identité. L'ancienne
référence ne doit pas désigner cette nouvelle valeur.

Exécuter [object-lifecycle.php](../../examples/object-lifecycle.php) :

```bash
php examples/object-lifecycle.php /tmp/object-lifecycle.pdf
```

Le PDF produit contient trois révisions, une page et deux références documentaires
privées. La page reste visible ; la sortie console affiche les deux générations.

## Écrire la première révision

Une chaîne est référencée par le catalogue sous `PrivateOriginal`.
`FileStructure::toRevision()` retourne les octets et leurs objets gelés.
`binding($old)->identifier` donne l'identifiant attribué à la chaîne.

```php
$initial = $file->toRevision();
$identifier = $initial->binding($old)->identifier;
```

Les objets PDF restent construits par l'application. Le contexte de sérialisation
attribue leurs identifiants dans ce fichier ; aucun registre global ne relie
des documents différents.

## Libérer le numéro

`ReleaseIndirectObject` demande la libération de l'identifiant exact. Le writer
ajoute une section xref ; il ne retire aucun octet de la première révision.
La génération libre devient `1` et le numéro entre dans la liste libre.

```php
$released = (new IncrementalUpdate(
    $initial,
    new FileBody(),
    objectOperations: new ReleaseIndirectObject($identifier),
))->toRevision();
```

`PrivateOriginal` garde son ancienne référence de génération `0`. Une référence
à un objet inexistant vaut `null` dans PDF. Les dictionnaires peuvent donc garder
une référence facultative obsolète. Les liens obligatoires du graphe, comme les
ressources nécessaires d'une page, sont vérifiés séparément.

Les protections natives refusent la libération du catalogue racine, des pages,
de la structure xref et du dictionnaire de chiffrement. La suppression d'un
conteneur `ObjStm` exige d'avoir libéré tous ses membres encore comprimés.

## Affecter le numéro à une nouvelle identité

`ReuseObjectNumber` reçoit le numéro libre et une nouvelle valeur. Celle-ci est
ici un dictionnaire ; elle ne reprend pas les alias PHP de l'ancienne chaîne.
La nouvelle référence utilise la génération libre `1`.

```php
objectOperations: new ReuseObjectNumber($identifier->objectNumber, $new)
```

L'exemple remplace aussi le catalogue sous son identifiant existant pour ajouter
`PrivateCurrent`, une référence à ce dictionnaire. `reference($old)` garde le
couple original ; `reference($new)` donne le nouveau couple. Leurs numéros sont
égaux et leurs générations diffèrent.

Une seconde suppression incrémenterait la génération libre à `2`. La génération
`65535` est permanente et ne peut pas être réutilisée. Les trous de numérotation
jamais affectés sont distingués des objets supprimés : leur génération est `0`.
Un numéro provenant de la liste libre ne peut pas servir à un nouveau conteneur
ou membre `ObjStm`, même lorsqu'il provenait d'un trou de génération `0`.

## Conserver la preuve de ce qui a été écrit

Chaque `FileRevision` expose `previous`, les objets effectifs, les générations
retirées et une `FreeListState` immuable. Les snapshots conservent les références
remappées et les dictionnaires réellement émis. Pour un flux, ils conservent les
octets encodés avant chiffrement issus du même appel d'écriture. Ces snapshots
sont des preuves ; un `RevisionStreamSnapshot` ne peut pas être sérialisé comme
un nouveau flux. Une nouvelle écriture demande un nouvel objet de flux explicite.

`LinearizedWriter::revision()` fournit aussi ce snapshot. On peut lui ajouter
une révision incrémentale ; conformément à l'annexe F, le fichier résultant cesse
d'être linéarisé. Les octets initiaux, leur `/L` et leurs plages d'indices restent
inchangés. Ces mécanismes portent sur les fichiers générés ; lire l'historique
d'un PDF externe appartient au module lecteur.

## Vérifications et références

Le corpus de libération et réutilisation vérifie 90 historiques, soit 450 PDF,
sous PHP 8.3, 8.4 et 8.5. Il contrôle les listes libres, les générations, les
sections physiques xref, les anciennes et nouvelles références, les objets
comprimés et neuf variantes de sécurité. L'oracle utilise qpdf sans modification
et vérifie ses valeurs effectives. La limite de cache de pypdf sur certaines
générations obsolètes est identifiée ; ses nouvelles valeurs et flux restent
vérifiés sans exception générale.

Les révisions après linéarisation ont un corpus distinct de 90 historiques,
soit 270 PDF, avec contrôle exact des préfixes, des références et des indices.

Sources conservées : [objets indirects et null, page 29](../../PDF32000_2008.pdf#page=29),
[xref, pages 48–50](../../PDF32000_2008.pdf#page=48),
[mises à jour et ObjStm, pages 52–55](../../PDF32000_2008.pdf#page=52),
[annexe F, page 683](../../PDF32000_2008.pdf#page=683).

[Parcours des tutoriels](README.md) · [Révisions incrémentales](11-incremental-update.md)
