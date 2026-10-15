# Apparences des annotations PDF 2.0

[English](README.md) | Français

Le core 0.9.1 corrige un contrôle conditionnel absent : une annotation `Square`
ordinaire, avec un `Rect` non dégénéré, pouvait être écrite en PDF2 sans `AP`.
Le tableau 166 d’ISO 32000-2, page physique 483, exige cette apparence. Le
[document conservé](../reference-documents/ISO_32000-2_sponsored_EC3.pdf) et le
[registre](coverage.json) identifient les clauses exactes et l’empreinte des octets.

Le problème EC3 nº22 corrige le qualificatif AP : obligatoire avec ses exceptions
en PDF2, facultatif en PDF1.2–1.7. Le nº124 ramène les indices du rectangle à zéro.
Les deux chaînes exactes d’annotations et réponses approuvées sont vérifiées par
[`validate_ec3_semantic_reviews.py`](../../tools/validate_ec3_semantic_reviews.py).
Elles rejoignent les quatre revues précédentes ; les 197 règles intrinsèques de
dictionnaires restent distinctes de ces contrôles contextuels du graphe.

## Apparence obligatoire et exceptions

Le validateur suit les relations réelles `Page.Annots` par le
`DocumentGraphContext` natif, y compris les révisions figées effectives. PDF2
exige AP sauf si **les deux** dimensions du rectangle se réduisent à un point
(`Rect[0]==Rect[2]` et `Rect[1]==Rect[3]`), ou si le sous-type est `Popup`,
`Projection` ou `Link`. Une ligne horizontale ou verticale seule n’est pas
exemptée. Inverser les coins ne change pas le contrôle. L’association des popups
et leur interdiction d’AP restent les règles distinctes du markup.

La priorité entre version d’en-tête et de catalogue s’applique. Passer un
catalogue PDF1.7 à PDF2 vérifie donc les annotations conservées des révisions
précédentes. Le writer doit fournir explicitement leurs apparences pendant cette
mise à jour. PDF1.7 conserve son comportement antérieur avec AP facultatif. Les
dictionnaires privés aux clés homonymes ne deviennent pas des annotations de page.

## AP, états et streams Form

Un dictionnaire AP exige `N`. `N`, ainsi que `R` et `D` facultatifs, contiennent
un véritable stream Form XObject ou un dictionnaire de streams Form nommés.
Un dictionnaire d’états dans l’un de ces emplacements exige un nom `AS`. Les
valeurs nulles facultatives gardent le sens d’une absence en PDF. Une clé privée
inconnue dans AP ne devient pas un nouvel emplacement d’apparence.

La page physique 491 d’ISO2 décrit explicitement un repli du consommateur si AS
ne correspond à aucune apparence. Le contrôle préserve ce cas sans sélectionner
un état arbitraire ni créer d’apparence. Le builder de widgets natif conserve
son propre contrat de construction avec sélection explicite d’état.

Chaque apparence fournie est un stream Form, avec un `BBox` numérique obligatoire,
un `Matrix` numérique facultatif de six éléments et un `FormType=1` facultatif.
`Type` peut être absent ; sa valeur, lorsqu’il existe, est `XObject` (tableau 93,
page physique 287). Aucune exigence d’inversibilité n’est inventée pour rejeter
une matrice singulière. Les autres contrôles de ressources Form et de version
gardent leur validation existante dans le graphe.

Composer `FormAppearanceStream`, des instructions de contenu et des ressources
explicites, puis placer son `IndirectReference` dans AP.N. Associer l’annotation
à la page réelle par `Annots` ; la construction ne dessine pas silencieusement
une apparence de remplacement. Le parcours des apparences et états possède un
budget de construction cumulé.

## Preuves et limites

[`AnnotationAppearanceGraphTest`](../../tests/AnnotationAppearanceGraphTest.php)
vérifie le graphe natif, AP/Form/états mal formés, points contre lignes, nulls,
entrées indirectes, mises à niveau du catalogue et remplacement historique effectif
des streams avec tous les formats xref. Les fixtures d’annotations et géospatiales
existantes fournissent désormais une apparence valide explicite sans retirer
leurs assertions.

```sh
python tools/validate_annotation_appearances.py --php php --qpdf qpdf --pdftoppm pdftoppm --work-dir /path/to/output
```

L’oracle écrit huit PDF, vérifie neuf refus natifs, lit les véritables objets
AP/AS/Form avec pypdf, contrôle la syntaxe avec qpdf et compare 28 pixels Poppler.
Le cas d’état inconnu est inspecté structurellement sans promettre une image de
repli particulière au visualiseur. Il ne teste pas les interactions de survol,
n’invente pas de contenu manquant et n’établit pas une conformité PDF/WTPDF universelle.
