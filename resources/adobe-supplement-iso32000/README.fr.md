# Catalogue source Adobe PDF 1.7 ExtensionLevel 3

[English](README.md) | Français

La source conservée est [`adobe_supplement_iso32000.pdf`](../reference-documents/adobe_supplement_iso32000.pdf),
Adobe Acrobat SDK 9.0, édition 1.0, juin 2008. Ses octets et nom originaux sont
préservés. `metadata.json` enregistre SHA-256 et identité documentaire ; chaque
définition PHP générée indique sa page PDF physique exacte, sa clause et sa source.

Il s'agit d'une extension Adobe de PDF 1.7. Ses numéros de tableaux suivent la
PDF Reference et restent séparés du catalogue ISO 32000-1. Le supplément
explique lui-même cette distinction aux pages 5–7.

`dictionaries.json` contient les **61 tableaux de dictionnaires PDF imprimés**,
**276 entrées imprimées distinctes** et **six spécialisations de sous-types
décrites en prose** près des tableaux. Ces six définitions portent l'étiquette
`prose specialization` : GEO et les cinq types de mesure/commentaire 3D.
La ligne `/MA` répétée à la page PDF 57 reste deux occurrences sources et
produit une entrée. `classes.json` répertorie 61 classes de dictionnaires,
61 définitions immuables et 282 entrées sous `PDF\Specification\Adobe`.

Un tableau d'entrées ajoutées compose explicitement les définitions ISO de base
et, selon le cas, d'autres définitions Adobe. Les entrées héritées inchangées
gardent leur source ISO ; celles modifiées gardent leur source Adobe.
`corrections.json` enregistre ambiguïtés et décisions d'implémentation sans
écraser le type, qualificatif ou description imprimé : ils restent dans
`source_constraints` et les champs originaux. Les types de membres de tableaux
décrits en prose sont dans `validation_type`, distinct de la colonne `type` imprimée.

Les schémas exécutables vérifient les types déclarés, références explicites,
noms fixes, clés requises inconditionnelles et les quatre tableaux RichMedia
exigeant des références à des dictionnaires. Les arguments RichMedia command
acceptent seulement chaînes, entiers, réels, booléens ou tableaux de ces scalaires ;
les tableaux mixtes sont valides. Les règles conditionnelles, relations entre
objets, géométrie, plages et comportements applicatifs conservent leur texte
normatif et restent la responsabilité explicite des mécanismes ou appelants.

`coverage.json` inventorie indépendamment les **66 tableaux de la partie I** :
61 dictionnaires, quatre listes noms/types et un tableau d'attributs XML.
Il conserve aussi les mécanismes en prose, algorithmes et 207 notes de la
partie II. Ces notes décrivent les produits Adobe ; elles ne deviennent pas
automatiquement des contraintes universelles PDF. `references.json` enregistre
les ressources externes citées.

Reconstruire l'inventaire avec Python et `pypdf` / `pdfplumber`, puis générer PHP :

```sh
python tools/extract_adobe_supplement.py
python tools/generate_adobe_supplement.py
```

Les deux outils ont `--check`, qui compare les octets exacts sans changer de
fichier. Le générateur PHP lit seulement le catalogue JSON commité et utilise
la bibliothèque standard Python ; les bibliothèques d'extraction ne sont pas
des dépendances PHP de production. L'inventaire de couverture et les références
externes sont des fichiers revus et ne sont écrasés par aucun générateur.
