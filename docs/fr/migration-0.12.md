# Migration vers le core 0.12

[English](../migration-0.12.md) | Français

Le writer valide de nouveaux contrats natifs dans le graphe effectif du document, y compris lors des remplacements incrémentaux. Corriger les valeurs invalides auparavant acceptées avant de mettre un document à jour :

- Rotate est un entier multiple de 90 ; le réel 90.0 est invalide. UserUnit doit être positif ; les boîtes de production contiennent quatre coordonnées numériques. Tabs accepte R/C/S dès PDF 1.5, A/W dès PDF 2. Les coins inversés d’un rectangle restent valides.
- Les liens de parenté et de fratrie des signets doivent être réciproques ; Count signé décrit les descendants visibles à l’ouverture. C comporte trois composantes dans 0..1, F utilise seulement ses deux bits définis et Dest/A sont mutuellement exclusifs.
- Les actions standard actives nécessitent leurs entrées spécifiques et des types, indicateurs et versions valides. Les cibles annotation, OCG, DPart et instance RichMedia sont vérifiées dans leur document réel. Les déclencheurs réservés aux scripts du document et des champs nécessitent des actions JavaScript. Le writer n’exécute aucune action et ne consulte aucune ressource externe.
- Les assets RichMedia nommés nécessitent des noms textuels identiques dans l’arbre/F/UF et les restrictions natives d’URI relative. Les instances nécessitent un Subtype reconnu ; les noms de configuration sont uniques après décodage textuel. Les paramètres d’animation, de fenêtre et de position sont vérifiés. Les assets principaux non nommés et les styles d’animation inconnus avec leur repli normatif restent autorisés.
- Les corps Type 4 `Procedure` sont compilés dans `PostScriptCalculatorFunction::program`, immuable. Les instructions non prises en charge, procédures autonomes, profondeurs excessives et violations de pile/type/sorties démontrées sur un programme linéaire sont refusées. Utiliser `Calculator\Evaluator` et `CalculatorLimits` explicites pour l’évaluation, notamment des branches dépendantes de l’entrée. Voir les [contrats des calculatrices](../../resources/calculator-functions/README.fr.md). Des entrées d’essai ne prouvent pas la correction sur tout Domain.

Ces contrôles étendent les mécanismes natifs manuscrits ; les compteurs du catalogue généré ne certifient pas une conformité ISO complète. Conserver le `FileRevision` d’origine pour les mises à jour natives autorisées ; ces ajouts n’autorisent pas à modifier des fichiers signés ou importés.
