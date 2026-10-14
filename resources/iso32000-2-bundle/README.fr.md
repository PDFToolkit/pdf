# Spécifications complémentaires d’ISO 32000-2

[English](README.md) | Français

Les onze PDF originaux fournis par le propriétaire du projet sont conservés dans
[`../reference-documents/`](../reference-documents/), sans modifier leurs noms ni leurs octets.
Ils proviennent de la [collection de normes sponsorisées de la PDF Association](https://www.pdfa.org/sponsored-standards/).
[`sources.json`](sources.json) indique pour chaque copie son identité, son
empreinte SHA-256, sa taille, son nombre de pages PDF physiques et sa collection
de corrections, lorsqu’elle en possède une.

| Référence | Sujet |
| --- | --- |
| ISO 32000-2:2020 EC3 | Spécification de base du PDF 2.0 et corrections issues des travaux de l’industrie |
| ISO/TS 32001:2022 EC3 | Algorithmes de hachage supplémentaires |
| ISO/TS 32002:2022 EC3 | Algorithmes de signature numérique supplémentaires |
| ISO/TS 32003:2023 | Chiffrement AES-GCM |
| ISO/TS 32004:2024 | Protection de l’intégrité des documents chiffrés, PDF MAC |
| ISO/TS 32005:2023 | Inclusion des espaces de noms de structure PDF 1.7 et PDF 2.0 |
| PDF Declarations | Déclarations XMP de normes ou profils externes |
| WTPDF 1.0.0 | Exigences du PDF balisé pour la réutilisation et l’accessibilité |
| Note d’application 001 | Compensation du point noir |
| Note d’application 002 | Fichiers associés |
| Note d’application 003 | Emplacements des flux de métadonnées d’objets |

La copie de la spécification de base précédemment fournie est conservée dans le
même dossier `reference-documents`. La nouvelle copie, dont le nom d’origine se
termine par `(1).pdf`, possède une empreinte différente et est conservée
séparément. L’empreinte identifie une
copie fournie précise ; elle ne désigne pas une nouvelle édition de la norme.

Les annotations EC3 font partie de la source : une résolution `Accepted` et une
résolution `Completed` ne possèdent pas la même autorité d’approbation. Il faut
lire les annotations et le texte des pages. Les trois notes d’application
expliquent des usages du PDF ; elles ne remplacent pas le texte ISO. WTPDF
comporte des exigences vérifiables automatiquement et d’autres qui demandent
une appréciation humaine du sens du contenu. Écrire une déclaration de
conformité et établir qu’elle est vraie sont deux opérations distinctes.

Chaque PDF conserve ses mentions et licences d’origine. Les publications de la
PDF Association indiquent CC-BY-4.0 ; les publications ISO conservent leur
copyright ISO. La présence des sources dans le dépôt ne modifie pas ces
mentions. Les originaux sont exclus des archives de distribution Composer :
l’installation de la bibliothèque ne télécharge pas la collection documentaire.

[`coverage.json`](coverage.json) consigne les fichiers d’implémentation, les
vérifications indépendantes et les limites explicites de chacun des onze documents.
Il distingue l’inventaire des dictionnaires de base, les algorithmes, les contrôles
de graphe et la conformité. Le [guide partagé du bundle](https://pdftoolkit.github.io/fr/guides/pdf-2-0-bundle.html)
décrit les mécanismes pris en charge et leurs limites.
