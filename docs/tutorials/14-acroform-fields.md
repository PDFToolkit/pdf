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

Le texte modifiable des champs `/Tx` **et** `/Ch` est entouré d'une séquence
`/Tx BMC ... EMC`. Le nom de ce marqueur reste `Tx`, même pour une liste de type
`Ch`. Un lecteur remplace cette seule séquence lors de la saisie ; le cadre reste
à l'extérieur. Sans marqueur, la procédure ISO ajoute le nouveau texte à la fin
du flux et conserve l'ancien dessin, ce qui peut produire une superposition dans
Acrobat Reader.

Le script compose cette séquence avec des objets `BeginMarkedContent`,
`SaveGraphicsState`, `BeginTextObject`, `EndTextObject`, `RestoreGraphicsState`
et `EndMarkedContent`. Le clipping est aussi à l'intérieur : il est restauré
avec `Q` et peut être remplacé avec le texte. Les apparences de boutons et de
signature restent des dessins distincts ; elles ne demandent pas ce marqueur
de texte variable.

`WidgetAnnotation` relie un champ à son rectangle dans la page. Le champ énumère
ses widgets dans `/Kids`, chaque widget revient à son `/Parent`, et la page les
référence dans `/Annots`. Le writer vérifie leur appartenance au catalogue réel.
Une case à cocher possède les apparences `/Off` et `/Yes` ; `/AS` choisit l'état.

La liste distingue les libellés affichés des valeurs exportées.
`ChoiceValueDomain::ExportValues` rend le choix explicite, certaines formulations
ISO étant ambiguës ; `/I` conserve les indices sélectionnés dans `/Opt`.
L'apparence initiale dessine `France` et `Belgique` sur deux lignes, avec les deux
sélections dans la séquence variable. Elle ne dessine pas les codes exportés
`FR` et `BE`, qui appartiennent aux données du champ.

Modifier valeurs, options, rectangles ou états dans le script permet d'observer
chaque lien. Le champ de signature reste vide ici ; le
[tutoriel 20](20-signed-document.md) réalise une signature. Les codecs barcode,
les scripts et l'exécution XFA demandent leurs propres moteurs.

Référence : ISO 12.7, pages physiques 438–473 ; texte variable et procédure de
remplacement §12.7.3.3, pages 443–444. Supplément Adobe pages 42–48 et page 121,
notes 118–119 : sans `/MK`, Acrobat conserve le contenu hors de la séquence
`/Tx`. La présence de `/MK` demande une régénération complète de l'apparence ;
elle n'est pas nécessaire pour les apparences personnalisées de ce script.

[Index](README.md) · [Code complet](../../examples/acroform-fields.php)
