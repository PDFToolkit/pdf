# Révisions natives protégées

[English](README.md) | Français

La version 0.10.0 étend le garde incrémental commun
introduit en 0.8.1. Tous les points d’entrée d’`IncrementalUpdate` et opérations
directes d’`IncrementalWriter` comparent les révisions figées complètes aux
restrictions historiques authentifiées DocMDP, FieldMDP, verrous Adobe et droits
d’usage. Les opérations natives prises en charge sont bornées ; ce mécanisme ne
classe pas les modifications de PDF signés arbitraires.

Les références primaires conservées sont
[PDF32000_2008.pdf](../reference-documents/PDF32000_2008.pdf), pages physiques
440–444, 478–482 et 485 ;
[ISO_32000-2_sponsored_EC3.pdf](../reference-documents/ISO_32000-2_sponsored_EC3.pdf),
pages physiques 545–549, 560, 588–592, 606 et 607–608 ; et
[adobe_supplement_iso32000.pdf](../reference-documents/adobe_supplement_iso32000.pdf),
page physique 43. [coverage.json](coverage.json) conserve leurs identités et
SHA-256 exacts. La vérification mathématique précède l’évaluation des permissions.
L’opération réelle respecte chaque restriction applicable ; les droits d’usage
ne priment jamais sur DocMDP ou un verrou de champ.

## Provenance native et comparaison complète

Conserver la véritable révision renvoyée par `FileStructure::toRevision()`,
`FileWriter::revision()`, `LinearizedWriter::revision()` ou la signature native.
L’autorité d’émission appartient à cette instance exacte de `FileRevision` dans
l’exécution courante. Snapshots fournis au constructeur, clones et désérialisation
PHP ne la recréent pas, même à octets identiques. Supprimer `Perms`, remplacer
`Root`, détacher l’AcroForm ou renommer un champ n’efface aucune restriction.

La finalisation modifie exactement les réservations `Contents` et `ByteRange`.
L’historique vérifié lie les octets finaux, l’identité de signature, son unique
intervalle Contents exclu, le format, le conteneur complété et le certificat
effectif. Un MAC attaché est installé avant la vérification mathématique finale.
`PreparedSignature::signWithPolicy()` et `finalizeWithPolicy()` vérifient la sortie
réelle. Le `sign()` de bas niveau fournit un historique vérifié si le backend
choisi fournit le certificat et un vérificateur de sortie. Le `finalize()` opaque
complète seulement les octets ; il n’autorise aucune mise à jour protégée ultérieure.

Chaque comparaison conserve les données étrangères à l’opération : catalogue,
pages, contenus, trailer, champs et objets. Les références partagées sont
contrôlées par leurs vrais chemins propriétaires ; un alias privé sous un
propriétaire par ailleurs légitime n’en fait pas partie. Les nouveaux objets
appartiennent à l’opération prouvée. Charges orphelines, suppressions d’objets et
réutilisations de numéros libres historiques sont refusées. Les flux d’apparence
et ressources partagés existants restent immuables ; les apparences de
remplacement utilisent des identités neuves et des ressources historiques inchangées.

## Formulaires et annotations pris en charge

- Sous P2/P3, remplir les champs terminaux existants `Tx`, `Ch` et `Btn` hors
  boutons poussoirs. Valeurs et drapeaux hérités effectifs sont examinés.
  Identités, noms, arborescence, valeurs par défaut, options, actions et géométrie
  des widgets restent fixes. ReadOnly et les verrous historiques FieldMDP/Adobe
  restent actifs, y compris les descendants protégés par un champ non terminal.
  Les noms qualifiés doivent être sans ambiguïté ; renommage et changement de
  parent sont refusés.
- Le texte simple respecte `MaxLen` et les contraintes comb. Valeurs de choix et
  `I`/`TI` concordent avec les options fixes et la version PDF. Cases à cocher et
  boutons radio concordent avec `AS` et les apparences Off/actif existantes ; le
  remplissage ne permet pas de remplacer leurs programmes d’apparence.
