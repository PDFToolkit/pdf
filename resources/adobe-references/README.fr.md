# Références historiques Adobe et revue des errata 2007

[English](README.md) | Français

Tous les PDF fournis conservent leurs noms et octets d’origine dans
`resources/reference-documents/`. `metadata.json` identifie chaque source et les
documents intégrés au portfolio. La référence, ses errata, l’addendum de
caviardisation et le supplément Adobe ultérieur restent des autorités distinctes.

`errata-review.json` classe **65 corrections** provenant de toutes les pages de
contenu du fichier `pdf_17_errata.pdf` de 18 pages. Il relie les contraintes
implémentées aux objets PHP et aux tests, sépare les corrections éditoriales et
les notes sur les anciens viewers, et indique explicitement les opérations des
applications et les travaux sémantiques incomplets. La revue de chaque correction
ne signifie pas que tous les comportements d’un processeur sont implémentés.

Cette revue ajoute les contraintes de coordonnées et de noms des renvois, interdit
les tableaux de contenu de page vides et implémente le placement corrigé des
apparences d’annotations. Les compteurs de signets, clés uniques, exemptions de
chiffrement, conventions de clés et filtres, noms de champs, collections et
contraintes de signature possédaient déjà des implémentations natives. La
suppression d’un indicateur de digest dans les errata 2007 n’efface pas l’extension
Adobe ultérieure qui le réintroduit explicitement.

L’autorisation complète des révisions authentifiées DocMDP/FieldMDP/UR, l’émulation
des anciens Acrobat, la véracité d’une mise en page et la validation de tous les
programmes de polices ne sont pas déduites de cette revue. Le processeur destructif
de caviardisation possède sa propre [couverture source](../redaction/).
