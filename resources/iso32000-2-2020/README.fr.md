# Catalogue pilote ISO 32000-2:2020 EC3

[English](README.md) | Français

Ce répertoire identifie séparément l'édition PDF 2.0 et les schémas vérifiés de
cette étape. Il **ne contient pas le catalogue ISO 32000-2 complet et ne garantit
pas une conformité complète à PDF 2.0**.

La source fournie est `ISO_32000-2_sponsored_EC3.pdf`, 1 023 pages PDF physiques,
SHA-256 `90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230`.
Ses octets et son nom originaux sont conservés à la
[racine du dépôt](../../ISO_32000-2_sponsored_EC3.pdf).
Cette copie provient de l'[accès sponsorisé de la PDF Association](https://www.pdfa.org/sponsored-standards/)
et est publiée avec l'autorisation de redistribution confirmée par le contributeur.
Elle est exclue des archives Composer.
L'édition de la norme est ISO 32000-2:2020 ; EC3 est une collection de corrections
sélectionnée séparément, datée du 2026-06-01. Le PDF contenant la norme est en
PDF 1.7, ce qui ne change pas la version PDF 2.0 définie par son contenu.

| Tableau | Pages PDF physiques | Schémas d'entrées vérifiés |
| --- | --- | --- |
| 29, catalogue du document | 113–117 | 32 |
| 43, spécification de fichier | 149–152 | 16 |
| 356, espace de noms | 744 | 4 |
| 409a, liste de propriétés du contenu marqué avec fichiers associés | 1015 | 1 |

`dictionaries.json` contient des descriptions d'implémentation concises rédigées
pour ce projet. Les emplacements physiques et les contraintes vérifiées sont
accompagnés de la provenance structurée des corrections. Les paragraphes de la
norme ne sont pas reproduits. `coverage.json` indique explicitement le travail
restant.

La source explique les états de révision à la page physique 2 : `Completed`
correspond à une approbation ISO TC 171 SC 2 WG 8 ; `Accepted` correspond à une
approbation du PDF Technical Working Group de la PDF Association. Ces autorités
restent distinctes dans les objets immuables `ErrataReviewStatus` et
`ErrataResolution`. Les relations entre annotations et réponses ont été vérifiées
pour les corrections du pilote sur les objets directs et les références
indirectes. Le tableau 409a correspond au modèle corrigé de liste de propriétés
de l'[erratum 374](https://github.com/pdf-association/pdf-issues/issues/374) :
MCAF est un tableau dans un dictionnaire, et le contenu marqué utilise une
ressource de propriétés nommée.

Les classes concrètes sont dans `PDF\Specification\Iso32000_2`. Un
`SourceEdition` identifie la norme indépendamment du hash de la copie fournie et
de la version du fichier produit. ISO 32000-1 et le supplément Adobe PDF 1.7
restent séparés de ce corpus. `Catalogue` refuse des définitions de familles
mélangées, même lorsque leurs identifiants de tableaux sont différents.

Reproduire ou comparer les 61 fichiers PHP générés et leurs manifestes :

```console
python tools/generate_iso32000_2_pilot.py
python tools/generate_iso32000_2_pilot.py --check
```

Le générateur utilise le JSON pilote rédigé et vérifié ; il n'extrait pas le PDF
source. Un futur extracteur complet doit reconnaître les tableaux d'errata avec
suffixe alphabétique, les grilles continuées sans titre répété, les réponses de
révision aux annotations et les pages de remplacement ajoutées. Le texte seul
des pages ne suffit pas.

Les schémas d'objets vérifient les familles de valeurs PDF, les noms Type fixes,
les entrées requises sans condition et les contraintes de références retenues.
Les mécanismes natifs du graphe vérifient les règles contextuelles des fichiers
associés. La présence d'entrées pour les espaces de noms, DSS ou DPartRoot
n'implémente pas ces mécanismes. Tests :
`tests/Iso32000_2SpecificationTest.php`.

Les ajouts natifs sont listés séparément dans `coverage.json`, avec leurs vrais
fichiers d’implémentation, tests unitaires et validateurs indépendants :
en-tête/catalogue PDF20 et contrôle de version des révisions figées ; chaînes
typées UTF-8/UTF-16BE/PDFDoc ; associations AF ; contenu MCAF nommé avec faits
figés lors de l’émission ; ressources propres aux Form XObjects PDF20 ;
validation bornée des dates sources ; interdiction R5 et contraintes explicites
des permissions CMS documentaires. L’exemple associe le même CSV au catalogue,
à la page et au contenu marqué nommé, en séparant texte CID visible et métadonnées
UTF-8. `BeginAssociatedFilesMarkedContent` compose le jeton BDC existant et
n’ajoute aucun opérateur à l’annexe A.

Ces domaines ne couvrent pas l’interprétation de contenu opaque arbitraire,
R6, les graphes natifs d’espaces de noms/rôles, DSS/horodatages, arbres DPart,
enveloppes chiffrées ni la conformité PDF 2.0 complète. Les contrôles de date/MIME
conservent les informations fournies ; le core n’invente ni ne charge les
métadonnées des fichiers sources.
