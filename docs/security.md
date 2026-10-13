# Revue de sécurité du 13 octobre 2026

Cette revue documente les contrôles de sécurité effectués sur le générateur PHP et ses dépendances verrouillées : revue de code, tests de régression, audit des dépendances et validation des PDF par des lecteurs indépendants. Elle couvre la sérialisation des valeurs PDF, les images inline, les ressources binaires, les graphes, les limites numériques et l'écriture des fichiers. Les résultats ci-dessous correspondent aux surfaces et scénarios vérifiés ; ils ne constituent pas une garantie d'absence de toute vulnérabilité.

Les contrôles des convertisseurs TrueType et PNG/JPEG sont maintenant maintenus
dans les modules optionnels [pdf-fonts](https://github.com/PDFToolkit/pdf-fonts)
et [pdf-images](https://github.com/PDFToolkit/pdf-images). Les objets PDF et leurs
invariants restent dans le core. Le module
[pdf-reader](https://github.com/PDFToolkit/pdf-reader) a sa propre
[documentation de sécurité](https://github.com/PDFToolkit/pdf-reader/blob/master/docs/security.md)
pour la lecture de fichiers externes ; cette revue du générateur ne s'y substitue pas.

## Résultats et protections

| Surface | Résultat de la revue |
| --- | --- |
| Noms, chaînes, nombres, header | Échappement des séparateurs et des octets, chaînes littérales préservant les données, nombres finis et version de header limitée par enum. Les charges essayant de fermer une chaîne ou d'introduire d'autres objets restent des données. |
| Flux PDF | `/Length` calculée sur les octets encodés. Les marqueurs `endstream` présents dans les données ne modifient pas cette longueur. Les entrées externes `/F`, `/FFilter` et `/FDecodeParms` sont refusées par `StreamObject`. |
| Images inline | Correction d'une ambiguïté `EI` : un lecteur tolérant peut reconnaître le terminateur sans blanc avant lui. Les octets pourraient alors être lus comme opérateurs de contenu. Ces séquences sont désormais refusées ; un XObject image fournit une représentation séparée avec longueur explicite. |
| Dimensions d'image | Calcul entier contrôlé des octets par ligne et du total. Un débordement est refusé au lieu de passer par un flottant puis une longueur incorrecte. |
| Import PNG/JPEG — module pdf-images | Octets fournis explicitement, sans chargement de chemin/URL. Limites sur les octets, pixels, dimensions et segments ; PNG CRC/ordre/filtres/zlib borné ; JPEG marqueurs, tables et couleurs inspectés. Les coefficients JPEG sont décodés par le lecteur PDF. |
| Programme TrueType — module pdf-fonts | Validation des données binaires fournies explicitement : tables, offsets, métriques, cmap et glyphes contrôlés ; cycles et profondeur des glyphes composés bornés. CFF, fontes variables et collections non prises en charge sont refusées. |
| Opérandes partagés | La validation visite chaque identité une seule fois, avec un ensemble distinct pour les ancêtres actifs. Elle conserve le refus des cycles sans revisiter exponentiellement un sous-graphe partagé. |
| Références croisées | Les trous initiaux reçoivent des enregistrements libres conformément à la norme. La capacité cumulée est bornée avant leur allocation par `CrossReferenceLimits`. Les sections incrémentales restent partielles ; les additions pour `/Size` et le numéro du flux xref sont contrôlées. |
| Révisions | Réservations des anciens numéros, conservation des générations et des alias ; remplacement de conteneurs structuraux refusé même à travers un nouveau flux d'objets. Les métadonnées du catalogue et de l'arbre de pages sont contrôlées sur les valeurs effectives. Les anciens octets demeurent dans le fichier : une update ne permet pas d'effacer un secret. |
| Sortie fichier | Validation du chemin avant sérialisation, refus des URI de streams, exception d'écriture sans divulgation du chemin. Une erreur de validation du graphe intervient avant l'ouverture de la destination existante. |
| Dépendances | Aucun avis connu ni paquet abandonné signalé par `composer audit --locked` lors de la revue. Ce contrôle est exécuté à chaque CI. |
| Chiffrement historique Adobe R=5 | Vecteurs AES officiels, fixtures qpdf et déchiffrement indépendant des chaînes et flux, avec mots de passe utilisateur/propriétaire et rejets des mauvais mots de passe. Vérification des longueurs après filtres et chiffrement, de l'absence de double chiffrement et des exceptions structurelles. |
| Préparation des mots de passe | SASLprep/NFKC Unicode 3.2 figé, comparé à un oracle indépendant : mappings, caractères interdits et non assignés, règles bidirectionnelles et troncation après normalisation. |
| Chiffrement ISO R=2/3/4 | Algorithmes 1–7, credentials utilisateur/propriétaire, RC4 et AESV2, clés de 40 à 128 bits, filtres implicites et explicites et révisions incrémentales. Les filtres Crypt explicites utilisent leur clé directement selon 7.4.10 ; les flux implicites utilisent la dérivation par objet. |
| Chiffrement par certificats | Messages CMS, regroupement des destinataires, formats s3/s4/s5 et clés distinctes pour le document et les pièces jointes. Vérification indépendante des données CMS, des clés et du déchiffrement. L'ordre des octets des permissions est un choix explicite. |
| Frontière du writer chiffré | Chaînes directes du trailer hors ID refusées ; dictionnaire Encrypt et xref conservés en clair. Seule la métadonnée du catalogue peut être exemptée ; son remplacement suit la liaison effective de la révision. Une nouvelle identité PHP ne permet pas de remplacer Encrypt dans un flux d'objets. |
| Signatures | ByteRange fixé avant signature, réservation Contents à capacité explicite, contrôle des délimiteurs et remplissage sans déplacement des offsets. Les trois formats ISO sont contrôlés par une vérification indépendante des condensats, des messages CMS et des signatures RSA. |

Le rejet explicite des stream URIs est un durcissement du contrat de `writeTo()`, pas la correction d'une exécution PHP démontrée : `LOCK_EX` bloquait déjà les wrappers usuels sur le PHP 8.5 vérifié. Une destination native peut encore suivre un lien symbolique ou désigner un partage réseau selon le système. L'application doit contrôler son emplacement et ses permissions.

## Utiliser les données au bon endroit

`ShowText(new LiteralString($text))` échappe la syntaxe de la chaîne. `NameType($name)` échappe celle du nom. En revanche, `StreamObject($rawContent)` écrit les octets fournis : s'ils sont utilisés comme `/Contents`, ils constituent du code de dessin PDF. Utiliser `ContentStreams` et ses opérateurs typés pour composer un contenu à partir de texte extérieur.

Un PDF peut représenter une action JavaScript, une action de lancement ou une URI. La bibliothèque ne les exécute pas côté PHP et conserve ces définitions parce qu'elles appartiennent au format. Un lecteur peut ensuite les interpréter. Une application recevant des choix d'utilisateurs doit autoriser les types d'actions et les destinations, plutôt que d'accepter librement un graphe ou des entrées arbitraires.

`EncodedData` transmet des octets déjà encodés. Il n'analyse ni ne décompresse un fichier PNG, JPEG ou PDF entrant. Les `PngImageDecoder` et `JpegImageReader` du module `pdftoolkit/pdf-images` effectuent leurs validations dédiées et reçoivent des limites explicites ; ils ne remplacent pas les quotas ni les contrôles d'accès d'un service d'upload. Le `DCTImage` du core accepte une représentation DCT déclarée explicitement sans inspecter ses marqueurs. Les programmes de police sont également des données binaires ; leur analyse appartient à `pdftoolkit/pdf-fonts`. Les moteurs de hinting et le décodage DCT restent exécutés par le lecteur qui ouvre le document.

## Écrire ou servir un document

Le chemin de `writeTo()` doit être choisi par l'application, dans un emplacement maîtrisé. La méthode écrase une destination existante et ne crée pas son répertoire parent. Elle ne constitue ni un bac à sable de chemins, ni une écriture atomique : une erreur matérielle pendant l'écriture peut laisser un fichier incomplet.

Pour une réponse HTTP, l'application peut envoyer les octets de `toPdf()` avec ses propres contrôles d'accès et des en-têtes adaptés :

```php
$bytes = $document->toPdf();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="document.pdf"');
header('X-Content-Type-Options: nosniff');
echo $bytes;
```

Le nom de téléchargement est ici fixe. Des données de requête ne doivent pas être interpolées directement dans les en-têtes ou les chemins. Les exemples du dépôt sont des scripts CLI ; leur premier argument est un chemin choisi par la personne qui lance la commande.

## Disponibilité et couverture

Les contrôles numériques évitent les débordements identifiés ; la validation des opérandes évite les revisites inutiles. Ils ne remplacent pas les quotas du service. Un graphe direct partagé peut représenter une très grande sortie lorsqu'il est développé en syntaxe PDF. Une chaîne de filtres peut aussi augmenter fortement le nombre d'octets. Une limite sur la taille des données d'entrée seule ne borne donc pas toute la sérialisation.

Les tests [SecuritySerializationTest](../tests/SecuritySerializationTest.php), [SecurityLimitsTest](../tests/SecurityLimitsTest.php) et [FileOutputTest](../tests/FileOutputTest.php) couvrent les charges d'injection, les 256 valeurs d'octet, les terminateurs inline, les bornes numériques, les graphes partagés et les sorties. [ImageObjectsTest](../tests/ImageObjectsTest.php), [CrossReferenceSectionTest](../tests/CrossReferenceSectionTest.php) et [IncrementalUpdateTest](../tests/IncrementalUpdateTest.php) couvrent les invariants PDF du core. Les régressions de décodage des images sont dans [ImageImportTest du module](https://github.com/PDFToolkit/pdf-images/blob/master/tests/ImageImportTest.php). Les validations TrueType restent dans [les tests du module de fontes](https://github.com/PDFToolkit/pdf-fonts/tree/master/tests). Des lectures indépendantes avec pypdf ont confirmé les vecteurs binaires et la reproduction de l'ambiguïté inline. qpdf contrôle tous les exemples en CI.

Cette revue n'est pas un audit des lecteurs PDF, de PHP, du serveur HTTP ou d'une future bibliothèque de mise en page. Les fichiers de définition générés expriment les contraintes de la norme ; ils ne forment pas une politique générale d'assainissement des documents.

La revue croisée des nouveaux mécanismes a identifié deux défauts dans les versions
de développement : une exemption de métadonnées attachée à une ancienne identité
et un remplacement de Encrypt par un membre de flux d'objets. Les tests
[EncryptedRevisionInvariantTest](../tests/EncryptedRevisionInvariantTest.php)
vérifient leurs corrections, y compris le contenu XML effectivement écrit.

Les validateurs [ISO](../tools/validate_iso_security.py),
[certificats](../tools/validate_public_key_security.py) et
[signatures](../tools/validate_pdf_signatures.py) sont des dépendances de
développement, avec versions et empreintes verrouillées. Chaque oracle précise
les limites de son lecteur : qpdf 12.4.2 dérive aussi une clé par objet pour un
Crypt explicite ; pyHanko vérifie la règle ISO sans modifier ses algorithmes.
Pour certains anciens RC4, pypdf utilise son propre fournisseur général afin
d'accepter toutes les longueurs entières autorisées.

Une signature mathématique ne prouve pas la confiance dans le signataire.
La politique de certificats, la révocation, l'horodatage et l'interprétation des
modifications autorisées demandent des contrôles distincts. Les clés privées
dans les fixtures sont publiques et exclusivement destinées aux tests.

Le backend natif contrôle les algorithmes et les tailles de clés de la table 257
sur la version PDF effective. Les messages CMS utilisent SHA-256 ; l'API PHP
OpenSSL employée n'offre pas une sélection complète de condensats. L'orchestration
`SignatureFieldSigning` applique les seeds, verrous, certificats et apparences
réellement écrits, puis vérifie mathématiquement le résultat avant de remplir
la réservation. Une exigence de digest ou de preuve indisponible reste refusée.
La signature simple refuse les politiques qui demandent ce contrat dédié.
Une chaîne de certificats déclarée pour une signature PKCS#1 doit commencer par
le certificat effectivement utilisé par ce backend.

Les tests de [vérification des résultats](../tests/SignatureOutputVerificationTest.php),
de [politique de champ](../tests/SignatureFieldPolicySigningTest.php) et de
[permissions historiques](../tests/SignaturePermissionHistoryTest.php) couvrent
les sorties altérées, les certificats divergents, les faits rejoués, les verrous
et les permissions. L'[oracle de politiques](../tools/validate_signature_field_policies.py)
vérifie huit PDF et neuf signatures RSA/DSA sans modifier les lecteurs.
La comparaison générale des modifications autorisées par MDP/UR demeure distincte.

Les tests [AdobeEncryptionTest](../tests/AdobeEncryptionTest.php) et le validateur
[validate_adobe_encryption.py](../tools/validate_adobe_encryption.py) couvrent le
protocole historique R=5. Ce protocole est sélectionné explicitement pour
l'interopérabilité : sa dérivation de mot de passe est ancienne et AES-CBC
n'authentifie pas le contenu. La préparation SASLprep ne renforce pas un mot de
passe faible. Le [guide Adobe](adobe-supplement.md) précise les mécanismes
effectivement disponibles et leurs limites.

[Politique et signalements privés](../SECURITY.md) · [Architecture](architecture.md) · [Couverture de la norme](specification.md)
