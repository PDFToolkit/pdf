# 23. Labels, articles et présentation

Le [script complet](../../examples/native-navigation.php) construit trois pages
identifiées par `i`, `AA` et `A-8`. Deux articles et une liste de présentation
relient ces pages sans modifier leurs instructions de dessin. Les signets
montrent une destination explicite, un nom PDF et une chaîne de destination.

```sh
php examples/native-navigation.php native-navigation.pdf
```

## Des labels indépendants des indices

Les indices des pages restent `0`, `1`, `2`, dans l'ordre de l'arbre. `PageLabels`
compose un vrai `NumberTree` avec une `LabelRange` pour chaque début de plage.
La plage d'indice zéro est obligatoire ; le writer contrôle les débuts contre
les pages réellement présentes dans le catalogue.

`PageLabel` distingue style, préfixe et premier nombre `St`, qui vaut au moins
un. `D`, `R`, `r`, `A`, `a` représentent chiffres décimaux, chiffres romains et
lettres. Le style alphabétique répète la même lettre : `A…Z`, `AA…ZZ`, puis
`AAA…ZZZ`. Le script commence la deuxième plage à 27, d'où `AA`.

L'absence de style produit uniquement le préfixe, même si `St` est fourni ; sans
préfixe, le label est une chaîne vide. `label()` calcule un label de plage et
`labelsFor($catalog)` donne les labels des pages de ce document. Les limites de
sortie sont explicites, pour éviter d'allouer un texte gigantesque avec un très
grand numéro alphabétique ou romain.

## Une destination décrit une vue

`LocalDestination` compose les huit objets existants `XYZ`, `Fit`, `FitH`,
`FitV`, `FitR`, `FitB`, `FitBH`, `FitBV`. Le premier élément est une référence
vers une page de ce document. La validation vérifie les paramètres et cette
appartenance réelle ; une page étrangère ne peut pas devenir une destination
locale en étant simplement ajoutée au corps du fichier.

`NamedDestinations` construit la correspondance de chaînes dans `Names.Dests`.
`LegacyDestinations` construit celle des noms dans `Catalog.Dests`, avec la
référence indirecte exigée par le catalogue. Les deux espaces sont distincts :
dans l'exemple, le nom `/details` cible la page `AA`, tandis que la chaîne
`(details)` cible un rectangle de `A-8`.

`NamedDestination` exprime cette référence sans convertir les noms en chaînes.
`toObject()` donne la primitive correspondante lorsque l'application construit
elle-même une entrée. `GoToDestination` compose un `/GoTo` local qui vérifie la
résolution du nom au moment de l'écriture. Un `DestinationDictionary` permet
aussi d'associer des attributs explicites à une destination de l'arbre.
Le constructeur de `GoToDestination` accepte aussi une action ou un tableau
d'actions `next`, sans imposer une structure d'arbre à leurs références.

Le calcul de cadrage est une opération séparée :

```php
$view = $destination->resolve(
    new DestinationViewport($windowWidth, $windowHeight, $currentView),
    new DestinationGeometry($cropBox, $contentBounds),
);
```

Les dimensions de fenêtre et la position actuelle sont fournies en objets
numériques. Les paramètres `null` conservent leur valeur actuelle ; le zoom
`XYZ` zéro a le même sens. `FitB` demande explicitement la boîte du contenu et
la restreint à la CropBox. Le core n'infère pas les limites du dessin depuis ses
octets. La position calculée utilise les axes de l'espace utilisateur par
défaut ; les transformations physiques d'écran et la rotation de l'interface
relèvent du lecteur. Une boîte sans aire ne définit pas un zoom de cadrage.

## Un article traverse des régions de pages

`ArticleThread::addBead()` choisit l'ordre de lecture. Chaque `ArticleBead`
possède un rectangle ; ses références `N` et `V` sont dérivées de cet ordre.
Le dernier retourne au premier, et le premier au dernier. Pour un article
d'un seul bead, les deux références désignent ce même bead.

Le premier bead possède toujours `T`, référence vers son article. Les autres
peuvent aussi la fournir à partir de PDF 1.2, avec `includeThread: true`.
`ArticleThreads` construit la référence indirecte `Catalog.Threads`. Les titres
d'articles peuvent être identiques : leur ordre reste celui choisi par l'auteur.

