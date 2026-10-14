# Historique des versions

[English](CHANGELOG.md) | Français

## [Unreleased]

## [0.6.1] - 2026-10-14

### Traçabilité des sources EC3

- Rattacher explicitement les ressources propres aux Form XObjects PDF 2.0 à
  l’erratum 292 Completed, page physique 287, §8.10.2 et tableau 93.
- Rattacher le placement du catalogue chiffré à l’erratum 439 Completed,
  pages physiques 77 et 112, §7.5.7 et §7.7.2. Ces deux règles étaient déjà
  implémentées et testées en 0.6.0 ; la correction complète leurs références.
- Préserver l’API PHP, les PDF produits et les versions des dépendances figées.

## [0.6.0] - 2026-10-14

### Premiers mécanismes PDF 2.0

- Ajoute les déclarations PDF 2.0 explicites dans l'en-tête du fichier et la
  version effective du catalogue. Conserve PDF 1.7 comme construction par défaut.
- Compose `TextString` avec des stratégies explicites PDFDoc/UTF-16BE et UTF-8
  PDF 2.0. Conserve les codes de caractères de police comme mécanisme distinct
  du contenu des pages.
- Ajoute les objets de relation de fichiers associés, tableaux AF, validation
  des propriétaires et construction corrigée MCAF avec propriétés nommées EC3.
  Vérifie les graphes émis et les révisions incrémentales ; les octets restent
  fournis explicitement.
- Introduit la provenance immuable des éditions et errata, en distinguant
  Completed, approuvé par ISO, d'Accepted, approuvé par le TWG de la PDF Association.
  Sépare l'identité de la copie privée, la norme et la version du fichier produit.
- Ajoute le pilote distinct ISO 32000-2:2020 EC3 : quatre schémas de dictionnaires,
  53 classes d'entrées, pages physiques et générateur reproductible. Refuse les
  familles/profils mélangés et limite la règle OPI à sa source ISO 32000-1 réelle.
- Distingue les fonctionnalités introduites, dépréciées et interdites. Refuse
  le chiffrement révision 5 en PDF 2.0 et applique ses obligations de permissions
  de sécurité à clé publique.
- Ajoute un exemple PDF 2.0 avec métadonnées Unicode, CSV incorporé associé au
  catalogue et à la page, propriétés de contenu marqué nommées et police incorporée.

### Périmètre et distribution

- Conserve PHP 8.3 minimum et la validation sous PHP 8.3, 8.4 et 8.5.
  Les modules optionnels 0.1.7 acceptent aussi la série 0.6 du core.
- Préserve les catalogues ISO 32000-1 et du supplément Adobe et les PDF conservés.
  Enregistre la provenance ISO 32000-2 sans publier sa copie personnalisée.
- Maintient en attente le chiffrement révision 6, le catalogue PDF 2.0 complet,
  les espaces de noms natifs, DSS/horodatages, parties documentaires et conformité
  complète. Publie des guides anglais/français et un tutoriel avec PDF réel.

## [0.5.5] - 2026-10-14

### Documentation bilingue

- Utilise l'anglais par défaut pour le README et les entrées documentaires, avec
  versions françaises intégrales du README, de la politique de sécurité et de
  l'historique des versions.
- Associe les pointeurs de guides et tutoriels conservés à leurs versions françaises
  et relie la langue correspondante du site documentaire central.
- Conserve l'API PHP, le minimum PHP 8.3, les exemples exécutables, ressources de
  spécification et octets des PDF de référence originaux.
- Documente les commentaires des exemples exécutables en anglais en conservant
  tous les tokens exécutés et les données ; corrige deux commentaires mal encodés.
- Fixe les modules de développement facultatifs aux versions publiées 0.1.6.

## [0.5.4] - 2026-10-14

### Distribution

- Publie `roukmoute/pdf` et les trois modules optionnels PDFToolkit sur Packagist.
  Les applications installent les versions stables avec `composer require` ;
  les déclarations de dépôts VCS personnalisés ne sont plus nécessaires.
- Retire les dépôts VCS personnalisés du manifeste de développement et verrouille
  les versions publiées 0.1.5 des modules optionnels. Conserve les versions des
  outils sélectionnés et l'indépendance du core envers les modules en production.
