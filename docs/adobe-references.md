# Références Adobe PDF 1.7 et errata

Ces documents complètent les sources du core : ils permettent de comprendre
l'origine des définitions, de vérifier les corrections et de distinguer le
format PDF du comportement d'une version d'Acrobat. Ils concernent PDF 1.7 et
appartiennent au dépôt `PDFToolkit/pdf`.

La référence active des définitions reste [ISO 32000-1:2008](../PDF32000_2008.pdf),
avec les extensions du [supplément Adobe](adobe-supplement.md). L'archivage des
documents ci-dessous ne modifie ni les classes générées, ni les règles de version,
ni le statut d'implémentation des mécanismes. La [couverture](coverage.md)
distingue ces dimensions.

## Documents conservés

Les numéros de pages de ce guide sont les pages physiques des PDF, à partir de 1.
Le [catalogue des références](../resources/adobe-references/metadata.json)
enregistre leurs identités, tailles, SHA-256 et relations entre pièces jointes.
Les fichiers conservent leurs noms et leurs octets d'origine dans Git et sont
téléchargeables depuis la documentation. Ils sont exclus des archives Composer
pour que l'installation de la bibliothèque n'ajoute pas ces volumes documentaires.

| Document | Édition et pages | Utilité |
| --- | --- | --- |
| [PDF Reference, version 1.7](../pdf_reference_1-7.pdf) | Sixième édition, novembre 2006 ; 1 310 pages | Référence Adobe antérieure à ISO 32000-1, avec exemples, notes historiques et notes d'implémentation. |
| [Errata for the PDF Reference](../pdf_17_errata.pdf) | Octobre 2007 ; 18 pages | Corrections de la sixième édition, à comparer aux règles ISO et aux contrôles exécutables. |
| [PDF Redaction: Addendum](../pdf_reference1-7_addendum_redaction.pdf) | Novembre 2006 ; 4 pages | Introduction des annotations `Redact` et distinction entre désignation d'une zone et destruction du contenu. |
| [PDF Reference and Related Documentation](../pdf_reference.pdf) | Portfolio Acrobat SDK 8.1, couverture d'avril 2007 ; 1 page et 4 PDF embarqués | Conteneur original regroupant les trois documents précédents et les notes d'implémentation Acrobat 8.1. |

L'[archive historique de la PDF Association](https://pdfa.org/resource/pdf-specification-archive/)
situe la référence Adobe de 2006 et ses errata avant la normalisation de PDF 1.7
par ISO en 2008. La pagination et les numéros de tableaux Adobe ne doivent pas
être réutilisés comme des références ISO.

La préface ISO, page PDF 6, explique la révision et l'adoption de la référence
Adobe de novembre 2006. Celle-ci reste utile pour ses exemples et son annexe H
sur les implémentations. Les pages PDF 25 à 27 distinguent déjà le format des
produits qui le traitent. Ses exemples d'annexe G, pages PDF 1 057 et 1 058,
signalent que les longueurs de flux et offsets illustrés peuvent être inexacts :
ils demandent une reconstruction avant de devenir des fixtures exécutables.

## Le portfolio et ses doublons

La page de couverture du portfolio énumère les quatre documents embarqués. Le
portfolio lui-même n'est pas une référence supplémentaire de 1 310 pages.

| Nom dans le portfolio | Pages du document embarqué | Relation avec les fichiers conservés |
| --- | --- | --- |
| `pdf_reference.pdf` | 1 310 | Identique octet pour octet à `pdf_reference_1-7.pdf`. |
| `PDF17Errata.pdf` | 18 | Identique octet pour octet à `pdf_17_errata.pdf`, daté d'octobre 2007. |
| `pdf_reference_addendum_redaction.pdf` | 4 | Identique octet pour octet à `pdf_reference1-7_addendum_redaction.pdf`. |
| `pdf_implementation.pdf` | 11 | Notes Acrobat 8.1 d'avril 2007 ; pas de fichier séparé parmi les quatre sources fournies. |

Le catalogue conserve ces correspondances. La couverture d'avril 2007 du
portfolio et l'édition d'octobre 2007 des errata joints sont des dates distinctes.
Un lecteur qui affiche uniquement la page de couverture peut ne pas présenter
les pièces jointes ; utiliser un lecteur capable de les ouvrir pour consulter
les notes d'implémentation. Leur pagination commence à 1 dans la pièce jointe.

## Utiliser les errata pour les audits

Les errata corrigent la référence Adobe de 2006. Ils ne doivent pas remplacer
automatiquement les tableaux ISO ni les extensions Adobe plus récentes. Chaque
correction doit être rapprochée de sa clause ISO, de l'objet qui la porte et des
preuves qui vérifient son comportement.

Exemples de sujets explicitement corrigés :

- Page 3 : unicité des clés d'un dictionnaire, position du filtre `Crypt` et
  distinction de sa clé de déchiffrement.
- Pages 4 à 6 : identifiants de fichier directs et non chiffrés, exclusions du
  chiffrement, permissions, noms et tailles des filtres cryptographiques.