- Les champs FileSelect stockent le chemin fourni comme texte ; aucun fichier
  n’est ouvert ni téléchargé. Les champs enrichis exigent des chaînes directes
  `V`/`RV` cohérentes et un `RichTextFilling(new WtPdfXmlRichTextParser())`
  explicite par mise à jour. L’interpréteur XML borné vérifie l’équivalence des
  valeurs sans DTD, entités externes ni réseau. Chaque widget concerné exige
  une nouvelle apparence normale complète ; une apparence périmée est refusée.
  ISO 2 §12.7.4.3 impose cette régénération complète : la restriction `/Tx` du
  texte simple ne s’applique pas. Ressources historiques, géométrie et verrous
  restent fixes. L’appelant fournit les instructions graphiques ; l’accord
  XML/valeur ne prouve pas leur équivalence visuelle. L’interpréteur n’est jamais
  activé implicitement.
- Le remplacement d’apparence à texte variable non enrichi conserve la géométrie
  et chaque octet décodé hors de l’unique région `/Tx BMC`…`EMC` correspondant ;
  sans région existante, il l’ajoute à la fin. Les instructions de dessin/état
  possèdent leur isolation `q`/`Q`. Chemins, clipping en attente et objets texte
  ne traversent ni cette région ni les frontières de restauration graphique.
  Le lexer traite chaînes/noms échappés, commentaires et conteneurs. Les flux
  bruts et un unique `FlateDecode` sont pris en charge : par défaut 1 Mio encodé
  et décodé, 100 000 tokens et une imbrication de 64. Tx multiples/imbriqués,
  images inline, opérateurs inconnus/Type3 et prédicteurs sont refusés. La preuve
  porte sur les frontières des régions/états, pas toute la conformité des
  opérandes graphiques, l’équivalence visuelle de la valeur ou l’intention humaine.
  Les noms de ressources conservent leurs liaisons ; les nouvelles liaisons
  sélectionnent uniquement des objets historiques inchangés. Les alternatives
  d’apparence et noms d’états existants sont conservés ; ajouter R/D ou un état
  sans programme historique comparable est refusé.
- P3 permet en plus la création, modification et suppression par détachement
  bornées des annotations `Text`, `FreeText`, `Line`, `Square`, `Circle`,
  `Polygon`, `PolyLine`, `Highlight`, `Underline`, `Squiggly`, `StrikeOut`,
  `Caret`, `Ink` et `Stamp`. Page propriétaire et ordre des annotations restantes
  restent fixes. ReadOnly, Locked et LockedContents interdisent la modification.
  Widgets, actions, nouvelles relations et dépendances arbitraires restent hors
  de ce périmètre. Le détachement conserve les octets de l’objet historique.

Le parcours de signature unique reste disponible sous P2/P3 et les historiques
FieldMDP permissifs : champ non signé existant, réservation inchangée ou nouveau
champ terminal de signature, avec preuve complète et restreinte des changements.
PDF 2.0 permet la maintenance DSS ou l’horodatage documentaire prouvés selon les
politiques historiques applicables. L’exception DocMDP ne desserre ni les verrous
Adobe P1 supplémentaires ni les droits d’usage. Les remplissages/modifications
d’annotations mélangés à une signature, un DSS ou un horodatage sont refusés.

## Autorité explicite des droits d’usage

Les historiques UR3 natifs non chiffrés, avec transformation version `2.2`,
exigent une `UsageRightsAuthorization` à chaque mise à jour.
`UsageRightsAuthority::permits()` reçoit la véritable `VerifiedRevisionSignature` ;
ses `facts` exposent les algorithmes vérifiés et le certificat signataire lié.
L’application décide si cette identité peut accorder des droits d’usage. La
validité mathématique seule n’accorde rien ; le callback ne vérifie pas
implicitement les chemins PKI ou la révocation.

Passer l’autorisation parmi les derniers arguments d’opération d’`IncrementalUpdate`,
par exemple `new UsageRightsAuthorization($authority, UsageRight::FillField)`.
La sélection non vide d’opérations appartient à cette seule mise à jour. Chaque
remplissage, création/modification/suppression d’annotation ou signature respecte
les droits UR3 signés et cette sélection, plus les règles historiques DocMDP/FieldMDP/Adobe.

