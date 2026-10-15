# Catalogue des dictionnaires ISO 32000-2:2020 et corrections EC3 sélectionnées

[English](README.md) | Français

Ce catalogue propre à l’édition inventorie les **440 tableaux de la source**, y
compris les annexes et les emplacements conservant la numérotation. Ses
**306 définitions de dictionnaires** fournissent **1 702 classes d’entrées fixes
et sept classes de clés variables explicites**. Le générateur produit
**2 322 fichiers PHP**, dont l’agrégation immuable
`PDF\Specification\Iso32000_2\Catalogue`. Ces nombres décrivent l’inventaire des
dictionnaires. Les règles conditionnelles, les algorithmes et la conformité globale
restent suivis séparément dans [coverage.json](coverage.json).

La source canonique est [l’original conservé](../reference-documents/ISO_32000-2_sponsored_EC3.pdf),
de 1 023 pages physiques, dont le SHA-256 est
`90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230`.
Elle provient des [normes sponsorisées de la PDF Association](https://www.pdfa.org/sponsored-standards/)
et est publiée avec l’autorisation de redistribution confirmée par le contributeur.
Le [lot complet fourni](../iso32000-2-bundle/) conserve aussi l’autre copie
transmise. Les PDF et les preuves d’extraction sont exclus des archives Composer.
L’édition logique est ISO 32000-2:2020 ; EC3 est une collection de corrections
sélectionnée séparément, datée du 1er juin 2026. Le conteneur PDF 1.7 du document
de référence ne change pas la version PDF 2.0 décrite par son contenu.

La revue conserve les identités des tableaux, les pages physiques, les titres de
clauses précédant réellement les légendes, les suites sans légende répétée, les
noms sensibles à la casse comme `/OP` et `/op`, les clés variables, les familles
de valeurs, le caractère direct ou indirect, les exigences conditionnelles ou
héritables et les indications de version. Les tableaux communs aux flux, fonctions,
annotations, actions, champs et attributs appartiennent à cette même édition.
Aucune définition ISO 32000-1 n’est substituée implicitement. Le tableau 117,
explicitement vide dans la norme, ne produit aucune classe de dictionnaire.

Les éléments conservés sont :

- `source-extraction.json` : grilles brutes, lignes et anomalies d’extraction,
  rattachées à l’empreinte de la source canonique ;
- `extraction-review.json` : grilles abîmées relues, casse des clés, clauses
  exactes, tableaux communs, noms uniques et alternatives fermées ;
- `reviewed-overrides.json` : les 53 résumés existants du projet et leurs
  contraintes EC3 sélectionnées, avec conservation de leurs noms publics ;
- `dictionaries.json`, `tables.json`, `classes.json`, `generated-files.json` :
  inventaires compilés reproductibles et correspondance des classes.

Les nouvelles descriptions normatives portent `description_origin:
normative-source` et `description_language: en`. Les 53 résumés restent
`project-authored`. Le texte anglais de référence est identifié séparément de la
documentation traduite. Les espaces et les défauts évidents de ponctuation issus
de l’extraction sont normalisés ; le PDF conservé fait référence. Le texte source
reste intact lorsqu’une correction explicite change une contrainte.

Les restrictions sur les noms sont relues explicitement. Le compilateur ne
transforme pas le premier « shall be X » rencontré en valeur unique : cette phrase
peut concerner une autre clé, une condition ou plusieurs possibilités.
`NameAlternatives` représente les ensembles fermés comme `Sig`/`DocTimeStamp`,
`Square`/`Circle` et `CIDFontType0`/`CIDFontType2`. Les cas contextuels comme
`BaseEncoding` conservent leur texte sans valeur unique inventée. La dépréciation
des seuls tableaux de modes de fusion ne déprécie pas toutes les valeurs de `/BM`.

Les autorités restent distinctes : `Completed` désigne une approbation par
ISO TC 171 SC 2 WG 8 ; `Accepted`, une approbation par le groupe technique PDF de
la PDF Association. `ErrataResolution` conserve la page physique, le numéro du
problème et l’identité de l’annotation. Le tableau ToUnicode 125a (problème 462)
et la liste de propriétés des fichiers associés 409a (problème 374) sont deux
exemples approuvés. Toutes les autres annotations EC3 n’ont **pas** encore reçu
une revue d’implémentation sémantique.

`semantic-rules.json` fournit maintenant **110 règles intrinsèques exécutables
réparties sur 44 tableaux source**, héritées par leurs définitions dérivées.
Elles contrôlent les domaines numériques, les tailles de tableaux, les dimensions
des fonctions, les paramètres de filtres, les sections de références croisées,
les couleurs calibrées, les masques et profondeurs d’images, les coordonnées de
renvois, les chemins de polygones et les options de choix.
`SchemaDictionary::validate()` et la sérialisation exécutent ces règles immuables
composées ; une entrée de catalogue seule ne prouve pas une implémentation sémantique.

Les [revues EC3 natives](ec3-semantic-reviews.json) conservent les identités exactes
des annotations et leurs chaînes de réponses d’approbation pour 21 annotations distinctes couvrant 11 problèmes
(22, 30, 124, 155, 246, 307, 308, 403, 444, 463 et 468). Le `Path` d’un polygone ignore les `Vertices` présents, malgré
l’ancienne formulation contradictoire de la grille. `ChoiceField` sélectionne
explicitement `ChoiceIndexSemantics::Pdf20Ec3` pour les indices corrigés de PDF 2.0 :
`/I` peut accompagner un choix unique, des valeurs d’export identiques avec des
libellés distincts le rendent obligatoire, et un `/V` tableau ne suffit plus à
l’exiger. La version effective du document contrôle toujours la construction PDF 2.0.
La construction historique `Pdf17` reste utilisée par défaut. Si des entrées de
champ supplémentaires étaient passées par position, fournir d’abord
`indexSemantics` ou utiliser l’argument nommé `entries`. Le graphe des annotations
PDF2 exécute aussi l’exigence AP et ses exceptions de point/sous-type (#22/#124) ;
voir les [contrôles d’apparence](../annotation-appearances/README.fr.md). Ces revues ne déclarent pas implémentées les autres annotations du même problème.

Les corrections supplémentaires contrôlent les exposants fractionnaires/négatifs
sur tout leur domaine ; les noms MIME de fichiers embarqués sans paramètres ni
`#` ; les déclarations d’extensions et de linéarisation entièrement directes, y
compris leurs clés privées ; les exclusions `Metadata`/`AF` des signatures avec
`ByteRange` ; les clés non nulles et ordonnées des arbres ; les enfants, attributs
et classes structurels non nuls ; et l’exclusion mutuelle de `StructParent` et
`StructParents`. Les valeurs nulles des arbres et les trous nuls de `ParentTree`
restent autorisés. Les noms de première classe des fichiers embarqués PDF 1.7
conservent leurs règles ; une promotion du catalogue vers PDF 2.0 active la
restriction MIME corrigée.

[ec3-annotation-inventory.json](ec3-annotation-inventory.json) inventorie les 761
ancres de problèmes correspondant à 356 numéros dans la source conservée. La page
physique et l’identité d’objet distinguent le nom d’annotation dupliqué dans la
source aux pages 616 et 621. L’approbation d’une correction source est distincte
de sa preuve d’implémentation. Les ancres absentes du registre sémantique restent
non résolues ; cet inventaire n’est ni une preuve de conformité ni une
classification sémantique automatique.

Pour reconstruire à partir des preuves conservées et comparer les objets PHP,
sans dépendances d’extraction PDF :

```console
python tools/compile_iso32000_2_catalogue.py --check
python tools/generate_iso32000_2.py --check
python tools/validate_ec3_semantic_reviews.py
```

Pour renouveler volontairement l’extraction, installer les dépendances de
développement pypdf et pdfplumber, lancer
`tools/extract_iso32000_2.py --output <répertoire>`, relire tous les changements et
anomalies, puis compiler avec
`tools/compile_iso32000_2_catalogue.py --extraction <répertoire>`. Une extraction
brute ne devient jamais automatiquement une preuve de conformité.

`tests/Iso32000_2SpecificationTest.php` vérifie tous les constructeurs, les grilles
réparées, les ensembles de noms, les contraintes négatives, les clés OPI et
l’isolation des éditions. Les espaces de noms, les inclusions TS 32005, les fichiers
associés, les parties de document, les enveloppes chiffrées, les métadonnées, le
chiffrement par mot de passe, les signatures, le DSS et les horodatages possèdent
leurs preuves de tests et leurs validateurs externes distincts dans `coverage.json`
et dans la couverture du lot. Un objet de schéma ne certifie pas le rendu,
l’accessibilité, la confiance PKI, la sémantique ou la conformité PDF 2.0 complète.
