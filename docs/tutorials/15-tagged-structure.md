# 15. Relier le contenu à sa structure logique

Le [script complet](../../examples/tagged-structure.php) construit trois pages :
un titre, un paragraphe continuant sur la seconde page, un tableau avec en-tête
identifié et un lien associant son texte et son annotation.

```sh
php examples/tagged-structure.php tagged-structure.pdf
```

Les opérateurs dessinent le contenu visible. Une séquence `BDC`/`EMC` donne un
`MCID` à une portion de contenu. Cet entier est local à son conteneur ; une autre
page peut avoir le même entier. Plusieurs flux `/Contents` d'une page sont lus
dans leur ordre, y compris si une séquence commence dans l'un et finit dans l'autre.

`StructureTreeRoot` et `StructureElement` composent l'ordre de lecture logique,
indépendamment des positions. Les enfants `/K` peuvent être d'autres éléments,
un contenu de page, un `MCR` précisant une autre page ou un `OBJR` vers une
annotation. L'exemple utilise un `MCR` pour le paragraphe continu et un `OBJR`
pour la zone interactive du lien.

`ParentTree` établit les liens inverses depuis `StructParents` et `StructParent`.
`IDTree` identifie l'en-tête du tableau ; `Headers` de sa cellule utilise cet
identifiant. `ClassMap` partage les attributs en conservant les révisions propres
à chaque association.

Les pieds de page sont des artefacts. La langue `fr-FR` et `MarkInfo.Marked`
déclarent les conventions choisies. Les objets contrôlent rôles, associations et
identités ; la qualité de l'ordre de lecture et les alternatives restent une
responsabilité éditoriale. Cet exemple n'est pas une certification PDF/UA.

Modifier les MCID, les liens vers les pages ou les identifiants d'en-tête montre
pourquoi ces valeurs dépendent de l'ensemble du graphe.

Référence : ISO 14.7–14.9, pages physiques 564–628.

[Index](README.md) · [Code complet](../../examples/tagged-structure.php)