La sauvegarde est vérifiée séparément par opération réelle : un droit d’annotation
sans rapport ne rend pas un remplissage persistable. En ISO 1.7, `FillIn` non
restrictif exige `FullSave` signé ; droits restrictifs, modifications d’annotations
et signature réelle conservent leurs règles de sauvegarde prévues. La sélection
applicative `FillField` autorise un remplissage persisté si la politique signée
permet sa sauvegarde : elle n’exige pas en plus `FullSave` côté application
lorsque ce droit signé fournit seulement la condition de sauvegarde. PDF 2.0
accepte uniquement `P=true` explicite et restrictif ; sa sémantique différente de
`P=false`/absent n’est pas assimilée à ISO 1.7. Les historiques `UR` anciens, UR
chiffrés, signatures opaques/non vérifiées, refus d’autorité, maintenance non
classifiée et sauvegarde générale sont refusés. UR est déprécié en PDF 2.0.

## Limites restantes et preuves

L’instanciation de modèles de pages, la création/suppression/renommage généraux
de champs, les valeurs texte indirectes et les flux texte, la régénération
arbitraire d’apparences, les nouvelles ressources et la lecture/classification
authentifiée de chaînes externes restent hors de ce périmètre natif. Intégrité
mathématique, politique de modification, confiance du certificat, révocation et
revue humaine des apparences sont distinctes. Le core ne dépend pas du lecteur
PDF externe pour prouver les modifications natives.

[examples/protected-form-fill.php](../../examples/protected-form-fill.php) signe
DocMDP P2, verrouille `Reference` avec FieldMDP puis remplit `Response` avec une
nouvelle apparence Tx isolée, en conservant les octets signés. Depuis un checkout
contenant les fixtures publiques de signature, exécuter
`php examples/protected-form-fill.php output.pdf` avec le backend OpenSSL disponible.
Le certificat public de test n’apporte aucune confiance. qpdf et le rendu vérifient
la sortie. pyHanko classe cet exemple `OTHER` parce que l’AP modifié appartient à
un widget enfant sans `/T` ; ses contrôles CMS/ByteRange restent séparés, sans
revendiquer une approbation DocMDP par pyHanko.

`ProtectedFormFillChangesTest`, `VariableTextAppearanceChangesTest` et
`ProtectedAnnotationChangesTest` couvrent valeurs figées, héritage, widgets,
isolation des apparences, propriétaires et changements cachés.
`AuthenticatedUsageRightsTest` utilise de vraies signatures UR3, une autorité
explicite et des historiques ultérieurs DocMDP P1/P2 ou FieldMDP ; il vérifie les
intersections d’opération et de sauvegarde. `IncrementalSignaturePermissionsTest`
conserve les attaques sur points d’entrée, sorties opaques et historiques fournis.

`tools/validate_incremental_permissions.py` utilise pyHanko 0.37.0 non modifié,
qpdf et Poppler. Le candidat passe sous PHP 8.3/8.4/8.5 : 105 refus natifs et 196
PDF contrôlés indépendamment par runtime ; 32 combinaisons natives de formulaire
et 32 remplissages indépendants ; quatre chaînes d’annotations P3 ; 198 pixels
contrôlés (144 boutons, 48 annotations, six attaque d’apparence partagée). UR3
apporte huit sources signées, trois remplissages autorisés et 11 fichiers CMS
vérifiés indépendamment ; altérer un octet signé est détecté. Ces contrôles
portent sur CMS/structure/rendu, pas 196 approbations de permissions ni une
décision indépendante d’autorité de droits d’usage.

La politique pyHanko inchangée confirme les 16 combinaisons `Tx`/`Ch`, mais renvoie
`docmdp_ok=false`/`OTHER` pour les 32 sorties de boutons remplis (16 natives et 16
indépendantes) et les 12 opérations d’annotations P3. Valeurs, états, CMS et
pixels rendus sont vérifiés séparément. Elle accepte deux changements ReadOnly
refusés par le classificateur natif. Le mode strict ne valide pas les signatures
hybrides : sa matrice utilise Table/Stream, et les tests hybrides natifs restent
distincts. Les rapports conservent ces différences, sans politique modifiée,
récupération réseau ni racine de confiance PKI implicite.

La matrice de texte étendue ajoute 16 remplissages natifs et 16 indépendants.
pyHanko accepte les huit combinaisons FileSelect mais classe `OTHER` les huit
combinaisons enrichies dans les deux implémentations. L’oracle examine le vrai
`RV` du dictionnaire physique : le résumé normalisé des champs pypdf l’omet.
Ces résultats ne revendiquent pas l’équivalence visuelle du texte enrichi.
