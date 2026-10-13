# 14. Champs et apparences AcroForm

Le [script complet](../../examples/acroform-fields.php) produit une page avec un
nom, une liste de pays, une case à cocher, des boutons radio, un bouton de
réinitialisation et un champ de signature vide.

```sh
php examples/acroform-fields.php acroform-fields.pdf
```

`FieldTree` porte les valeurs héritées : ressource Helvetica, apparence textuelle
par défaut et alignement. `NonTerminalField` crée le groupe `Person` ; ses enfants
ont des noms partiels. Le nom complet `Person.Name` résulte de l'arbre.

La valeur `/V` et l'apparence `/AP` ont des fonctions différentes. La première
décrit la donnée ; la seconde est un XObject Form qui dessine le widget avec sa
propre `BBox` et ses ressources. Changer la valeur ne recalcule pas l'apparence :
fournir une nouvelle apparence ou choisir explicitement `NeedAppearances`.

`WidgetAnnotation` relie un champ à son rectangle dans la page. Le champ énumère
ses widgets dans `/Kids`, chaque widget revient à son `/Parent`, et la page les
référence dans `/Annots`. Le writer vérifie leur appartenance au catalogue réel.
Une case à cocher possède les apparences `/Off` et `/Yes` ; `/AS` choisit l'état.

La liste distingue les libellés affichés des valeurs exportées.
`ChoiceValueDomain::ExportValues` rend le choix explicite, certaines formulations
ISO étant ambiguës ; `/I` conserve les indices sélectionnés dans `/Opt`.

Modifier valeurs, options, rectangles ou états dans le script permet d'observer
chaque lien. Le champ de signature reste vide ici ; le
[tutoriel 20](20-signed-document.md) réalise une signature. Les codecs barcode,
les scripts et l'exécution XFA demandent leurs propres moteurs.

Référence : ISO 12.7, pages physiques 438–473 ; supplément Adobe pages 42–48.

[Index](README.md) · [Code complet](../../examples/acroform-fields.php)
