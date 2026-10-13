# 05. Liens internes et externes

[Parcours des tutoriels](README.md) · [Précédent : navigation](04-navigation.md) · [Suivant : images](06-images.md)

## But et prérequis

Créer deux zones cliquables sur une page : l'une ouvre le dépôt GitHub, l'autre affiche une seconde page. Ce tutoriel compose les classes issues des tableaux de la norme pour montrer comment utiliser une fonctionnalité qui n'a pas de façade spécialisée dans le domaine.

Lire [pages et texte](02-pages-and-text.md) et [destinations](04-navigation.md), puis installer les dépendances avec `composer install`. Le script [examples/links.php](../../examples/links.php) fonctionne seul sous PHP 8.5 avec les extensions demandées par Composer.

## Exécuter et regarder

Depuis la racine du dépôt :

```sh
php examples/links.php
php examples/links.php links-demo.pdf
```

Le premier appel produit `examples/links.pdf`. La première page affiche deux textes bleus ; le second mène à la page « Vous avez suivi le lien interne ». Le lien GitHub propose d'ouvrir une adresse externe, selon les réglages du lecteur.

![Aperçu de la page comportant les deux liens](previews/links.png)

L'image sert à repérer les textes. Tester les liens dans le PDF original avec un lecteur interactif : une image de page ne transporte pas les annotations.

## Dessin et interaction

Un `ShowText` produit des glyphes visibles. Ni leur couleur ni leur contenu ne créent de lien. L'interaction est portée par un autre objet : une annotation `/Subtype /Link`, référencée dans le tableau `/Annots` de la page.

La zone active est son `/Rect`, en coordonnées de page. Les quatre nombres sont les deux coins opposés, **x inférieur, y inférieur, x supérieur, y supérieur**, alors que l'opérateur graphique `re` utilise **x, y, largeur, hauteur**. Le script place les textes à l'intérieur de ces zones et utilise `/Border [0 0 0]` pour supprimer la bordure dessinée par le lecteur.

## Lire la construction

1. **Créer la page cible.** Le script construit d'abord le second `PageObject`. Le lien interne vise cette instance ; l'ordre final reste déterminé par les appels à `addKid()`.
2. **Décrire l'action externe.** `URIActionTable206` contient `SEntry(new NameType('URI'))` et `URIEntry(new LiteralString(...))`. `/S` est le type d'action ; `/URI` contient son adresse ASCII. L'annotation référence ce dictionnaire par son entrée `/A`.
3. **Décrire la destination interne.** La deuxième annotation reçoit `DestEntry(new Fit($targetPage))`. Cette fois, il n'y a pas d'action `/A` : `/Dest` est une destination explicite. La norme interdit d'ajouter `/Dest` lorsque `/A` est présent sur le même lien ; le script respecte cette règle de composition.
4. **Composer les entrées communes.** `TypeEntry`, `RectEntry`, `BorderEntry` et `ContentsEntry` proviennent du tableau 164. `SubtypeEntry`, `AEntry` et `DestEntry` proviennent du tableau 173. `LinkAnnotationTable173` compose la définition du tableau commun 164, notamment ses entrées obligatoires.
5. **Rattacher les annotations.** `AnnotsEntry` appartient au tableau 30, consacré à la page. Son `ArrayType` contient des `IndirectReference` vers les deux annotations. Une annotation garde ainsi sa propre identité d'objet PDF.
6. **Assembler l'arbre des pages.** Le premier `PageObject` reçoit `/Annots` avant son rattachement. On ajoute ensuite la page porteuse des liens, puis la cible, au même `PageTreeNode`.

`ContentsEntry(new TextString(...))` fournit une description textuelle de chaque annotation. Cette description contribue à l'interprétation de l'objet ; elle ne constitue pas, à elle seule, une structure complète d'accessibilité PDF.

## Pourquoi ces noms de classes ?

Les noms `LinkAnnotationTable173` et `URIActionTable206` conservent le lien avec les tableaux ISO. Leurs entrées et leurs définitions sont des objets PHP, et leurs contraintes connues sont vérifiées lors de la construction ou de la sérialisation. Le numéro distingue les tableaux portant des titres proches et facilite la consultation de la source.

Les définitions exposent aussi les règles conditionnelles dans leurs descriptions. Elles ne garantissent pas automatiquement toutes les relations possibles entre entrées. Ici, le code choisit volontairement une seule des deux cibles `/A` et `/Dest`, fournit les bons noms `/Link` et `/URI`, et emploie des références indirectes dans `/Annots`.

## Correspondance avec ISO 32000-1:2008

| Objet ou entrée | Référence |
| --- | --- |
| `/Annots` de la page | §7.7.3.3, tableau 30 |
| Entrées communes d'une annotation | §12.5.2, tableau 164 |
| Annotation `/Link` | §12.5.6.5, tableau 173 |
| Destination explicite `/Fit` | §12.3.2.2, tableau 151 |
| Action `/URI` | §12.6.4.7, tableau 206 |

Voir la [norme présente dans le dépôt](../../PDF32000_2008.pdf) et le [guide des définitions](../specification.md).

## Variantes et limites

Pour viser une destination nommée, remplacer le `Fit` par une `LiteralString` portant une clé publiée dans `/Names /Dests`, comme dans le [tutoriel de navigation](04-navigation.md). Pour déplacer un lien, modifier à la fois la position du texte et le rectangle de l'annotation : leur association est une décision de mise en page dans ce script.

La zone active est rectangulaire ; elle n'est pas calculée à partir de métriques de police. Les valeurs ASCII du texte visible évitent ici les problèmes de codage avec les polices standard. Il n'y a pas de retour automatique à la ligne ni d'apparence personnalisée d'annotation. Le lecteur peut demander une confirmation avant de suivre un lien externe.

[Retour au parcours](README.md) · [Continuer : images](06-images.md)
