# Couverture des deux références PDF

Le modèle couvre les tableaux de dictionnaires des deux références. Cela ne suffit
pas à déclarer toute leur implémentation terminée : un tableau décrit des objets,
alors que certains chapitres imposent aussi un algorithme, des liens entre objets
ou un comportement de lecteur.

## Références conservées

| Document | Inventaire | Source |
| --- | --- | --- |
| ISO 32000-1:2008, PDF 1.7 | 756 pages ; 392 tableaux ; 263 tableaux de dictionnaires ; 1 405 classes d'entrée ; 73 opérateurs de contenu | [PDF32000_2008.pdf](../PDF32000_2008.pdf) |
| Supplément Adobe, niveau d'extension 3 | 140 pages ; 66 tableaux, dont 61 tableaux de dictionnaires | [adobe_supplement_iso32000.pdf](../adobe_supplement_iso32000.pdf) |

Les fichiers originaux restent dans le dépôt. Les catalogues enregistrent leur
SHA-256 et les numéros de pages physiques ; les corrections d'interprétation
conservent la formulation imprimée et indiquent leur justification.

## Comment lire la couverture

L'[inventaire ISO](../resources/iso32000-1/coverage.json) examine 98 familles de
mécanismes. L'[inventaire Adobe](../resources/adobe-supplement-iso32000/coverage.json)
distingue ses tableaux, algorithmes, prescriptions, notes de produit et standards
externes. Leur référence initiale est la version 0.3.0 ; les ajouts ultérieurs
doivent être accompagnés de fichiers d'implémentation et de preuves de validation.

Les dimensions sont distinctes :

- **Définition** : le dictionnaire et ses entrées ont des objets nommés, leurs
  types, exigences connues et références de source.
- **Écriture** : des objets exécutent réellement le mécanisme et produisent ses
  octets PDF, par exemple un flux LZW ou une table d'indices de linéarisation.
- **Règles sémantiques** : les plages, dimensions, conditions, héritages et liens
  entre objets sont contrôlés pour le domaine annoncé.
- **Validation** : des tests et, lorsque possible, une autre implémentation
  vérifient les documents produits. Un contrôle de syntaxe ne prouve pas à lui
  seul leur rendu, leur accessibilité ni leur validité cryptographique.

Un pourcentage unique mélangerait ces dimensions. Les inventaires donnent donc
les mécanismes vérifiés et le travail restant, plutôt qu'un chiffre de complétude.

## Constructions natives de la version 0.4.0

| Famille | Constructions et vérifications |
| --- | --- |
| Formulaires AcroForm | Arbres de champs, héritage, noms qualifiés, valeurs et flags, widgets séparés ou fusionnés, apparences et appartenance au catalogue réel. |
| FDF | Écriture native, hiérarchie des champs, export explicitement choisi et références de modèles de pages. |
| Structure logique et PDF balisé | Hiérarchie, rôles, classes, attributs, MCID/MCR/OBJR, ParentTree et IDTree ; vérification d'un document de plusieurs pages. |
| Couleurs, motifs et dégradés | Espaces calibrés et spéciaux, cellules tiling, sept dégradés et vrais flux binaires de maillages ; contrôles des octets et du rendu. |
| CMaps | Codespaces, correspondances CID et Unicode, plages, notdef, modes d'écriture et UseCMap ; extraction d'un encodage différent d'Identity-H. |
| Polices et chaînes | Type1, MMType1, TrueType, Type3, CIDFont et Type0 ; programmes déclarés, métriques horizontales et verticales, noms de sous-ensembles et encodages PDFDoc/UTF-16. |
| Images et transparence | Échantillons 1/2/4/8/16 bits, masques, Decode, Matte, groupes, états graphiques, mélanges, soft masks et trames ; les codecs JPX/JBIG2 restent externes. |
| Contenu optionnel | Groupes et appartenances, expressions de visibilité, configurations, usages et états propres au document. |
| Compression | LZW EarlyChange 0/1, transitions de taille et réinitialisations ; prédicteurs TIFF/PNG aux profondeurs 1/2/4/8/16, composés avec Flate ou LZW. |
| Chiffrement par mot de passe | Algorithmes ISO R=2/3/4, RC4 et AESV2, avec filtres et mises à jour incrémentales ; R=5 Adobe reste disponible explicitement. |
| Chiffrement par certificat | Groupes de destinataires CMS, clés de filtres distinctes et formats s3/s4/s5 ; contrôle des clés, données et interprétations de permissions. |
| Signatures | Réservation de Contents, fixation de ByteRange avant signature et remplissage sans déplacement ; vérification indépendante des trois formats ISO et de signatures successives. |
| Politiques de signature | Objets DocMDP, FieldMDP, droits d'usage, seeds et critères de certificat ; contrôles intrinsèques. L'orchestration et la comparaison des révisions autorisées restent ouvertes. |
| Références hybrides | Table classique et flux xref auxiliaire, références historiques, objets comprimés et lecture par lecteurs anciens et récents. |
| Linéarisation | Ordonnancement natif, indices de pages et ressources partagées, vignettes et pièces jointes, objets comprimés, trois formats xref et variantes chiffrées. |

Ces ajouts ne constituent pas encore une déclaration de couverture complète.
Les inventaires conservent notamment la libération et la réutilisation des
numéros d'objets, les mises à jour après linéarisation, l'orchestration des
politiques de signature et les règles de versions, de ressources et de graphe qui
doivent encore être vérifiées systématiquement. Plusieurs familles de navigation,
d'annotations, de fichiers associés et de fonctions demandent aussi des
constructions et des contrôles supplémentaires au-delà de leurs dictionnaires.

## Frontière du core

Le core construit les objets PDF et leurs mécanismes d'écriture. Les programmes
de police, profils ICC, données d'image encodées, certificats, messages CMS, XFA
ou médias sont fournis explicitement. Leurs traitements peuvent être confiés à
des composants dédiés ; ils ne sont jamais supposés réalisés parce que leur
dictionnaire existe.

La lecture d'un PDF externe appartient à
[pdf-reader](https://github.com/PDFToolkit/pdf-reader). La conversion de fichiers
image et de polices appartient à [pdf-images](https://github.com/PDFToolkit/pdf-images)
et [pdf-fonts](https://github.com/PDFToolkit/pdf-fonts). Le rendu, l'exécution
JavaScript, les moteurs XFA/3D/multimédia et la politique de confiance des
certificats demandent leurs propres processeurs ou standards.

[Couverture des définitions](specification.md) · [Supplément Adobe](adobe-supplement.md)
· [Sécurité](security.md) · [Tutoriels](tutorials/README.md)
