# 04. Signets et destinations

[Parcours des tutoriels](README.md) · [Précédent : graphiques](03-graphics.md) · [Suivant : liens](05-links.md)

## But et prérequis

Créer trois pages, un plan hiérarchique de signets et des destinations nommées. Les tutoriels [premier document](01-first-document.md) et [pages et texte](02-pages-and-text.md) expliquent déjà les ressources, les flux et l'arbre des pages. Installer les dépendances avec `composer install` ; PHP 8.5 et les extensions indiquées par Composer sont nécessaires.

Le script complet est [examples/navigation.php](../../examples/navigation.php). Chaque étape y est commentée ; il fonctionne seul et n'utilise aucun fichier produit par les autres tutoriels.

## Exécuter et regarder

Depuis la racine du dépôt :

```sh
php examples/navigation.php
# Destination personnalisée, dans un répertoire existant :
php examples/navigation.php navigation-demo.pdf
```

Sans argument, le fichier est `examples/navigation.pdf`. Ouvrir le PDF dans un lecteur disposant d'un panneau de signets. Le résultat contient trois chapitres. Le signet « Comprendre la navigation » contient trois enfants ; cliquer sur le deuxième affiche le deuxième chapitre à un zoom de 100 %. Les autres ajustent la page entière. Le catalogue demande l'ouverture du panneau, mais cette préférence dépend du lecteur.

![Aperçu de la première page du tutoriel de navigation](previews/navigation.png)

L'aperçu est une image de la page ; les signets se trouvent dans la structure interactive du PDF, en dehors de son dessin.

## Lire la construction

1. **Les pages et le plan sont deux arbres.** `PageTreeNode::addKid()` détermine l'ordre de lecture. `OutlineDictionary::addItem()` et `OutlineItem::addItem()` construisent un plan indépendant : on peut choisir une hiérarchie éditoriale différente de celle des pages.
2. **Une destination vise un objet Page.** `new Fit($page)` construit le tableau `[référence-à-la-page /Fit]`. Le numéro de page affiché dans un lecteur découle de sa place dans l'arbre ; il ne sert pas d'identifiant à cette destination.
3. **`XYZ` précise la vue.** Le script passe trois objets `NumericType` de valeurs 0, 842 et 1 : le point visé est le bord supérieur de la page et le zoom vaut 1, soit 100 %. Un paramètre PHP `null` devient un objet PDF `null`, qui demande au lecteur de conserver ce paramètre de la vue courante. Pour le zoom, la valeur 0 a aussi ce sens dans la norme.
4. **Les liens du plan sont déduits.** Les objets de plan produisent `/Parent`, `/Prev`, `/Next`, `/First`, `/Last` et `/Count` à partir des rattachements. Les titres sont des `TextString` : ils peuvent ici contenir des accents, indépendamment de l'encodage du texte dessiné avec Helvetica.
5. **Les destinations nommées offrent des clés stables.** Le `NameTree` associe `chapitre-1`, `chapitre-2` et `chapitre-3` aux destinations explicites. Ses clés sont des chaînes d'octets, représentées par `LiteralString`, et sont triées par l'objet arbre. Le `NameDictionary` l'expose sous `/Dests`, puis l'entrée de catalogue `Names` l'expose sous `/Names`.
6. **Le catalogue relie tout.** `new Outlines(new IndirectReference($outline))` respecte l'obligation de référence indirecte de cette entrée. `PageMode::UseOutlines` définit la préférence d'interface ; `OpenAction($firstPage)` définit la vue initiale.

Le script publie les destinations nommées pour montrer leur construction. Ses signets emploient directement `Fit` et `XYZ` ; la présence d'un arbre de noms ne transforme pas automatiquement ces cibles en destinations nommées.

## Correspondance avec ISO 32000-1:2008

| Objet ou entrée | Rôle | Référence |
| --- | --- | --- |
| `PageTreeNode`, `PageObject` | Arbre des pages | §7.7.3, tableaux 29 et 30 |
| `/Names`, `NameDictionary` | Arbres de noms accessibles au catalogue | §7.7.4, tableau 31 |
| `NameTree`, `NameTreeEntry` | Association de chaînes à des valeurs | §7.9.6 |
| `Fit`, `XYZ` | Destinations explicites | §12.3.2.2, tableau 151 |
| `/Names /Dests` | Destinations nommées | §12.3.2.3 |
| `OutlineDictionary`, `OutlineItem` | Plan du document | §12.3.3, tableaux 152 et 153 |
| `/PageMode`, `/OpenAction`, `/Outlines` | Préférences et accès depuis le catalogue | §7.7.2, tableau 28 |

Ces références désignent la [norme présente dans le dépôt](../../PDF32000_2008.pdf), également utilisée par les définitions générées.

## Variantes et limites

Passer `expanded: false` au constructeur d'un `OutlineItem` possédant des enfants pour le présenter fermé initialement. Remplacer `Fit` par `XYZ` pour préciser la vue ; les autres classes du namespace `PDF\Document\Destinations` représentent les modes d'ajustement de la norme.

Pour activer une destination nommée depuis une annotation, utiliser un `DestEntry(new LiteralString('chapitre-2'))` sur un document dont le catalogue publie cette clé sous `/Names /Dests`. Le [tutoriel suivant](05-links.md) montre la construction des annotations et la version avec une destination explicite.

Le plan n'écrit pas de sommaire visible dans les pages. Un sommaire imprimable demande des opérateurs de texte et, éventuellement, des annotations de lien. Les réglages de zoom et l'ouverture du panneau sont interprétés par chaque lecteur.

[Retour au parcours](README.md) · [Continuer : liens internes et externes](05-links.md)