- Pages 6 et 7 : interdiction d'un tableau `Contents` vide, syntaxe des dates,
  taille non comprimée d'un fichier embarqué et types des données de collection.
- Pages 8 et 9 : déplacement de ligne `T*` et organisation des programmes
  OpenType embarqués.

Cet inventaire de sujets ne déclare pas un audit exhaustif des 18 pages terminé.
Une règle déjà présente dans ISO 32000-1 peut avoir sa définition dans le core
sans que toutes ses contraintes soient contrôlées. Les validations actuelles et
les travaux restants sont indiqués dans le [guide de couverture](coverage.md).

Des correspondances concrètes ont été vérifiées dans le code existant : les
clés de dictionnaire sont uniques ; un filtre `Crypt` explicite est unique et
placé en premier ; sa clé suit le chemin explicite des backends de chiffrement.
`EmbeddedFileParameters` calcule la taille à partir des octets non comprimés,
et `PartialFieldName` refuse un point dans un nom partiel (errata page 12).
Ces contrôles portent sur leur domaine déclaré, pas sur toutes les variantes
d'un document arbitraire.

L'audit a aussi conduit à une correction dans le core 0.5.2 : `OutlineDictionary`
omet désormais le `Count` de racine lorsque tous les signets de premier niveau
ont `expanded=false` (errata page 10 et ISO tableau 152, page PDF 376). Un
descendant ouvert mais caché sous un parent fermé ne déclenche pas cette entrée.
Un signet de premier niveau explicitement ouvert conserve le calcul du nombre
visible ; les comptes signés des éléments ne changent pas. Ce contrôle porte sur
ces états explicites, sans redéfinir l'ouverture des feuilles.

### Préserver les différences entre éditions

La page 13 des errata retire le bit 7 `DigestMethod` des flags de seed de
signature. Le tableau ISO 234, page PDF 455, le conserve : le core suit ce
contrat ISO. De même, la page 14 des errata déprécie les anciens digests des
références de signature, alors que le tableau ISO 253, pages PDF 477 et 478,
conserve un `DigestMethod` optionnel. Ces différences justifient des références
de source explicites plutôt que l'application globale d'un correctif de 2007.

## Caviardisation : annoter et supprimer

Les pages 2 à 4 de l'addendum décrivent deux étapes distinctes. Une annotation
`Redact` désigne le contenu à retirer et permet de revoir les zones choisies.
L'application de cette annotation doit ensuite supprimer le contenu concerné
et l'annotation, puis produire l'apparence de remplacement.

Le core contient déjà le
[dictionnaire des annotations de caviardisation](../src/Specification/Dictionary/RedactionAnnotationTable192.php),
issu du tableau ISO 192, pages PDF 421 et 422. Les huit entrées de l'addendum
(`Subtype`, `QuadPoints`, `IC`, `RO`, `OverlayText`, `Repeat`, `DA` et `Q`)
figurent dans cette définition. Cela permet la construction du dictionnaire ;
cela ne constitue pas un moteur d'application de la caviardisation.

La page 3 de l'addendum exige de supprimer les données, y compris la partie
concernée d'une image : un masque ou un rectangle opaque ne suffit pas. Les
métadonnées et les autres formes de contenu doivent aussi être examinées.
Une mise à jour incrémentale conserve les octets des révisions précédentes et
ne peut donc pas assurer à elle seule cette destruction.

Un futur processeur de caviardisation devra s'appuyer sur la lecture,
l'interprétation et la réécriture du contenu dans un module dédié. Les objets
`Redact`, les coordonnées et les apparences PDF restent dans le core. Ce
processeur n'est pas implémenté par l'ajout des références documentaires.

## Notes d'implémentation Acrobat

La pièce jointe `pdf_implementation.pdf` renseigne les comportements d'Acrobat
8.1 : PRC aux pages 6 à 8, U3D aux pages 8 et 9, XFA aux pages 9 et 10, collections
à la page 10 et abandon des diaporamas SVG à la page 11. Ces sujets sont également
traités par le supplément Adobe déjà conservé, notamment aux pages PDF 55 à 58
pour PRC et dans ses notes de produit.

Ces notes servent à expliquer un comportement historique, pas à imposer les
limites d'Acrobat 8.1 à tous les PDF. Le format peut décrire un objet ou une action
sans que le core exécute son script, rende son média ou traite son XFA.

## Vérifier les sources

Après installation des dépendances Python de validation :

```sh
python tools/validate_adobe_references.py
```

La validation utilise un lecteur indépendant pour vérifier les pages, les quatre
pièces jointes et leurs correspondances, en plus des tailles et empreintes des
originaux. Les workflows de qualité et de documentation l'exécutent avant la
publication. La validation des fichiers source n'est pas une preuve de couverture
des algorithmes qu'ils décrivent.

[Définitions](specification.md) · [Couverture](coverage.md) · [Supplément Adobe](adobe-supplement.md)
