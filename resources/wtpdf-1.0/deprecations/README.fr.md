# Recommandations sur les fonctions dépréciées

[English](README.md) · [Français](README.fr.md)

WTPDF 1.0 §6.2, page physique 10 du PDF, indique qu’un fichier **devrait**
éviter les fonctions dépréciées par ISO 32000-2. `WtPdfDeprecatedChecks`
exécute cette recommandation pour des relations explicitement reconnues dans
un graphe PDF construit. Il ne transforme pas toute dépréciation en opération
interdite au writer. WTPDF §8.9.1 **interdit** séparément les types
d’annotations dépréciés ; ces échecs existants sont conservés.

Le [catalogue](catalogue.json) immuable consigne 47 familles examinées avec
l’identité des sources conservées, les clauses et pages physiques : 34
inspectables dans le graphe construit, six demandant un contexte de fichier
fourni, cinq impliquant des programmes opaques, un comportement de consommateur
et une revue de source. Il dépasse la liste introductive non exhaustive de la
page 14 d’ISO2. Ces nombres décrivent la portée du catalogue, sans représenter
un pourcentage de conformité ISO ou WTPDF.

## Inspecter un document construit

Avec les dictionnaires `$catalog` et `$trailer` construits par l’application
et la version effective `$version` :

```php
use PDF\Document\Structure\WtPdfAudit;
use PDF\Document\Structure\WtPdfConformanceLevel;
use PDF\Document\Structure\WtPdfDeprecatedFileContext;

$report = (new WtPdfAudit())->inspect(
    $catalog,
    $version,
    WtPdfConformanceLevel::Accessibility,
    new WtPdfDeprecatedFileContext($trailer),
);

$failures = $report->failures();
$recommendations = $report->recommendations();
$pendingHumanOrExternalChecks = $report->pending();
$report->assertNoDetectedFailures();
```

`Recommendation` se distingue de `Failed`. Une recommandation ne fait pas
lever d’exception par `assertNoDetectedFailures()`. `pending()` contient les
contrôles humains et externes, tandis que `recommendations()` retourne les
fonctions déconseillées détectées. Les applications qui présentaient tous les
constats sans échec comme des contrôles en attente doivent afficher ces deux
catégories séparément.

L’appel existant à trois arguments de `inspect()` reste valable. Sans
quatrième argument, un constat indique explicitement que les déclarations
d’`Info` du document et de chiffrement n’ont pas été inspectées. Fournir un
trailer vide représente l’affirmation par l’application de l’absence de ces
entrées. Ce contexte renseigne le modèle construit ; il n’authentifie pas les
octets d’un fichier importé, ne valide pas une signature CMS et ne reconstitue
pas les révisions historiques.

## Contextes et exceptions

Le parcours suit les relations normalisées : pages effectives et annotations,
ressources et fontes/XObjects/états graphiques, champs et valeurs de signature,
actions atteintes par les événements standard, attributs de structure, fichiers
associés et arbres de noms des fichiers incorporés. Un objet peut être visité
dans plusieurs domaines ; une même recommandation pour un objet et une
fonction n’apparaît qu’une fois. Les budgets d’objets, d’arêtes, de profondeur
et d’opérations typées sont explicites dans `WtPdfDeprecatedLimits`. Un budget
épuisé produit un constat de contrôle externe incomplet, jamais un échec de
conformité ou un succès déduit.

Les dictionnaires privés ne sont pas supposés être des actions, fontes,
spécifications de fichiers ou dictionnaires de chiffrement. `Name`, `R`,
`Info`, `ID` et `Mac` sont interprétés dans leur domaine effectif. En
particulier :

- `Info.CreationDate` et `Info.ModDate` du trailer restent valides ; les
  informations d’un thread constituent un autre usage de ces métadonnées.
- `CharSet` est vérifié pour un descripteur Type1 et `CIDSet` pour un
  descripteur CID. `BaseFont` et les noms ordinaires de ressources ne sont
  pas des entrées `Name` dépréciées.
- `OPI` sur un masque d’image et les tentatives de redéfinir `CF.Identity`
  sont ignorés comme prévu. L’`ID` privé d’un formulaire ne devient pas un
  identifiant Web Capture de page ou d’image.
- `TR`/`TR2` d’un état graphique se distinguent des fonctions de transfert
  d’une trame. Seuls les événements standard du propriétaire d’un dictionnaire
  d’actions additionnelles créent des relations d’action.
- Le `checked` minuscule est détecté avec un propriétaire d’attribut
  explicitement `PrintField` ; `Checked` reste actuel. Déterminer une
  sémantique standard équivalente dans un propriétaire ou espace de noms
  personnalisé demande une revue sémantique supplémentaire.
- L’entrée `Length` d’un filtre cryptographique est qualifiée de dépréciée
  **et d’obligatoire** dans la Table25 d’ISO2. La recommandation conserve
  explicitement cette contradiction et ne conseille pas de retirer des
  données obligatoires automatiquement.

Le point d’entrée indépendant `WtPdfDeprecatedChecks::inspect()` retourne les
mêmes objets de constat lorsque seuls ces contrôles sont nécessaires. Ce
n’est pas un validateur PDF général. Le writer natif conserve les mécanismes
historiques.

## Frontières explicites

Les instructions de contenu typées exposent l’opérateur obsolète `F`. Les
octets opaques d’un flux ne sont pas parcourus à la recherche de la lettre
`F` : un parseur doit distinguer opérateurs, chaînes, commentaires et données
d’images en ligne. Les programmes CMap et les contenus FDF incorporés non
typés demandent également des lecteurs de formats.

Les condensats des signatures CMS, les algorithmes des certificats, ceux des
enveloppes à clés publiques et les permissions chiffrées des destinataires
demandent une validation cryptographique explicitement sélectionnée. Les
déclarations des dictionnaires ne révèlent pas tous les algorithmes et ne
prouvent pas qu’un consommateur respecte les permissions. Ces cas restent
des constats de contrôle externe.

Le diff EC3 de l’issue133 annexé à la page physique1010 contient une note de
dépréciation du regroupement de liens. Ce catalogue ne transforme ni sa chaîne
d’approbation ni son application sémantique en règle exécutable. La ligne reste
`source-review-required`. L’inscription au catalogue ne résout pas une
annotation EC3 et n’établit pas un inventaire universel des dépréciations.

Les régressions de [WtPdfDeprecatedFeaturesTest](../../../tests/WtPdfDeprecatedFeaturesTest.php)
vérifient les 40 familles mécaniquement inspectables, collisions de domaines,
objets partagés, cycles, types de champs signature hérités, frontières opaques,
budgets et distinction entre recommandations et interdictions SHALL existantes.
