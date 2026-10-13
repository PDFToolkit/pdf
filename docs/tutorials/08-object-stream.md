# 08. Filtres, objets compressés et références croisées

Cette leçon reprend une page de texte et change la manière dont ses objets sont rangés dans le fichier. Elle montre la différence entre compresser le contenu d'un flux et regrouper des dictionnaires dans un flux d'objets.

**Prérequis :** avoir lu le [premier document](01-first-document.md). Le [dégradé](07-gradient.md) de la leçon précédente est indépendant de ce sujet.

## Exécuter l'exemple

```sh
php examples/object-stream.php
```

Ouvrir `examples/object-stream.pdf`. La page affiche « An explicit object stream and cross-reference stream. ». Le résultat visuel reste une page ordinaire ; le conteneur d'objets se constate en examinant la structure du PDF.

![Aperçu de la page avec objets compressés](previews/object-stream.png)

Le [code commenté](../../examples/object-stream.php) précise chaque étape. Il utilise une police standard et un flux de texte comme le premier tutoriel.

## 1. Compresser le flux de contenu

Le `StreamObject` reçoit un `FlateDecode` en plus de son contenu. Cette instance encode les octets et conduit à l'entrée `/Filter /FlateDecode`. Le nom est celui de l'opération que le lecteur utilisera pour décoder le flux.

`/Length` décrit les octets **après** compression. On ne fournit donc pas à la main une longueur calculée sur les instructions originales. Cette compression concerne ce flux précis, pas toutes les structures du document.

## 2. Former un conteneur d'objets

```php
$objects = (new ObjectStream(
    new ObjectStreamEntry(new ObjectNumber(40), $catalog),
    new ObjectStreamEntry(new ObjectNumber(41), $pages),
    new ObjectStreamEntry(new ObjectNumber(42), $page),
    new ObjectStreamEntry(new ObjectNumber(43), $font),
))->withFilters(new FlateDecode());
```

Un `ObjectStream` est un flux `/Type /ObjStm`. Les quatre entrées choisissent les objets qui seront rangés à l'intérieur. Les numéros 40 à 43 sont explicites pour rendre l'exemple inspectable ; le writer les réserve et attribue les autres numéros sans collision.

Le conteneur porte `/N`, le nombre de membres, et `/First`, l'offset du début de leurs données après l'index interne, dans les octets **décodés** du conteneur. À l'inverse, `/Length` mesure les octets encodés écrits dans le fichier. `withFilters()` retourne un nouveau conteneur ; le script conserve cette valeur. Son `FlateDecode` compresse l'index et les objets regroupés. Il s'agit d'une seconde compression indépendante de celle du contenu de page.

Les membres ont une génération zéro et ne peuvent pas être eux-mêmes des flux. Le flux de contenu demeure donc un objet indirect hors de ce conteneur. Le catalogue et la page conservent les mêmes références entre eux, quelle que soit leur représentation physique.

## 3. Utiliser un flux de références croisées

`CrossReferenceFormat::Stream` demande un flux `/Type /XRef` à la place de la table textuelle `xref`. Les entrées de références croisées décrivent notamment :

| Type | Emplacement d'un objet |
| --- | --- |
| 0 | Objet libre. |
| 1 | Objet indirect autonome, identifié par son offset dans le fichier. |
| 2 | Membre compressé, identifié par le numéro du conteneur et son index à l'intérieur. |

Un membre de `/ObjStm` nécessite cette représentation de type 2. Le writer refuse donc un flux d'objets associé à `CrossReferenceFormat::Table`. `/Root` continue à référencer le catalogue, même lorsque ce dernier est compressé.

Les flux d'objets et de références croisées existent depuis PDF 1.5. L'exemple utilise un header 1.7. Une table traditionnelle et un flux de références croisées sont deux représentations du fichier ; ce choix ne réalise ni linéarisation ni mise à jour incrémentale.

## Examiner le fichier

Avec qpdf installé :

```sh
qpdf --check examples/object-stream.pdf
qpdf --show-xref examples/object-stream.pdf
qpdf --qdf --object-streams=disable examples/object-stream.pdf objets-decompresses.pdf
```

`--show-xref` permet de repérer les objets 40 à 43 dans le conteneur. La dernière commande réécrit le document avec des objets autonomes et des flux décompressés pour faciliter leur lecture. Les numéros peuvent changer lors de cette réécriture.

## Variantes

- Retirer le filtre du flux de contenu permet de lire directement ses instructions dans le fichier, tout en conservant le conteneur d'objets compressé.
- Retirer `->withFilters(new FlateDecode())` laisse l'index et les membres du conteneur non compressés, tout en conservant leur regroupement dans `/ObjStm`.
- Pour revenir au fichier du premier tutoriel, utiliser `FileBody($catalog)`, retirer le conteneur et choisir `CrossReferenceFormat::Table`.

**Dans la norme :** filtres (§7.4), flux d'objets (§7.5.7), flux de références croisées et types d'entrées (§7.5.8).

[Précédent : dégradé et fonction](07-gradient.md) · [Index des tutoriels](README.md) · [Recette : tableau](../recipes/table.md)
