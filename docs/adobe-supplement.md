# Supplément Adobe à ISO 32000

Le dépôt conserve deux documents de référence distincts :

- [ISO 32000-1:2008, PDF 1.7](../PDF32000_2008.pdf), 756 pages ;
- [Adobe Supplement to the ISO 32000](../adobe_supplement_iso32000.pdf), édition 1.0,
  juin 2008, 140 pages, `BaseVersion 1.7`, `ExtensionLevel 3`.

Tous les PDF Adobe fournis par le propriétaire du projet sont conservés avec leur
nom et leurs octets d'origine. Les empreintes, pages physiques, tableaux et clauses
servent à relier le code au document. Le supplément a l'empreinte SHA-256
`50d0bbc96028dca1a54cf3c671054536d26e9494dc52877061763b4aed08ac48`.

## Construire les objets d'extension

Les classes de `PDF\Specification\Adobe\Dictionary` représentent les dictionnaires
et leurs entrées. Celles de `PDF\Specification\Adobe\Definition` représentent leurs
définitions immuables. Elles composent explicitement les définitions ISO existantes
et les définitions Adobe communes : un champ inchangé garde sa source ISO, un champ
ajouté ou modifié garde sa source Adobe.

Les 61 tableaux de dictionnaires produisent 61 classes de dictionnaire, 61 classes
de définition et 282 classes d'entrée : 276 entrées des tableaux et six sous-types
prescrits dans la prose. Il y a donc 404 composants générés. Le catalogue séparé
conserve aussi les quatre tableaux de valeurs et le tableau d'attributs XML, les
algorithmes, prescriptions en prose, références externes et notes d'implémentation.

Les numéros du supplément restent ceux du PDF Reference à chapitres, comme `8.6c`
et `9.51a`. Ils ne sont pas renommés en numéros ISO. `SourceReference` contient
également le nom du document : une page 88 du supplément ne désigne pas une page
88 du document ISO.

| Famille | Propriétés représentées | Pages du supplément |
| --- | --- | --- |
| Déclarations et structure | Extensions, niveaux, ordre des tabulations, XFAResources, RelatedFiles | 23–26 |
| Collections | Folders, couleurs, présentation, vues, champs de chemin et d'identifiant | 28–36 |
| Annotations et actions | Polygon/PolyLine, Redact, SetOCGState, GoToE, actions et tabulations | 37–41 |
| Formulaires et signatures | Barcode, PaperMetaData, paramètres de symbologie, Lock et seed values | 42–48 |
| Géospatial et mesures | GEO, GCS/DCS, coordonnées, unités, PtData et Viewport | 48–54 |
| Contenus 3D | PRC, vues et nœuds, attributs de coupe, mesures linéaires/perpendiculaires/angulaires/radiales, notes | 55–76 |
| Médias enrichis | Annotations, fenêtres, activation/désactivation, contenus, configurations, instances, assets, paramètres et cue points | 76–99 |
| Artefacts | Numérotation Bates et Contents | 100–101 |

Les entrées contrôlent leurs types PDF, noms fixes, références indirectes et, lorsque
la source le prescrit, références des membres de tableaux. Un tableau direct de
références indirectes et une référence indirecte vers un tableau sont deux
constructions différentes. Les unions, dont `text string or array of text string`,
contrôlent aussi le domaine des membres.

La présence des schémas permet de construire ces structures dans un PDF. Elle ne
fournit pas un moteur de lecture multimédia, Flash ou 3D, un codec barcode, un moteur
XFA ni une transformation géodésique. Les signatures ont une construction et des
backends explicites indépendants des schémas. Les exigences
conditionnelles et les relations sémantiques qui n'ont pas encore de mécanisme
spécialisé restent identifiées dans les descriptions et la couverture.

## Objets spécialisés et exemple