`PageBeads` fournit séparément l'ordre de dessin du tableau `B` de chaque page.
Le script liste la note avant la continuation de l'article sur `AA`, tout en
conservant les deux anneaux. Pour résoudre la relation cyclique avec `P` :

1. Construire les beads, articles et collections de pages.
2. Fournir `PageBeads::pageEntry()` au constructeur de chaque page.
3. Rattacher les pages à l'arbre.
4. Appeler `bindToPage()` sur les collections avec ces mêmes pages.

Le writer vérifie la présence unique de chaque bead dans `B`, son article
enregistré et son véritable propriétaire `P`. Aucun clone de page ou registre
global ne remplace cette association.

## Transitions et étapes de présentation

`PagePresentation` distingue la durée d'affichage `Dur` de la transition entrante
`Trans`. La première page déclare une transition Split de 3,5 secondes, verticale
et vers l'extérieur, puis un affichage de 5 secondes. L'absence de durée
d'affichage laisse l'avance automatique désactivée ; une durée zéro est immédiate.

`Transition` vérifie les paramètres propres à son style : `Dm` pour Split/Blinds,
`M` pour Split/Box/Fly, angles `Di` selon le style et `SS`/`B` pour Fly. Les angles
sont mesurés dans le sens inverse des aiguilles d'une montre depuis la droite,
contrairement à `Page.Rotate`. Le Fly de la deuxième page utilise `None` et une
échelle de 0,25. Le style `R` remplace la page sans effet et ignore la durée de
transition.

`PresentationSequence` construit les `NavigationNode` de `PresSteps` en liste
doublement liée. Ses extrémités restent ouvertes : elles ne forment pas l'anneau
d'un article. Le node primaire peut être choisi au milieu. Le script fournit son
entrée à la page, puis lie la séquence après le rattachement à l'arbre.

Une requête forward ou d'accès direct sélectionne `NA`, puis `Next` ; une requête
backward sélectionne `PA`, puis `Prev`. `enter()` et `request()` retournent ce
plan explicite, sans exécuter ses actions. Le dernier node de l'exemple quitte
la page avec une destination locale et ne possède donc aucun `Next`.

Les relations sont contrôlées dans la vue effective du document, y compris lors
d'une écriture incrémentale. Modifier un ancien builder sans le soumettre à la
nouvelle révision ne change pas le PDF déjà écrit. Remplacer un objet conserve
son identité PDF dans les références historiques ; supprimer un article demande
de retirer aussi les tableaux `B` concernés. Les anciens dictionnaires devenus
inaccessibles peuvent rester dans les sections de références précédentes.

La vérification inspecte les actions de navigation déclarées, y compris les
chaînes `Next`, avec un parcours borné. Elle ne déduit pas les effets d'un script
opaque. Les cycles d'actions ne sont pas assimilés aux cycles interdits des
arbres : la norme décrit leur traitement par le lecteur. La liste typée reste
une construction finie, figée lors de son rattachement à une page.

## Versions et vérification

Les articles et noms demandent PDF 1.1, les chaînes de destination PDF 1.2,
les labels PDF 1.3 et PresSteps PDF 1.5. Les cinq styles Fly/Push/Cover/Uncover/Fade
demandent aussi PDF 1.5. Le writer tient compte de la version effective du header
et du catalogue.

```sh
qpdf --check native-navigation.pdf
php tools/fixtures/native_navigation.php > navigation-vectors.json
python tools/validate_native_navigation.py native-navigation.pdf navigation-vectors.json
```

L'oracle indépendant inspecte les trois pages, les labels, les anneaux, `P`/`B`,
les deux espaces de destinations et les liens `PresSteps`. Il compare aussi
55 labels et dix cadrages à des calculs Python, puis lit trois révisions d'un
autre document pour vérifier les remplacements et suppressions. Le rendu statique montre les
régions ; la lecture des articles, les timers et animations restent des
fonctions de l'application PDF.

Références : ISO 12.3.2, pages physiques 373–375 ; ISO 12.4.2–12.4.4,
pages physiques 382–389 ; GoTo, ISO 12.6.4.2, page physique 426.

[Index](README.md) · [Code complet](../../examples/native-navigation.php)
