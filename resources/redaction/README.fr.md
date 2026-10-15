# Application du caviardage

[English](README.md) · [Français](README.fr.md)

La source Adobe conservée est [pdf_reference1-7_addendum_redaction.pdf](../reference-documents/pdf_reference1-7_addendum_redaction.pdf), novembre 2006. Les pages physiques 2 à 4 distinguent le marquage d’une région de la suppression réelle de son contenu. Son SHA-256 inchangé figure dans [coverage.json](coverage.json).

`PDF\Document\Redaction\SecureRedaction` applique un `RedactionPlan` à un `DocumentCatalog` natif explicite. Chaque `RedactionPageRequest` relie l’instance réelle de `PageObject` à ses valeurs `RedactionAnnotation`. L’[exemple exécutable](../../examples/pdf17-secure-redaction.php) construit deux pages, applique un caviardage et écrit un nouveau fichier.

```php
$result = (new SecureRedaction())->apply(
    $catalog,
    new RedactionPlan(new RedactionPageRequest($page, new RedactionAnnotation($annotation))),
);
$result->writeTo($destination);
```

Une annotation identifie le contenu. `SecureRedaction` supprime les instructions de peinture et les données des images/Forms affectées avant d’ajouter leur remplacement. Un rectangle noir, un découpage ou un masque d’image ne constituent jamais une suppression. Chaque image/Form partagée affectée et ses ressources de rendu sont exclues de tous les usages, y compris sur les pages parcourues auparavant. Cette approche autorise volontairement une suppression plus large.

`Rect` est normalisé ; `QuadPoints` est prioritaire et utilise des enveloppes conservatrices des quadrilatères. L’intersection repose sur les enveloppes des points de contrôle des tracés, les CTM et un élargissement conservateur des contours. Une image ou Form affectée est supprimée entièrement. Le texte est supprimé par objet `BT`/`ET` complet. Par défaut, tous les objets texte d’une page sélectionnée disparaissent : un `FontBBox` ou des `Widths` déclarés ne prouvent pas la géométrie du programme de police. Un `RedactionFontGeometry` injecté explicitement peut fournir les enveloppes réellement issues des contours et les avances vérifiées pour conserver le texte prouvé extérieur à toutes les régions. Le core ne charge aucune fonte implicitement.

Après suppression, `IC` fournit le remplissage RGB ; sans `IC`, le remplacement reste transparent. `RO` est prioritaire sur `IC`, `OverlayText`, `DA` et `Q`, et l’origine de sa Form est alignée avec le rectangle de l’annotation. Le compositeur natif d’`OverlayText` accepte un `DefaultAppearance` typé, les largeurs explicites d’une fonte simple ou Courier standard, les correspondances Unicode d’un `ToUnicodeCMap` natif, la justification `Q` et `Repeat`. Une mise en forme complexe ou un autre compositeur peut fournir une Form `RO` normalisée. Une chaîne d’apparence opaque doit être normalisée ; aucun encodage de police inconnu n’est deviné.

Le résultat expose seulement l’écriture d’un fichier neuf, sans mise à jour incrémentale. Le graphe catalogue/pages reconstruit retire volontairement toutes les annotations, la structure et les alternatives textuelles, les formulaires/XFA, les métadonnées du document/des objets, les pièces jointes, actions, signets, dictionnaires d’informations et anciennes révisions. Les ressources de rendu nécessaires sont élaguées et copiées selon une liste autorisée. Les fontes inutilisées et les alternatives `ToUnicode` disparaissent. La structure logique, les formulaires interactifs et l’extraction de texte d’origine ne sont donc pas garantis comme propriétés d’accessibilité.

Le mode strict conserve uniquement des lignes de pixels exactes et normalisées dans un espace Device. Les images encodées opaques, octets supplémentaires, profils non normalisés et programmes de fontes incorporées/complexes conservés nécessitent un `RedactionResourceSanitizer` explicite qui analyse puis reconstruit ces formats. Le core ne fournit pas cet analyseur de formats externes. Une image opaque affectée peut être supprimée sans décodage. Ce mécanisme ne détecte pas la stéganographie et ne recherche pas toutes les occurrences d’une chaîne secrète.

Les contenus non pris en charge sont refusés avant toute écriture du résultat : instructions de page/Form opaques ; opérateurs inconnus ; `gs`, motifs/ombrages et visibilité optionnelle non normalisés ; XObjects externes ; état texte de Form hérité sans `Tf` local ; découpage par le texte ; tracés de découpage croisant un caviardage. Ils demandent une normalisation explicite ou un autre processeur. Les budgets limitent les instructions cumulées sur les deux passes, objets du graphe, profondeurs, octets de pixels et répétitions. L’API traite prudemment le graphe normalisé décrit ; elle n’est pas un éditeur universel de PDF arbitraires.

Les régressions négatives couvrent les limites de police non fiables, les métadonnées d’image opaques/supplémentaires, la suppression des ressources partagées, la priorité RO/IC, l’isolation de l’état du remplacement et les budgets. `tools/validate_secure_redaction.py` inspecte indépendamment les objets accessibles et flux décodés avec pypdf, contrôle l’original et le résultat avec qpdf, puis rend quatre pages avec Poppler. Il vérifie la disparition des octets texte/image supprimés et des métadonnées sémantiques, la conservation des tracés extérieurs et l’unique révision du résultat.