`DeveloperPrefix`, `DeveloperExtension`, `DeveloperExtensionEntry` et
`DeveloperExtensions` composent la déclaration du catalogue. `BatesArtifact`
construit les propriétés de pagination ; `WindowDimension` construit des dimensions
Default/Min/Max résolues et cohérentes, en unités de l'espace utilisateur par défaut.

Le [tutoriel 13](tutorials/13-adobe-extensions.md) explique chaque étape d'une
déclaration Adobe et d'un pied de page Bates. Le PDF est généré en CI ; un lecteur
indépendant vérifie la déclaration, les propriétés de l'artefact, les numéros visibles
et l'équilibre BDC/EMC.

## Mesures 3D et préparation des codes-barres

`ThreeDUnitMapping` compose les échelles de création `TSm/TSn`, leurs éventuelles
surcharges utilisateur `USm/USn`, puis les échelles d'affichage `DSm/DSn`, selon
les pages 59–61. `map()` retourne un `MeasuredDistance`, avec sa valeur PDF et
son libellé d'unité. Les libellés conditionnellement nécessaires sont contrôlés,
ainsi que les divisions par zéro et les dépassements numériques.

La prose « m unités du modèle = n unités affichées » et l'équation imprimée
`Y = (m/n) X` sont contradictoires. L'appelant choisit obligatoirement
`UnitMappingInterpretation::PrintedEquation` ou `::UnitDescription` ; aucune
interprétation implicite ne masque cette ambiguïté du document.

`BarcodeModuleWidth` calcule `ceil(distanceInches × Resolution)` et construit
l'entrée `XSymWidth` entière. `BarcodeDataPreparation` conserve les octets pour
`DataPrep = 0` et leur applique Flate pour `DataPrep = 1`. Ces objets mettent en
œuvre la préparation et la formule des pages 46–47. Ils ne choisissent pas
l'encodage du texte et ne dessinent pas un symbole PDF417, QR ou Data Matrix :
les codecs de ces symbologies dépendent de normes externes.

## Chiffrement historique AES-256

Le supplément décrit le Standard Security Handler `V=5`, `R=5` avec `AESV3`.
Les objets de `PDF\Security` implémentent la préparation SASLprep Unicode 3.2,
la troncation UTF-8 à 127 octets, les credentials utilisateur et propriétaire,
la clé du fichier, les permissions et les opérations AES-CBC/ECB correspondantes.
Les tables Unicode sont figées et reproductibles ; aucun réglage de locale ou
version ICU installée ne décide de leur résultat.

`HistoricalRevision5Encryption` est une sélection explicite de ce protocole ancien.
Le backend `OpenSslAes256Backend` nécessite l'extension PHP `openssl`, optionnelle
pour le core. La production de PDF chiffrés exige PDF 1.7, la déclaration Adobe
niveau 3 et les identifiants de fichier explicites. Les références croisées,
identifiants et dictionnaire Encrypt restent en clair ; les chaînes et les flux
sont chiffrés dans leur domaine, après les filtres, sans double chiffrement.
Les métadonnées suivent `EncryptMetadata`.

Après avoir construit un `FileStructure` avec la déclaration Adobe niveau 3 et
deux identifiants directs de 16 octets dans `/ID`, sélectionner le handler :

```php
use PDF\Security\EncryptMetadata;
use PDF\Security\HistoricalRevision5SecurityHandler;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\Revision5Permissions;
use PDF\Security\Saslprep;
use PDF\Security\SystemRandomSource;
use PDF\Serialization\FileWriter;

$profile = new Saslprep();
$encryption = (new HistoricalRevision5SecurityHandler(
    new OpenSslAes256Backend(),
    new SystemRandomSource(),
))->create(
    $profile->prepare($userPassword),
    $profile->prepare($ownerPassword),
    new Revision5Permissions(-3904),
    new EncryptMetadata(false),
);
$bytes = (new FileWriter(encryption: $encryption))->serialize($file);
```

