# PDF

[English](README.md) | Français

Un modèle objet et writer explicites pour **PDF 1.7 / ISO 32000-1:2008** et
**PDF 2.0 / ISO 32000-2:2020 EC3**, avec les spécifications complémentaires de
chiffrement, signatures, métadonnées et structure balisée. PHP 8.3 ou supérieur, 64 bits.

Cette bibliothèque représente la syntaxe et les structures du format, jusque dans ses noms, nombres, références, entrées de dictionnaire et opérateurs. Elle ne cherche pas à fournir une API de mise en page. Une bibliothèque de plus haut niveau peut construire ces objets et conserver le contrôle de leur composition.

Le projet fait partie de l'organisation [PDFToolkit](https://github.com/PDFToolkit). Ce dépôt est le **core** : il contient les objets et mécanismes décrits par la norme Adobe/ISO, dont l'écriture. La lecture de PDF externes, la conversion des fichiers PNG/JPEG et l'analyse des fontes TrueType appartiennent à trois packages optionnels. Chaque module dépend du core ; installer le core pour générer un PDF n'installe aucun de ces modules.

La [documentation en ligne](https://pdftoolkit.github.io/fr/) réunit les guides, la référence de tous les composants et les tutoriels avec leur code et leurs PDF consultables. Elle est maintenue dans [PDFToolkit/pdftoolkit.github.io](https://github.com/PDFToolkit/pdftoolkit.github.io), à partir de versions publiées fixées à leurs commits exacts. Les pages indiquent séparément les versions des packages et le commit de la documentation.

La série `0.x` est destinée aux développeurs qui souhaitent construire et explorer ce modèle. Avant la version `1.0`, une nouvelle version mineure peut modifier les noms et signatures ; les versions correctives conservent l'API de leur série. Le périmètre implémenté et les limites figurent dans [la couverture de la spécification](https://pdftoolkit.github.io/fr/guides/couverture.html).

Les dix-huit PDF de référence originaux sont regroupés dans [`resources/reference-documents/`](resources/reference-documents/README.fr.md), avec leurs noms et octets d’origine. Les définitions se fondent sur les documents Adobe/ISO [PDF32000_2008.pdf](resources/reference-documents/PDF32000_2008.pdf) et [adobe_supplement_iso32000.pdf](resources/reference-documents/adobe_supplement_iso32000.pdf). Le [guide du supplément](https://pdftoolkit.github.io/fr/guides/supplement-adobe.html) explique les extensions Adobe du PDF 1.7, niveau 3, leur provenance et leurs mécanismes. Le [guide des références Adobe](https://pdftoolkit.github.io/fr/guides/references-adobe.html) présente aussi la référence de 2006, ses errata, l'addendum de caviardisation et leur portfolio. Tous les PDF Adobe fournis au projet sont conservés avec leur nom et leurs octets d'origine ; les PDF de référence sont exclus des archives Composer. Le [PDF ISO 32000-2:2020 EC3 fourni](resources/reference-documents/ISO_32000-2_sponsored_EC3.pdf), obtenu via l’[accès sponsorisé de la PDF Association](https://www.pdfa.org/sponsored-standards/), est conservé sans modification dans ce dépôt et exclu des archives Composer. Son édition, ses pages physiques et son empreinte sont enregistrées dans un catalogue séparé. Les profils PDF/A, PDF/X et PDF/UA demandent leurs propres mécanismes de conformité.

## Spécifications complémentaires PDF 2.0

Le core 0.7 ajoute AES-CBC R=6 et AES-GCM R=7, les signatures SHA-3/SHAKE256 et
à courbes nommées, la protection d’intégrité PDF MAC, les espaces de noms de
structure et les règles d’inclusion TS 32005. Les déclarations XMP, métadonnées
d’objets, compensation du point noir, parties documentaires et enveloppes de
contenu chiffré sont des objets explicites. Les horodatages RFC3161 et DSS/VRI
relient les preuves de validation aux révisions réellement signées. Les objets
géospatiaux décrivent systèmes de coordonnées, viewports, points de contrôle
appariés, matrices affines et nuages de points.
Les destinations de structure ciblent de véritables éléments logiques, avec
résolution ordonnée du contenu, replis de page explicites et ID distants en octets.
Le [guide du bundle](https://pdftoolkit.github.io/fr/guides/pdf-2-0-bundle.html)
explique les sources, algorithmes, relations et contrôles indépendants.

Les onze PDF fournis sont conservés sans modification dans
[la collection de références](resources/iso32000-2-bundle/README.fr.md), avec leur
provenance PDF Association, identité binaire, pages physiques et annotations.
Ils sont exclus des distributions Composer. La copie antérieure d’ISO 32000-2
reste disponible séparément ; des empreintes différentes n’indiquent pas une
nouvelle édition.

Le [guide PDF 2.0](https://pdftoolkit.github.io/fr/guides/pdf-2-0.html) explique
les versions, chaînes Unicode et fichiers associés. Les tutoriels ajoutent les
[métadonnées et déclarations](https://pdftoolkit.github.io/fr/tutorials/28-pdf20-metadonnees-declarations.html),
[enveloppes chiffrées](https://pdftoolkit.github.io/fr/tutorials/29-pdf20-enveloppe-chiffree.html),
[espaces de noms](https://pdftoolkit.github.io/fr/tutorials/30-pdf20-structure-espaces-noms.html)
[signatures modernes](https://pdftoolkit.github.io/fr/tutorials/31-pdf20-signatures-modernes.html),
[horodatages et DSS](https://pdftoolkit.github.io/fr/tutorials/32-pdf20-horodatages-dss.html)
[mesures géospatiales](https://pdftoolkit.github.io/fr/tutorials/33-pdf20-geospatial.html)
et [destinations de structure](https://pdftoolkit.github.io/fr/tutorials/34-pdf20-destinations-structure.html),
avec leurs sources exécutables et aperçus des vrais PDF.

Le rapport `WtPdfAudit` distingue erreurs détectées, validations complémentaires
et appréciation humaine du sens du contenu. Une déclaration de métadonnées ne
prouve pas sa propre véracité. Le catalogue de spécification, les mécanismes
natifs et la conformité complète d’un document restent distincts ; la
[couverture](https://pdftoolkit.github.io/fr/guides/couverture.html) précise leur
périmètre réel. Le chiffrement révision 5 est interdit en PDF 2.0.
L’inventaire de base propre à l’édition contient 440 tableaux, 306 définitions de
dictionnaire et 1 709 objets d’entrée. Cet inventaire complet n’établit pas toutes
les exigences sémantiques ou conditionnelles de la norme.

## Contraintes exécutables et caviardage

La version 0.8 ajoute 197 règles intrinsèques exécutées par les dictionnaires
de spécification, les corrections EC3 #246/#403/#444/#468 et un registre des
65 corrections Adobe. Les sources, dispositions, preuves et limites sont
consultables dans les [références Adobe](resources/adobe-references/README.fr.md)
et les [règles sémantiques](resources/iso32000-2-2020/README.fr.md).

Le [caviardage destructif](resources/redaction/README.fr.md) retire les instructions
et ressources concernées d’un graphe natif normalisé, puis écrit un fichier neuf.
Les cas opaques non normalisés sont refusés ; les régions ne sont pas simplement
recouvertes. Un backend XML WTPDF explicite vérifie aussi l’équivalence des valeurs
texte et rich text. Aucun de ces ajouts ne constitue une certification générale
de conformité ou d’accessibilité.

`ChoiceField` accepte maintenant `ChoiceIndexSemantics` avant les entrées
variadiques. Le comportement historique reste `Pdf17` ; `Pdf20Ec3` sélectionne
les indices corrigés PDF 2.0. Les appels avec des `Entry` positionnelles doivent
passer explicitement cet argument avant celles-ci. `AnnotationAppearanceTransform`
exécute l’algorithme corrigé de placement des apparences `Matrix × A`.

## Validation contextuelle, apparences de codes-barres et polices utilisées

La version 0.9 vérifie l’appartenance des annotations et leurs relations de
marquage, les cibles des actions distantes et embarquées, et les tableaux de
mesures rectilignes et de formats numériques dans leur contexte réel. Les
[contrôles de graphe](resources/graph-semantics/coverage.json) consignent les
sources et contre-exemples. Ils n’exécutent aucune action et n’ouvrent aucun
fichier externe.

La [préparation des codes-barres](resources/barcodes/README.fr.md) conserve les
octets réels du champ, compresse éventuellement la valeur entière avant de la
répartir entre les widgets et construit leurs apparences normales complètes par
une capacité explicite d’encodage d’image. La symbologie, la capacité et le
comportement du scanner appartiennent à l’encodeur externe choisi.

Les contrôles WTPDF suivent seulement les programmes de glyphes Type3 natifs
réellement sélectionnés, avec leurs ressources locales et des budgets cumulés.
Les glyphes inutilisés ne créent pas de fausse erreur d’incorporation. ISO
32000-2 exclut le mode de rendu 7 du rendu des glyphes Type3 ; les versions
retenues de Poppler/PDFium se comportent différemment. L’oracle indépendant
consigne donc cette limite sans revendiquer de confirmation visuelle de ce mode.
Les fonctions PDF 2.0 dépréciées sont inspectées dans leurs domaines de graphe
normalisés. `WtPdfFindingStatus::Recommendation` et `recommendations()` séparent
les recommandations SHOULD WTPDF des erreurs et des validations humaines ou
externes en attente. Fournir explicitement `WtPdfDeprecatedFileContext` pour le
contexte du trailer et du chiffrement : il s’agit d’éléments fournis par l’appelant,
pas d’une provenance authentifiée du fichier source.

`PdfMacTokenVerifier` vérifie les jetons avec une clé de fichier et un sel KDF
explicites, sans dépendre d’une source aléatoire. Les identifiants SHA-2 acceptent
des paramètres absents ou NULL équivalents ; ceux de SHA-3 restent absents.
`Revision5Permissions` peut représenter explicitement l’exigence de MAC
historique. L’authentification des fichiers externes appartient au lecteur
optionnel, pas à un parseur du core.

La version **0.9.1** contrôle les apparences des annotations PDF 2.0 dans le graphe
effectif des pages, y compris lors d’une mise à niveau du catalogue figé. Seuls
les rectangles réduits à un point (deux dimensions nulles), Popup, Projection et
Link sont exemptés d’AP ; PDF 1.7 conserve son caractère facultatif. AP.N, les
streams Form et les noms AS requis sont vérifiés, en préservant le repli prévu
par la norme lorsqu’un état est inconnu. Le [guide des apparences](resources/annotation-appearances/README.fr.md)
consigne EC3 #22/#124 : six chaînes exactes sont désormais vérifiées, distinctes
des 197 règles intrinsèques. Aucune apparence n’est générée implicitement.

## Révisions incrémentales protégées

La version candidate 0.10.0, non publiée, étend le garde natif commun au
remplissage P2/P3 borné des champs existants de texte, choix, case à cocher et
bouton radio, et à l’édition P3 des annotations de marquage listées. Il compare
les révisions figées complètes et conserve DocMDP, FieldMDP, verrous Adobe,
héritage des champs et ressources partagées. Les apparences à texte variable
conservent les octets hors d’une région `/Tx` isolée ; les boutons gardent leurs
programmes d’apparence existants. Signature terminale et maintenance DSS/horodatage
documentaire PDF 2.0 conservent leurs preuves restreintes séparées.

Les historiques UR3 natifs non chiffrés exigent une `UsageRightsAuthorization`
explicite par mise à jour : l’autorité applicative doit reconnaître l’identité
signataire vérifiée, et chaque opération doit respecter droits signés, sélection,
conditions de sauvegarde et toutes les autres restrictions historiques. La
validité mathématique seule n’accorde ni droits d’usage ni confiance PKI. UR ancien,
UR chiffré et PDF 2.0 `P=false`/absent restent hors périmètre, comme les modèles,
le texte enrichi, les nouvelles ressources, la classification de chaînes externes
et les mises à jour mêlant remplissage, signature ou maintenance.
Voir les [opérations prises en charge, migration et preuves indépendantes](resources/signature-revisions/README.fr.md).

Conserver la `FileRevision` originale renvoyée par l’écriture ou la signature
native dans l’exécution courante. Une révision reconstruite manuellement ou
clonée n’a pas cette provenance, et les preuves natives ne peuvent pas être
restaurées par sérialisation PHP. `PreparedSignature::finalize()` peut incorporer
des octets externes, mais ces octets opaques ne constituent pas une autorité
vérifiée pour une révision protégée ultérieure. Poursuivre cet historique exige
une vérification réelle de la signature liée aux octets finaux, au dictionnaire,
aux plages signées et au certificat du signataire. Elle prouve l’intégrité
mathématique, pas la confiance envers le certificat ni une classification
générale des modifications de PDF externes.

Le caviardage vérifie aussi les entrées de police conservées selon leur domaine
réel : police simple, composite ou CID. Des entrées CID superflues sur une police
simple sont refusées au lieu d’être recopiées dans la sortie neuve. Les programmes
incorporés opaques nécessitent toujours un assainisseur explicite ; voir les
[limites du caviardage](resources/redaction/README.fr.md).

## Installation

Le core **0.9** nécessite PHP 8.3 ou supérieur, en 64 bits et les extensions `ctype`, `iconv` et `zlib`. Les backends AES et CMS sont optionnels et nécessitent `openssl`. Le fournisseur Ed25519/Ed448 reçoit aussi un exécutable OpenSSL local explicite. Le package [roukmoute/pdf](https://packagist.org/packages/roukmoute/pdf) est disponible sur Packagist :

```sh
composer require 'roukmoute/pdf:^0.9.0'
```

Les modifications d'API figurent dans le [changelog](CHANGELOG.fr.md) et le
[guide de migration 0.5](https://pdftoolkit.github.io/fr/guides/migration-0-5.html) ;
le [guide PDF 2.0](https://pdftoolkit.github.io/fr/guides/pdf-2-0.html) décrit
les fondations ; le guide du bundle couvre les ajouts 0.7. Pour suivre les développements
après cette série, remplacer la contrainte stable par `dev-master`.

## Modules optionnels

| Package | Responsabilité |
| --- | --- |
| [`pdftoolkit/pdf-reader`](https://packagist.org/packages/pdftoolkit/pdf-reader) | Lire les objets et révisions d'un PDF externe, vérifier ses pages et leur héritage, inspecter leurs flux. |
| [`pdftoolkit/pdf-images`](https://packagist.org/packages/pdftoolkit/pdf-images) | Inspecter des fichiers JPEG et décoder des fichiers PNG en objets image du core. |
| [`pdftoolkit/pdf-fonts`](https://packagist.org/packages/pdftoolkit/pdf-fonts) | Analyser un TrueType statique et préparer ses métriques et correspondances pour les objets de police du core. |

Les convertisseurs images et polices sont disponibles en **0.1.10**, le lecteur en **0.3.2** ; ces versions acceptent le core 0.9 et PHP 8.3 ou supérieur. Composer installe automatiquement le core requis par le module choisi. Par exemple, pour importer des fichiers image :

```sh
composer require 'pdftoolkit/pdf-images:^0.1.10'
```

Le module installe le core avec sa contrainte stable. Le [guide des packages](https://pdftoolkit.github.io/fr/guides/modules.html) explique leur frontière, les installations et le fonctionnement du lecteur. Conserver le `composer.lock` de l'application pour fixer les versions et les commits utilisés.

## Développement et vérifications

Depuis un clone du dépôt :

```sh
composer install
composer check
php examples/hello-world.php hello-world.pdf
php examples/object-stream.php object-stream.pdf
```

Le code de production dépend uniquement de PHP et de ces trois extensions. PHPUnit 12, PHPStan, PHP-CS-Fixer et les modules utilisés par les exemples sont des dépendances de développement verrouillées dans `composer.lock`. Leur présence dans ce checkout n'ajoute aucune dépendance de production aux applications utilisant le core. La CI exécute les tests et valide les PDF d'exemple sous PHP 8.3, 8.4 et 8.5. Les tests échouent aussi sur les dépréciations et avertissements PHP. Le [changelog](CHANGELOG.fr.md) décrit chaque version publiée.

Pour valider ce checkout, y compris depuis son tag, définir
`COMPOSER_ROOT_VERSION=0.9.x-dev` avant `composer install`. Cette variable identifie
le core courant comme appartenant à la série 0.9 attendue par les modules. Les
dépendances de développement utilisent les modules stables 0.1, dont les versions
et les sources exactes sont verrouillées dans `composer.lock`. Les CI définissent
également cette variable pour les branches, les tags et les pull requests.

## Construction objet par objet

```php
$helvetica = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(
    new Font(new DictionaryType(
        new Entry(new NameType('F1'), new IndirectReference($helvetica)),
    )),
);
$contents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
    new MoveTextPosition(new NumericType(72), new NumericType(720)),
    new ShowText(new LiteralString('Super !')),
    new EndTextObject(),
));
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog));
$file->writeTo('hello-world.pdf');
```

L'exemple complet, avec les imports et les métadonnées, se trouve dans [`examples/hello-world.php`](examples/hello-world.php).

## Tutoriels et recettes

Le [parcours de tutoriels](https://pdftoolkit.github.io/fr/tutorials/index.html) accompagne chaque script d'une explication en français, d'un aperçu et de variantes à essayer. Les commentaires du code relient les objets aux instructions et structures du PDF.

| Sujet | Ce que l'exemple explique |
| --- | --- |
| [Premier document](https://pdftoolkit.github.io/fr/tutorials/01-premier-document.html) | Police, ressources, contenu, page, catalogue et informations documentaires. |
| [Pages et texte](https://pdftoolkit.github.io/fr/tutorials/02-pages-et-texte.html) | Héritage des ressources, plusieurs pages, positions et interligne. |
| [Dessin](https://pdftoolkit.github.io/fr/tutorials/03-dessin.html) | Couleurs, chemins, courbes et transformations de l'état graphique. |
| [Navigation](https://pdftoolkit.github.io/fr/tutorials/04-navigation.html) | Signets hiérarchiques et destinations vers les pages. |
| [Liens](https://pdftoolkit.github.io/fr/tutorials/05-liens.html) | Annotations cliquables internes et actions URI. |
| [Images](https://pdftoolkit.github.io/fr/tutorials/06-images.html) | Image XObject, image inline, octets RGB et filtres. |
| [Dégradé](https://pdftoolkit.github.io/fr/tutorials/07-degrade.html) | Fonction PDF de type 2 et shading axial. |
| [Objets compressés](https://pdftoolkit.github.io/fr/tutorials/08-objets-compresses.html) | Flux de contenu, conteneur d'objets et références croisées. |
| [Police TrueType et Unicode](https://pdftoolkit.github.io/fr/tutorials/09-police-incorporee.html) | Programme incorporé, CID, largeurs et correspondance ToUnicode. |
| [Images JPEG et PNG](https://pdftoolkit.github.io/fr/tutorials/10-images-jpeg-png.html) | Couleurs, décodage PNG, JPEG conservé et masque de transparence. |
| [Révisions incrémentales](https://pdftoolkit.github.io/fr/tutorials/11-revisions-incrementales.html) | Remplacements explicites, octets historiques conservés et chaînage des xref. |

La [recette de tableau](https://pdftoolkit.github.io/fr/recipes/table.html) assemble ces notions dans une présentation concrète. La [collection de recettes](https://pdftoolkit.github.io/fr/recipes/index.html) explique aussi comment proposer de nouveaux exemples.

## Identité et responsabilités

- Les valeurs PDF sont des objets. Les chaînes représentent des octets ; `TextString` réalise explicitement l'encodage du texte Unicode.
- Une `IndirectReference` vise une instance. Un `SerializationContext` attribue les identifiants pendant l'écriture d'un seul fichier. Il n'existe aucun singleton ni compteur global.
- `FileStructure` décrit le fichier ; `FileWriter` l'écrit. Chaque écriture crée un contexte neuf et produit les offsets et longueurs à partir des octets réellement émis.
- Les dictionnaires utilisent des objets `Entry`. `with()` et `without()` construisent des copies et préservent les contraintes des modèles spécialisés.
- Les arbres de pages et les plans utilisent des constructeurs de graphe mutables, avec un seul parent et des références explicites. Leur structure calcule les liens et compteurs ; les objets de définition de la norme sont immuables.
- Les filtres s'appliquent dans l'ordre inverse de leur ordre de décodage. Les flux sont toujours référencés comme objets indirects.

Voir [le guide d'architecture](https://pdftoolkit.github.io/fr/guides/architecture.html) pour les choix de construction et [la couverture de la norme](https://pdftoolkit.github.io/fr/guides/couverture.html) pour la traçabilité et les limites.

Le [guide des composants](https://pdftoolkit.github.io/fr/guides/composants.html) explique chaque famille d'objets. La [référence API](https://pdftoolkit.github.io/fr/reference/index.html) donne les signatures et les liens vers le code et la norme. Les [instructions de construction du site](https://github.com/PDFToolkit/pdftoolkit.github.io/blob/main/docs/site/README.md) décrivent la génération et les vérifications locales dans le dépôt de documentation.

## Couverture et limites

Le catalogue recense les **392 tableaux**, **823 sections indexées** et **73 tokens d'opérateur** de la référence. Les tableaux de dictionnaires donnent lieu à des classes nommées pour leurs dictionnaires, leurs entrées et leurs définitions immuables. Les noms PDF conservent exactement leur casse, qui est significative dans le format.

Les implémentations exécutables couvrent les objets fondamentaux, l'écriture des fichiers, les tables et flux de références croisées, les objets compressés, les opérateurs de contenu, les arbres de pages/noms/nombres, les destinations et plans, les polices standard et les quatre représentations de fonction PDF.

**La présence d'une définition n'est pas une déclaration de conformité complète.** Le catalogue valide les types, références exigées, exigences inconditionnelles et certaines formes de valeurs. Les conditions, relations entre dictionnaires et règles de version qui ne disposent pas encore d'une validation spécialisée restent dans leurs définitions et dans le document de couverture.

Le core compose les objets de polices, les CMaps, les images et leurs masques, les formulaires, la structure balisée et les révisions incrémentales. Il écrit aussi les filtres, le chiffrement explicitement choisi, les signatures par plages d'octets, les références hybrides et la linéarisation. Les modules optionnels fournissent l'analyse TrueType, la conversion JPEG/PNG et la lecture de PDF externes. Les mécanismes vérifiés et les règles restantes figurent dans [l'inventaire de couverture](https://pdftoolkit.github.io/fr/guides/mecanismes.html). La fabrication de sous-ensembles de programmes de police, les scripts complexes et l'import avec remappage d'un graphe externe demandent des composants dédiés. `EncodedData` permet de déclarer explicitement des octets déjà encodés. Les obligations de sécurité non prises en charge sont refusées.

Le writer assemble actuellement le fichier en mémoire. Le lecteur est fourni par `pdftoolkit/pdf-reader`. Un moteur de rendu et une API de mise en page ne font pas partie du core.

## Sécurité

La [politique de sécurité](SECURITY.fr.md) décrit les signalements privés et les frontières de confiance. La [revue de sécurité](https://pdftoolkit.github.io/fr/guides/securite.html) explique les protections de sérialisation et les responsabilités de l'application pour les chemins, les actions PDF et les limites de ressources.

## Génération des définitions

Les ressources structurées et les classes générées sont versionnées. La génération est reproductible à partir de ces ressources ; elle n'a pas besoin de relire le PDF à l'exécution de la bibliothèque.

```sh
python tools/generate_specification.py
```

L'extracteur `tools/extract_spec.py` conserve la source et les localisations dans le PDF. Son utilisation demande les dépendances Python indiquées dans la documentation de la spécification. Les descriptions normatives servent à tracer les contraintes encore à implémenter ; les algorithmes ne sont pas remplacés par une génération de classes.