- Relie les métadonnées Composer et les guides d'installation à la documentation
  commune et aux pages des packages. Vérifie les consommateurs stables, les commits
  des tags indexés et la synchronisation des packages lors des publications.

L'API PHP, le minimum PHP 8.3, les PDF de référence originaux et les sources
exécutables restent inchangés.

## [0.5.3] - 2026-10-14

### Documentation

- Déplace les guides communs, tutoriels, ressources du site, export API et
  génération du site dans
  [PDFToolkit/pdftoolkit.github.io](https://github.com/PDFToolkit/pdftoolkit.github.io).
  La documentation commune est sur https://pdftoolkit.github.io/fr/ avec des entrées
  dédiées au core, au lecteur, aux images et aux polices. Les sources PHP, exemples
  exécutables, fixtures, PDF Adobe originaux et catalogues restent dans le core.
- Actualise les liens du dépôt et de sécurité vers le site commun. Documente sa
  responsabilité et sa publication distinctes, avec des versions de packages
  publiées et des commits exacts pour la provenance de l'API et des exemples.
- Conserve le site de projet `/pdf/` avec des redirections HTML validées qui
  transmettent les paramètres et fragments, et des copies inchangées des fichiers
  binaires téléchargeables. Le workflow de compatibilité utilise un commit
  immuable des outils documentaires et vérifie toute sa sortie avant déploiement.

### Dépendances de développement

- Verrouille les modules optionnels sur leurs versions documentaires publiées
  0.1.4. Le core reste indépendant en production. PHP 8.3 et l'API PHP sont inchangés.

## [0.5.2] - 2026-10-14

### Corrections

- Omet le `Count` de la racine des signets lorsque tous les éléments de premier
  niveau sont explicitement fermés, y compris leurs descendants ouverts masqués
  par un parent fermé. Conserve le total visible existant d'un élément de premier
  niveau explicitement ouvert, les comptes signés des éléments et les liens entre
  voisins (tableau ISO 152, page physique 376 ; errata Adobe PDF 1.7, page 10).

### Documentation et intégrité des références

- Conserve inchangés les quatre originaux Adobe PDF 1.7 fournis : référence de
  novembre 2006, errata d'octobre 2007, addendum de caviardisation et portfolio
  Acrobat SDK 8.1. Enregistre leurs empreintes et les quatre sources incorporées
  au portfolio, dont trois doublons exacts des documents fournis séparément.
- Explique leur utilité, les différences entre éditions et la distinction entre
  un dictionnaire `Redact` et la suppression destructive de contenu. L'archivage
  ne remplace pas les définitions ISO/Adobe actives et ne prétend pas réaliser
  un audit complet des errata.
- Ajoute un guide documentaire et des téléchargements validés. Vérifie les octets
  sources, pages physiques et pièces jointes indépendamment dans les deux workflows
  CI. Exclut les quatre nouveaux PDF des archives Composer tout en les conservant
  dans Git.
- Conserve PHP 8.3 minimum et les modules optionnels publiés 0.1.3. N'introduit
  aucune nouvelle dépendance de production ni prise en charge de PDF 2.0.

## [0.5.1] - 2026-10-14

### Corrections

- Marque les apparences de texte et de choix modifiables de l'exemple AcroForm
  avec `/Tx BMC ... EMC`, pour qu'un lecteur remplace le texte variable existant
  au lieu d'ajouter une autre valeur. Maintient les bordures hors de la section
  remplaçable et borne le clipping et l'état graphique à cette section
  (ISO 12.7.3.3, page physique 444 ; supplément Adobe, page 121, note 119).
- Dessine la liste de choix avec ses deux libellés réels et les lignes sélectionnées,
  en conservant `FR`/`BE` comme valeurs exportées. Applique la même structure de
  texte variable au champ de référence de l'exemple de politique de signature.
- Explique l'édition et le remplacement des apparences dans le tutoriel AcroForm.
  Ajoute des contrôles de régression indépendants sur les flux analysés et des
  preuves de remplacement répété ; l'absence du marqueur reste un repli permis
  pour les apparences génériques.

## [0.5.0] - 2026-10-13

Historiques de révision immuables, générations natives d'objets, navigation,
pièces jointes, collections portables et politiques de champs de signature
appliquées. PHP 8.3 reste le minimum, avec validation sur PHP 8.3, 8.4 et 8.5.

### Ajouts

- Retourne les instantanés initiaux exacts de `LinearizedWriter::revision()` et
  ajoute des mises à jour incrémentales ordinaires en conservant chaque octet
  original et l'étendue des hints.
- Modélise les listes libres immuables, la libération et la réutilisation, les
  générations retirées et les index xref effectifs ordinaires/comprimés. Conserve
  les anciens alias avec leurs numéro/génération d'origine ; distingue les trous
  jamais utilisés et la génération permanente 65535.
- Construit les étiquettes de pages, destinations nommées et explicites, calculs
  de viewport, articles et beads, transitions et séquences de présentation.
  Valide leurs relations effectives réelles avec le catalogue et les pages après
  les mises à jour.
- Construit les spécifications de fichiers, octets incorporés et métadonnées
  dérivées, informations de ressources Mac, tableaux de fichiers liés, arbres
  de noms de pièces jointes et schémas/éléments/tris de collections portables.
  Déclare les données et filtres de flux externes sans charger de fichiers externes.
- Applique les seeds, verrous, faits de certificats, apparences réelles et contrats
  de preuve écrits des signatures avant finalisation. Vérifie la sortie CMS/PKCS#1
  réelle sur les octets réservés exacts et fige les identifiants et le digest choisis.
- Conserve les restrictions historiques de certification et de verrouillage lors
  de la signature d'un autre champ, y compris les descendants renommés et les
  valeurs de signature retirées. Impose une certification première et unique et
  la cardinalité des signatures d'usage, sans rendre le lien optionnel Perms/DocMDP
  obligatoire.
- Ajoute quatre tutoriels expliqués avec de vrais PDF pour la navigation, les
  pièces jointes, les générations et les politiques de champs de signature.
  Ajoute des oracles indépendants permanents pour les nouveaux mécanismes.

### Corrections

- Capture les dictionnaires de flux et octets encodés avant chiffrement depuis
  l'émission réelle du même appel, y compris les entrées gérées et identités
  remappées. Borne les preuves d'émission au numéro et à la génération.
- Conserve les objets temporaires pendant la découverte du graphe pour ne pas
  omettre des entrées générées lorsque PHP réutilise un identifiant d'objet.
  Distingue les valeurs directes de leurs éventuelles identités indirectes historiques.
- Valide les ressources, boîtes et rotations effectives de pages, ainsi que l'ordre
  des octets et limites des arbres de noms. Vérifie les relations noms de fichiers,
  EF/RF/CI et schémas de collection avec les objets figés et remplacés.
- Résout les déclarations de flux externes avec leurs générations réelles avant
  une exemption des données de fichier. Conserve les remplacements internes explicites
  et refuse la perte d'une dépendance externe historique inchangée.
- Conserve la version F originale des spécifications de fichier et la clause source
  correcte poursuivie pour PageLabels ; impose la contrainte de référence indirecte
  imprimée pour CI.
- Vérifie les résultats de signature OpenSSL natifs et refuse les identifiants
  incompatibles, déclarations de digest incohérentes, DER malformé ou faits de
  vérification rejoués.

### Dépendances de développement

- Verrouille les modules optionnels publiés 0.1.3, qui acceptent le core
  `^0.2 || ^0.3 || ^0.4 || ^0.5`. Le core n'en dépend toujours pas en production.

### Périmètre

- Les définitions de dictionnaires restent complètes pour les deux références
  conservées. Ces ajouts ne prétendent pas couvrir toute la conformité sémantique
  ni toutes les procédures natives des sources.
- L'analyse générale authentifiée des différences de révisions DocMDP/FieldMDP/UR,
  les règles restantes d'annotations/mesures/média/fonctions et les procédures
  d'apparence des codes-barres restent suivies dans
  [l'inventaire de couverture](docs/fr/coverage.md).
- L'analyse/conversion externe, le rendu, l'exécution XFA/JavaScript/média,
  l'acquisition d'horodatages/révocations et la confiance des certificats restent
  des composants externes explicites.
- Voir [le guide 0.5](docs/fr/migration-0.5.md) pour les nouvelles API et la distinction
  entre objets écrits et instantanés émis.

## [0.4.0] - 2026-10-13

Mécanismes d'écriture natifs avec construction objet explicite, contrôles
indépendants d'interopérabilité et inventaire séparant les définitions des
algorithmes et règles sémantiques. PHP 8.3 reste le minimum.

### Ajouts

- Construit les champs, widgets, apparences AcroForm et FDF ; les structures
  balisées, relations MCID/MCR/OBJR, ParentTree et IDTree.
- Construit les espaces de couleur calibrés et spéciaux, motifs et les sept types
  de shading, y compris les maillages binaires natifs ; images échantillonnées,
  masques, transparence, mélange, demi-teintes et configurations de contenu optionnel.
- Modélise les familles de polices natives, programmes explicites, métriques CID
  horizontales et verticales, vecteurs d'encodage et CMaps. Borne l'unicité des noms
  de sous-ensemble au document.
- Encode LZW avec les deux valeurs EarlyChange et les prédicteurs TIFF/PNG aux
  profondeurs permises. Valide transitions, réinitialisations et octets décodés
  indépendamment.
- Écrit le chiffrement par mot de passe ISO R=2/3/4 et le chiffrement CMS explicite
  des destinataires avec des clés par filtre. Conserve les révisions incrémentales
  chiffrées, exemptions de métadonnées et permissions de destinataires.
- Signe les plages d'octets fixes dans les trois formats ISO de signature. Modélise
  les politiques intrinsèques seed, certificat, DocMDP, FieldMDP et droits d'usage ;
  refuse les politiques requises dont l'orchestration reste non prise en charge.
- Écrit les références croisées hybrides et la linéarisation native de l'annexe F,
  avec ressources partagées, miniatures, fichiers incorporés, flux d'objets et
  variantes chiffrées.
- Ajoute neuf tutoriels expliqués avec PDF générés, documentation de composants
  recherchable et validations reproductibles par qpdf, pypdf, pyHanko, ASN.1 et
  contrôles cryptographiques.
- Ajoute des inventaires de couverture sourcés pour les deux PDF conservés ;
  distingue les définitions complètes des sémantiques natives restantes et des
  processeurs externes.

### Modifications

- Remplace les cinq fabriques statiques restantes de production par des valeurs
  et constructeurs explicites. Voir [le guide de migration](docs/fr/migration-0.4.md).
- Encode les chaînes textuelles en PDFDocEncoding si possible, sinon en UTF-16BE
  avec BOM ; expose des noms explicites de sous-ensembles de polices et des
  dispositions d'échantillons normalisées.
- Verrouille les modules documentaires de développement publiés 0.1.2, qui acceptent
  `^0.2 || ^0.3 || ^0.4` ; les dépendances de production restent les extensions PHP.

### Corrections

- Conserve les contraintes sources d'orientation d'attributs, padding, fonctions
  de shading et tableaux poursuivis sans perdre la provenance imprimée.
- Fige les conteneurs directs de révision, résout les liaisons effectives et refuse
  les références structurelles à des pages hors de l'arbre effectif.
- Empêche le remplacement d'Encrypt dans un flux d'objets et les exemptions de
  métadonnées périmées ; applique directement les clés Crypt explicites selon
  ISO 7.4.10.
- Exige un état d'apparence de widget pour chaque branche d'apparence à états.
  Impose les versions requises par l'algorithme natif de signature et la taille
  de clé avant signature.

### Périmètre

- Cette version ne prétend pas implémenter entièrement les deux documents sources.
  La réutilisation d'objets libres, les révisions après linéarisation, l'orchestration
  des politiques de signature et d'autres règles de graphe, annotations et ressources
  restent suivies dans [l'inventaire de couverture](docs/fr/coverage.md).
- L'analyse de PDF externes, conversion images/polices, rendu, XFA, exécution
  JavaScript, codecs média, transformations géodésiques et confiance des certificats
  restent des responsabilités distinctes.

## [0.3.0] - 2026-10-13

Adobe PDF 1.7, niveau d'extension 3, avec conservation de la spécification fournie
et composition de ses définitions avec le modèle ISO. PHP 8.3 reste le minimum.

### Ajouts

- Conserve `adobe_supplement_iso32000.pdf` octet pour octet avec SHA-256, références
  de pages physiques et règle permanente pour préserver les futurs PDF Adobe fournis.
  Les deux PDF de spécification sont téléchargeables sur le site et leurs empreintes
  sont vérifiées lors de sa construction.
- Modélise les 61 tableaux imprimés de dictionnaires du supplément avec 404
  composants générés de dictionnaires, définitions immuables et entrées. Les
  entrées ISO et Adobe gardent leur provenance respective. Inventorie séparément
  les 66 tableaux, algorithmes, prescriptions en prose, notes d'implémentation
  produit, références externes et anomalies sources.
- Ajoute des objets explicites d'extensions développeur, artefacts Bates et
  dimensions résolues de fenêtres rich-media. Un tutoriel produit un PDF déclarant
  le niveau Adobe 3 et affichant une pagination Bates.
- Implémente les unités 3D avec une interprétation explicite de l'équation et de
  la description d'unité contradictoires. Ajoute le calcul de largeur de module
  des codes-barres et la préparation DataPrep ; les codecs de symbologie restent
  des standards externes.
- Implémente le gestionnaire Standard historique R=5 choisi explicitement,
  SASLprep Unicode 3.2, identifiants, permissions et sérialisation AES-256 des
  chaînes/flux. Son backend OpenSSL optionnel n'est pas une dépendance de production
  pour la génération ordinaire. Valide l'interopérabilité avec qpdf et pypdf,
  vecteurs AES officiels et oracle Unicode indépendant.
- Ajoute des dérivations de clés par mot de passe R=2–R=4 séparées et le digest
  SHA-256 des destinataires à clé publique, validés par vecteurs indépendants.
  Ces primitives n'ajoutent pas de writer RC4/AES-128, analyse CMS ou chiffrement
  de certificat.

### Corrections

- Impose les déclarations d'extension directes dans le graphe selon ISO 7.12.1,
  et valide correctement les unions commençant par des tableaux et leurs membres
  indirects. Enregistre les incohérences sources comme des décisions explicites
  documentées par page.
- Refuse les chaînes directes du trailer hors `/ID` lors du chiffrement.
  Respecte uniquement l'exemption des métadonnées du catalogue ; conserve le
  chiffrement des métadonnées de pages et images. Empêche le double chiffrement
  des flux de contenu et d'objets, exemptions de signature invalides et instantanés
  de révisions chiffrées non pris en charge.
- Reproduit les tables Unicode entre versions Python grâce aux plages RFC 3454
  conservées et à l'exclusion explicite des décompositions algorithmiques Hangul,
  déjà traitées séparément par le normaliseur PHP.
- Épingle qpdf indépendant sur les binaires officiels 12.4.2 vérifiés par empreinte
  en CI. Les anciennes versions interprètent mal les métadonnées chiffrées hors
  catalogue ; les fixtures et le writer conservent les frontières correctes de
  métadonnées de la spécification.

### Compatibilité et périmètre

- `CatalogDictionary\Extensions` accepte désormais uniquement un dictionnaire
  direct. Remplacer les déclarations indirectes par des dictionnaires directs ;
  les références imbriquées et flux sont aussi refusés. Toutes les déclarations
  doivent contenir au moins un préfixe développeur avant sérialisation.
- Les modules optionnels 0.1.1 acceptent `^0.2 || ^0.3` ; les dépendances de
  développement et sources documentaires sont fixées aux tags publiés. Le core
  n'en dépend toujours pas à l'exécution.
- R=5 est une fonctionnalité historique d'interopérabilité, pas un choix moderne
  par défaut ; sa dérivation de mot de passe est ancienne et AES-CBC n'authentifie
  pas le contenu. Le rendu 3D/rich-media, traitement XFA, transformations géodésiques,
  signatures, codecs de symbologie et révisions incrémentales chiffrées sont hors
  du comportement implémenté dans cette version. Voir
  [le guide du supplément](docs/fr/adobe-supplement.md) pour le périmètre exact.

## [0.2.1] - 2026-10-13

Corrections d'intégration des checkouts de développement et constructions
documentaires. Le modèle de production et l'API sont inchangés depuis 0.2.0.

### Corrections

- Exige la série stable 0.1 des modules optionnels en développement et verrouille
  leurs tags publiés 0.1.0, à la place des commits de préparation initiaux.
- Identifie la branche de développement et les checkouts de validation du core
  comme `0.2.x-dev` pour que la contrainte stable `^0.2` des modules accepte le
  checkout courant.
- Fixe les sources des tutoriels et de référence API commune aux commits publiés
  des modules et actualise la version stable affichée du core.

## [0.2.0] - 2026-10-13

Core compatible PHP 8.3 et modules optionnels versionnés séparément. Cette
version mineure inclut les changements d'API ci-dessous ; les tags 0.1 publiés
conservent leur exigence PHP 8.5.

### Ajouts

- Tutoriel de lecture de pages utilisant le PDF généré indépendamment du lecteur,
  avec toutes les pages d'entrée, attributs hérités vérifiés, résultats ordonnés
  des flux de contenu et exemple PHP complet. Les liens API et sources sont fixés
  au commit testé du module.
- Incorporation explicite de polices TrueType/CID statiques avec correspondances
  Unicode, métriques, FontFile2, descripteurs, Type0 Identity-H, CIDToGIDMap,
  largeurs et flux ToUnicode.
- Représentations explicites d'images échantillonnées et DCT, construction de masques
  alpha PNG et sémantique de décodage CMYK Adobe, composées à partir des lecteurs
  bornés du module images.
- Révisions de fichier figées et mises à jour incrémentales avec alias d'identités
  historiques, offsets xref absolus, héritage du trailer et identifiants de fichier
  changeants, y compris le remplacement de membres comprimés.
- Tutoriels dédiés et exemples PDF rendus pour les polices, fichiers image et
  révisions incrémentales.
- Site GitHub Pages en français avec référence recherchable de chaque composant
  PHP, contraintes et liens sources, tutoriels avec téléchargements PDF générés
  et aperçus navigateur, validation automatisée des liens et PDF avant déploiement.
- Parcours de tutoriels en français avec scripts expliqués, aperçus rendus,
  expériences et références : premier document, pages et texte, dessin, navigation,
  liens, images, dégradés et objets comprimés.
- Recette pratique de tableau et instructions pour contribuer d'autres exemples.

### Modifications

- Transfère le projet à PDFToolkit et sépare les lecteurs de formats externes
  dans `pdftoolkit/pdf-reader`, `pdftoolkit/pdf-images` et `pdftoolkit/pdf-fonts`.
  Conserve les objets PDF, métriques, CMaps, images et écriture dans le core sans
  dépendance de production sur ces modules.
- Compose les descripteurs et correspondances de caractères avec des valeurs PDF
  explicites dans le core ; déplace l'analyse TrueType et les fabriques de conversion
  dans le module polices, et l'analyse PNG/JPEG dans le module images.
- Documente les quatre API au même endroit, avec badges de packages optionnels
  et liens sources fixés au commit verrouillé de chaque module.
- Abaisse le minimum PHP à 8.3 en conservant les constantes typées, classes readonly
  et modèle objet ; garde le diagnostic natif `NoDiscard` sous PHP 8.5.
- Utilise PHPUnit 12 et exécute les contrôles qualité et validations PDF sous
  PHP 8.3, 8.4 et 8.5 ; construit la documentation avec PHP 8.3.
- Exécute PHPStan sur les exemples, puis génère et valide chaque exemple avec
  qpdf en CI.
- Déplace la racine de la hiérarchie des signets vers `PDF\Document\OutlineDictionary`,
  avec les éléments et destinations.
- Renomme l'entrée `Extension` en `Extensions` selon la clé PDF. Ces changements
  d'API n'ont pas d'alias de compatibilité.
- Valide les catalogues et ressources via leurs définitions, y compris les entrées
  génériques ajoutées au constructeur ou avec `with()`.

### Corrections

- Émet les références croisées libres des numéros inutilisés plutôt qu'omettre
  les trous initiaux ; borne la capacité cumulée avant allocation des entrées.
- Conserve les types de références historiques et valeurs Type lors des remplacements
  incrémentaux ; refuse les anciens numéros libres, changements de génération et
  remplacements des anciens flux structurels.
- Découvre les références dans les valeurs directes du trailer sans écrire d'objets
  indirects orphelins inutiles.
- Exige des références indirectes pour `Dests`, `Outlines` et `Threads` du catalogue
  selon le tableau 28. Les constructeurs spécialisés vérifient toujours leurs cibles.

### Suppressions

- Alias inutilisé `PDF\Dictionary\CatalogDictionary` et registre de constantes
  d'entrées du catalogue. Les entrées étendent directement l'objet entrée PDF.
- Wrappers de commodité `PDF\Text\Font` et `PDF\Text\TextObject`. Composer
  `BeginTextObject`, `SetTextFontAndSize`, `ShowText` et `EndTextObject` explicitement
  dans un `ContentStreams`.

### Exigences et compatibilité

- PHP 8.3 ou supérieur, avec `ctype`, `iconv` et `zlib` ; CI PHP 8.3, 8.4 et 8.5.
- Les modules optionnels `pdftoolkit/pdf-reader`, `pdftoolkit/pdf-images` et
  `pdftoolkit/pdf-fonts` commencent à 0.1.0 et dépendent de la série 0.2 du core.
  Le core n'en dépend pas en production.
- Avant 1.0, les versions mineures peuvent modifier noms et signatures. Les
  versions correctives conservent l'API de leur série mineure.
- Chiffrement, signatures, sous-ensembles de polices, shaping complexe, import
  de graphes externes et linéarisation restent hors des mécanismes implémentés.
  Voir [le document de couverture](docs/fr/specification.md).

## [0.1.1] - 2026-10-13

Corrections de sécurité de l'API 0.1 publiée. Les changements d'API et tutoriels
supplémentaires sur master ne font pas partie de cette version de maintenance.

### Corrections

- Refuse les séquences ambiguës `EI` d'images inline acceptées sans blanc préalable
  par les lecteurs tolérants.
- Utilise une arithmétique entière contrôlée pour les tailles inline et les bornes
  d'identifiants de références croisées.
- Valide les graphes d'opérandes directs partagés une fois par identité tout en
  gardant la détection de cycles actifs.
- Refuse les chemins vides/NUL et URI de flux avant écriture, et signale les erreurs
  sans divulguer le chemin de destination.

### Ajouts

- Tests de régression de sécurité, signalement privé et politique documentée
  de frontières de confiance.
- Audit des dépendances Composer verrouillées en CI.

## [0.1.0] - 2026-10-13

Première version publique du modèle objet explicite PDF 1.7 / ISO 32000-1:2008
pour PHP 8.5.

### Ajouts

- Primitives, entrées, références indirectes et définitions immuables sous forme
  d'objets, avec identité propre au document plutôt qu'un singleton global.
- Writer complet avec longueurs de flux exactes en octets, tables et flux de
  références croisées, et flux d'objets comprimés.
- Opérateurs typés, images inline, états graphique/texte et validation structurelle
  du contenu.
- Arbres de pages avec héritage et contrôles de propriété, ressources, polices
  standard, métadonnées, destinations, signets, arbres de noms et de nombres.
- Quatre représentations de fonctions PDF, avec échantillons empaquetés et
  programmes de calcul typés.
- Inventaire traçable de 392 tableaux, 263 définitions, 1 405 classes d'entrées
  et 73 tokens d'opérateurs, avec génération reproductible.
- Exemples exécutables, 147 tests et 7 189 assertions, PHPStan niveau 8,
  contrôles PSR-12 et qpdf indépendant en CI.

### Exigences et compatibilité

- PHP 8.5 ou supérieur, avec `ctype`, `iconv` et `zlib`.
- L'API 0.x peut évoluer avant 1.0.
- Cette version ne prétend pas à la conformité PDF complète. Chiffrement,
  signatures, sous-ensembles de polices, codecs image spécialisés, mises à jour
  incrémentales, linéarisation et autres contraintes sémantiques restent à venir.
  Voir [le document de couverture](docs/fr/specification.md).

[Unreleased]: https://github.com/PDFToolkit/pdf/compare/v0.6.0...HEAD
[0.5.4]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.4
[0.5.3]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.3
[0.5.2]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.2
[0.5.1]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.1
[0.5.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.0
[0.4.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.4.0
[0.3.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.3.0
[0.2.1]: https://github.com/PDFToolkit/pdf/releases/tag/v0.2.1
[0.2.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.2.0
[0.1.1]: https://github.com/PDFToolkit/pdf/releases/tag/v0.1.1
[0.1.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.1.0
[0.5.5]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.5
[0.6.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.6.0
