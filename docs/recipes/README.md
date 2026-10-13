# Recettes et exemples pratiques

Les [tutoriels](../tutorials/README.md) introduisent les objets du format par étapes. Les recettes combinent ces objets pour construire une présentation concrète. Chaque recette conserve son calcul de mise en page dans son script et explique les choix qui produisent le résultat.

| Recette | Résultat | Code |
| --- | --- | --- |
| [Un tableau dessiné explicitement](table.md) | En-tête, cinq lignes, fonds alternés, grille et total. | [table.php](../../examples/table.php) |

## Proposer une recette

Une contribution peut porter sur un sommaire imprimé, un graphique, une fiche ou une autre composition. Choisir un cas assez petit pour que le lecteur puisse suivre le graphe d'objets et ses opérateurs.

1. Ajouter un script autonome dans `examples/`, avec des données de démonstration reproductibles et un chemin de sortie optionnel.
2. Commenter les étapes qui expliquent les objets, les ressources, les références et les coordonnées. Le script charge `vendor/autoload.php` et utilise l'API du checkout.
3. Ajouter un guide dans `docs/recipes/`, avec une commande, un aperçu dérivé du PDF, les paramètres à essayer et les limites de la composition. Créditer l'auteur et les sources si la recette provient d'une contribution ou d'un travail existant.
4. Ajouter les liens dans cet index, vérifier le PDF dans un lecteur, exécuter `composer check` et contrôler le fichier produit avec qpdf.

Les recettes peuvent montrer des calculs de positions et des répétitions en PHP. Les valeurs, ressources et instructions PDF produites restent les objets du modèle. Une API de mise en page réutilisable peut être développée dans une bibliothèque distincte.

[Index des tutoriels](../tutorials/README.md) · [Retour au projet](../../README.md)
