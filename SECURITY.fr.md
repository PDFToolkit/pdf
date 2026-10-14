# Politique de sécurité

[English](SECURITY.md) | Français

## Signaler une vulnérabilité

Utiliser le [signalement privé GitHub](https://github.com/PDFToolkit/pdf/security/advisories/new)
pour signaler une vulnérabilité présumée. Préciser la version touchée, une
reproduction minimale, le comportement attendu et l'impact observé. Éviter les
identifiants réels, les données clients et les signalements d'exploitation
publics avant qu'un correctif puisse être préparé.

La série actuellement prise en charge est **0.5**, avec PHP 8.3 ou supérieur.
Les corrections de sécurité s'appliquent à cette série et à `master`. Les séries
antérieures conservent leurs exigences publiées ; consulter le changelog avant
une mise à niveau.

## Frontières de confiance

Cette bibliothèque construit des objets PDF et sérialise des documents.
Son graphe d'objets et ses extensions PHP sont du code applicatif. Elle n'est
ni un lecteur PDF ni un assainisseur de contenus PDF arbitraires.

La lecture de PDF externes appartient à [pdftoolkit/pdf-reader](https://github.com/PDFToolkit/pdf-reader),
la conversion des fichiers image à [pdftoolkit/pdf-images](https://github.com/PDFToolkit/pdf-images),
et l'analyse TrueType à [pdftoolkit/pdf-fonts](https://github.com/PDFToolkit/pdf-fonts).
Ces packages optionnels ont leurs propres sources, tests et politiques de
sécurité. Signaler un problème au package touché ; installer uniquement le core
n'installe pas leurs analyseurs.

- Fournir le texte non fiable comme `LiteralString` ou `TextString` selon le
  contexte, et les noms comme `NameType`. Ne pas interpoler les données d'une
  requête dans des chaînes brutes de flux de contenu.
- Choisir les chemins de sortie dans l'application. `writeTo()` refuse les URI
  de flux, les chemins vides et les octets NUL, mais ne confine pas les chemins
  à un répertoire ni ne protège un répertoire contrôlé par un autre utilisateur.
- Conserver les documents générés à des emplacements où le serveur n'exécutera
  pas leurs octets comme du PHP ou un autre programme. Les flux PDF peuvent
  contenir des octets arbitraires.
- Autoriser les liens, JavaScript, actions de lancement, pièces jointes et
  spécifications de fichiers externes selon la politique de l'application.
  Le modèle PDF représente explicitement ces fonctionnalités.
- Borner la taille d'entrée, les dimensions des images, la profondeur du graphe,
  les pages, les filtres, la taille de sortie, la durée d'exécution et la mémoire
  des services traitant des données utilisateur. Le writer construit actuellement
  le fichier en mémoire.
- Maintenir PHP et ses extensions natives à jour. `composer audit --locked`
  vérifie les avis connus des packages Composer, y compris les dépendances de
  développement ; il n'audite pas le runtime PHP ni le système d'exploitation.

Le runtime n'inclut pas de définitions de police téléversées, ne récupère pas
les URL d'images, n'invoque pas de shell, ne désérialise pas d'objets PHP et
n'exécute pas les programmes de calcul PDF. Les implémentations personnalisées
de `BasicType`, `Operator` ou `Filter`, ainsi que les générateurs de développement,
doivent être du code de confiance.

Voir [la revue de sécurité](https://pdftoolkit.github.io/fr/guides/securite.html)
pour les surfaces vérifiées, la couverture des régressions et les responsabilités
applicatives restantes.

## Chiffrement historique

Le gestionnaire de sécurité de révision 5 du supplément Adobe est un mécanisme
de compatibilité explicite. Sa dérivation rapide de mot de passe est historique,
et AES-CBC n'authentifie pas le contenu du document. Ce n'est pas un choix de
sécurité moderne par défaut. Le backend OpenSSL optionnel nécessite une
extension PHP OpenSSL à jour. Le writer refuse les substitutions de crypt-filter
non prises en charge, les révisions incrémentales chiffrées et les chaînes
directes du trailer en dehors de l'identifiant du fichier. Seul le flux de
métadonnées du catalogue peut être exempté par `EncryptMetadata` ; les métadonnées
de pages et d'images restent chiffrées. Voir
[le guide du supplément Adobe](https://pdftoolkit.github.io/fr/guides/supplement-adobe.html)
pour le périmètre exact.
