# Contrats des calculatrices Type 4 natives

[English](README.md) | Français

`PostScriptCalculatorFunction` compile les objets explicites `Calculator\Procedure`, `Number` et `Operator` dans un `program` immuable. La sérialisation et l’évaluation utilisent ce même instantané. Les procédures imbriquées doivent former immédiatement les corps de `if` ou `ifelse` ; les objets procédure PostScript autonomes et les instructions personnalisées sont refusés. La profondeur est limitée à 255 accolades, corps extérieur compris. Un dernier argument optionnel `CalculatorLimits` règle les budgets de source et d’évaluation.

`Calculator\Evaluator::evaluate($function, ...$inputs)` accepte des entrées `NumericType` et renvoie `NumberArray`. Les entrées sont bornées par Domain avant l’évaluation, les sorties par Range après. Chaque invocation possède sa pile et son compteur de travail. Les erreurs de type, dépassements de pile, domaines arithmétiques invalides et nombres de sorties incorrects produisent des exceptions explicites. Les nombres conservent leur type lexical émis : utiliser `RealType` pour un opérande réel de valeur entière.

`CalculatorLimits` prévoit par défaut 100 opérandes sur la pile, 1 000 000 instructions exécutées, 100 000 instructions source et 255 niveaux d’imbrication. La capacité de pile ne peut pas descendre sous le minimum normatif de 100. Le writer peut émettre un programme demandant une pile supérieure ; sélectionner explicitement cette capacité lors de son évaluation.

Le writer refuse les violations de pile, de type et de sorties démontrées sur un programme linéaire. Les formes de pile conditionnelles ou dépendantes des données sont différées à l’évaluation explicite ; des formes intermédiaires différentes entre branches ne prouvent pas une invalidité. Des entrées d’essai ne démontrent pas la validité sur tout Domain.

Les 42 opérateurs autorisés sont implémentés sans interpréteur externe ni I/O. Les entiers suivent la taille signée du mot PHP (64 bits testés) ; les réels utilisent les valeurs finies binary64 de PHP. Le décalage logique à droite introduit des zéros. Un dépassement arithmétique entier peut promouvoir le résultat en réel ; `idiv` et `cvi` refusent les résultats hors de l’intervalle entier. Pour une demi-valeur négative, `round` choisit l’entier supérieur. Voir le [registre des sources et validations](coverage.json).

L’oracle indépendant lit les streams effectivement émis avec pypdf et les évalue avec Ghostscript. Il couvre des cas finis, sans preuve universelle. Le décalage signé négatif de Ghostscript diffère de la règle référencée de remplissage par zéro ; des tests normatifs directs couvrent séparément cette frontière. Les streams Type 4 importés ou préencodés opaques ne passent pas par ce constructeur typé.
