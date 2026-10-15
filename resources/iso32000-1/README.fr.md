# Inventaire PDF 1.7 et relations de dictionnaires exécutables

[English](README.md) | Français

Le fichier conservé `PDF32000_2008.pdf` et ses pages physiques exactes restent la
référence de cette édition. `semantic-rules.json` ajoute **98 règles intrinsèques
exécutables réparties sur 33 tableaux** aux définitions générées, indépendamment
de PDF 2.0. Ces règles sont des objets `SemanticRule` immuables composant des objets
`DictionaryConstraint`. Les définitions dérivées héritent des règles des tableaux
communs ; `SchemaDictionary::validate()` et la sérialisation les exécutent.

Les contrôles portent sur les domaines numériques, les dimensions des fonctions,
les positions des filtres et paramètres, les références croisées, les couleurs
calibrées et ICC, les motifs et maillages, les masques et échantillons d’images,
la géométrie des renvois, les lignes de rappel et les options de choix. Ils ne
déduisent pas la sémantique dépendant des ressources ou de l’application.
Les corrections PDF 2.0 concernant `Path` et les indices de choix ne modifient pas
implicitement PDF 1.7.

La [revue des errata Adobe 2007](../adobe-references/errata-review.json) classe
séparément chaque correction du fichier fourni de 18 pages. Le writer natif
refuse désormais un tableau `/Contents` vide ; l’absence de `/Contents` et un flux
explicitement vide restent valides. `AnnotationAppearanceTransform` implémente
l’algorithme corrigé `Matrix × A`, avec les quatre coins transformés, les rectangles
inversés et des tests de matrices non commutatives.

`coverage.json` conserve son audit historique. Les sections du développement
courant et de la revue sémantique décrivent les ajouts ultérieurs ; une ligne
historique `schema-only` ne décrit pas toute l’implémentation actuelle. Inversement,
l’inventaire des tableaux et une sérialisation réussie ne prouvent ni la conformité
PDF complète, ni l’autorisation des révisions authentifiées, ni le rendu d’un viewer.

Vérifier la génération avec `python tools/generate_specification.py --check` et
exécuter `tests/SpecificationSemanticsTest.php` pour les relations rattachées aux sources.