Les permissions sont les bits PDF déclarés par l'application ; elles ne
remplacent pas un contrôle d'accès sur les octets déchiffrés. Avec
`EncryptMetadata(false)`, seule la métadonnée du catalogue reste en clair, les
métadonnées de page et d'image restent chiffrées. Les chaînes directes du trailer
hors `/ID` sont refusées : une donnée privée supplémentaire doit appartenir à un
objet indirect chiffré. `revision()` conserve l'opération de chiffrement dans un
snapshot propre au document ; `IncrementalUpdate` la réutilise en préservant les
anciens octets, le dictionnaire Encrypt et le premier identifiant du fichier.

R=5 est un format historique dont la dérivation des mots de passe n'offre pas les
protections des révisions modernes. AES-CBC ne garantit pas l'authenticité du
contenu. Cette API sert à l'interopérabilité avec la spécification conservée ;
elle ne constitue pas un réglage de sécurité moderne par défaut.

`HistoricalStandardKeyDerivation` (R=2 à R=4) et
`HistoricalPublicKey256KeyDerivation` sont des primitives séparées du writer R=5.
La première reçoit des octets de mot de passe déjà encodés en PDFDocEncoding ;
la seconde reçoit les octets CMS déjà produits, dans leur ordre explicite.
`HistoricalStandardSecurityHandler` fournit désormais les opérations complètes
R=2 à R=4 ; `PublicKeySecurityHandler` compose les destinataires CMS et les clés
de filtres des formats s3/s4/s5. Les backends OpenSSL sont choisis explicitement,
avec des octets de certificats et de clés fournis par l'appelant. Le core ne
cherche aucun fichier de clé ni ne décide de la confiance dans un certificat.
Pour R=2 à R=4, le padding à 32 octets est conservé depuis
ISO 7.6.3.3, algorithme 2 (pages 69–70), bien que la reformulation Adobe de
l'étape 1 l'omette : les vecteurs qpdf vérifient cette décision d'interopérabilité.

Les filtres Crypt explicites suivent ISO 7.4.10 : leur clé n'est pas modifiée
par l'identifiant de l'objet. Pour les permissions publiques, le texte de 2008
indique l'ordre peu significatif d'abord ; une clarification ultérieure indique
l'ordre inverse. `PublicKeyPermissionByteOrder` rend ce choix obligatoire.
La formulation originale reste conservée ; la
[clarification publiée](https://pdf-issues.pdfa.org/32000-2-2020/clause07.html)
est une référence distincte.

Le [guide de couverture](coverage.md) distingue les constructions natives
des comportements de lecteur et standards externes qui restent séparés.

## Erreurs de source et comportement d'Acrobat

Les incohérences sont conservées et commentées. Par exemple, `Name` d'une
RichMediaConfiguration est imprimé comme `text tree` alors que l'exemple fournit
une chaîne ; PtData est décrit dans une cellule comme un dictionnaire alors que
la prose permet aussi un tableau. Les corrections appliquées au modèle ont une
`ImplementationCorrection`, avec la page et l'exemple qui motivent le choix.

La partie II décrit ce qu'Acrobat fait ou ne fait pas. Elle est conservée dans
la couverture et les références, avec les pages et identifiants de notes. Ces notes
de produit ne deviennent pas automatiquement des restrictions générales du format.
Les titres et numéros restent ceux du texte fourni, y compris les anomalies.

## Reproduire et vérifier

```sh
python tools/extract_adobe_supplement.py
python tools/generate_adobe_supplement.py
python tools/generate_adobe_supplement.py --check
python tools/build_saslprep_tables.py --check
php examples/adobe-extensions.php adobe-extensions.pdf
```

L'extraction utilise les colonnes et positions du PDF conservé. La génération
utilise le catalogue versionné ; les classes PHP ne chargent pas ce JSON à
l'exécution. Les tests contrôlent les domaines de toutes les entrées générées,
la composition des bases, les corrections et les contraintes négatives.
Les contrôles indépendants AES et SASLprep sont détaillés dans le code des tests
et dans la CI. La référence API du site relie chaque entrée à sa page Adobe ou ISO.
