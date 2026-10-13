# Revue de sécurité du 13 octobre 2026

Cette revue documente les contrôles de sécurité effectués sur le générateur PHP et ses dépendances verrouillées : revue de code, tests de régression, audit des dépendances et validation des PDF par des lecteurs indépendants. Elle couvre la sérialisation des valeurs PDF, les images inline, les ressources binaires, les graphes, les limites numériques et l'écriture des fichiers. Les résultats ci-dessous correspondent aux surfaces et scénarios vérifiés ; ils ne constituent pas une garantie d'absence de toute vulnérabilité.

## Résultats et protections

| Surface | Résultat de la revue |
| --- | --- |
| Noms, chaînes, nombres, header | Échappement des séparateurs et des octets, chaînes littérales préservant les données, nombres finis et version de header limitée par enum. Les charges essayant de fermer une chaîne ou d'introduire d'autres objets restent des données. |
| Flux PDF | `/Length` calculée sur les octets encodés. Les marqueurs `endstream` présents dans les données ne modifient pas cette longueur. Les entrées externes `/F`, `/FFilter` et `/FDecodeParms` sont refusées par `StreamObject`. |
| Images inline | Correction d'une ambiguïté `EI` : un lecteur tolérant peut reconnaître le terminateur sans blanc avant lui. Les octets pourraient alors être lus comme opérateurs de contenu. Ces séquences sont désormais refusées ; un XObject image fournit une représentation séparée avec longueur explicite. |
| Dimensions d'image | Calcul entier contrôlé des octets par ligne et du total. Un débordement est refusé au lieu de passer par un flottant puis une longueur incorrecte. |
| Import PNG/JPEG | Octets fournis explicitement, sans chargement de chemin/URL. Limites sur les octets, pixels, dimensions et segments ; PNG CRC/ordre/filtres/zlib borné ; JPEG marqueurs, tables et couleurs inspectés. Les coefficients JPEG sont décodés par le lecteur PDF. |
| Programme TrueType | Validation des données binaires fournies explicitement : tables, offsets, métriques, cmap et glyphes contrôlés ; cycles et profondeur des glyphes composés bornés. CFF, fontes variables et collections non prises en charge sont refusées. |
| Opérandes partagés | La validation visite chaque identité une seule fois, avec un ensemble distinct pour les ancêtres actifs. Elle conserve le refus des cycles sans revisiter exponentiellement un sous-graphe partagé. |
| Références croisées | Les trous initiaux reçoivent des enregistrements libres conformément à la norme. La capacité cumulée est bornée avant leur allocation par `CrossReferenceLimits`. Les sections incrémentales restent partielles ; les additions pour `/Size` et le numéro du flux xref sont contrôlées. |
| Révisions | Réservations des anciens numéros, conservation des générations et des alias ; remplacement de conteneurs structuraux refusé même à travers un nouveau flux d'objets. Les métadonnées du catalogue et de l'arbre de pages sont contrôlées sur les valeurs effectives. Les anciens octets demeurent dans le fichier : une update ne permet pas d'effacer un secret. |
| Sortie fichier | Validation du chemin avant sérialisation, refus des URI de streams, exception d'écriture sans divulgation du chemin. Une erreur de validation du graphe intervient avant l'ouverture de la destination existante. |
| Dépendances | Aucun avis connu ni paquet abandonné signalé par `composer audit --locked` lors de la revue. Ce contrôle est exécuté à chaque CI. |

Le rejet explicite des stream URIs est un durcissement du contrat de `writeTo()`, pas la correction d'une exécution PHP démontrée : `LOCK_EX` bloquait déjà les wrappers usuels sur le PHP 8.5 vérifié. Une destination native peut encore suivre un lien symbolique ou désigner un partage réseau selon le système. L'application doit contrôler son emplacement et ses permissions.

## Utiliser les données au bon endroit

`ShowText(new LiteralString($text))` échappe la syntaxe de la chaîne. `NameType($name)` échappe celle du nom. En revanche, `StreamObject($rawContent)` écrit les octets fournis : s'ils sont utilisés comme `/Contents`, ils constituent du code de dessin PDF. Utiliser `ContentStreams` et ses opérateurs typés pour composer un contenu à partir de texte extérieur.

Un PDF peut représenter une action JavaScript, une action de lancement ou une URI. La bibliothèque ne les exécute pas côté PHP et conserve ces définitions parce qu'elles appartiennent au format. Un lecteur peut ensuite les interpréter. Une application recevant des choix d'utilisateurs doit autoriser les types d'actions et les destinations, plutôt que d'accepter librement un graphe ou des entrées arbitraires.

`EncodedData` transmet des octets déjà encodés. Il n'analyse ni ne décompresse un fichier PNG, JPEG ou PDF entrant. Les nouveaux `PngImageDecoder` et `JpegImageReader` effectuent leurs validations dédiées et reçoivent des limites explicites ; ils ne remplacent pas les quotas ni les contrôles d'accès d'un service d'upload. Les programmes de police sont également des données binaires. Les moteurs de hinting et le décodage DCT restent exécutés par le lecteur qui ouvre le document.

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

Les tests [SecuritySerializationTest](../tests/SecuritySerializationTest.php), [SecurityLimitsTest](../tests/SecurityLimitsTest.php) et [FileOutputTest](../tests/FileOutputTest.php) couvrent les charges d'injection, les 256 valeurs d'octet, les terminateurs inline, les bornes numériques, les graphes partagés et les sorties. [ImageImportTest](../tests/ImageImportTest.php), [CrossReferenceSectionTest](../tests/CrossReferenceSectionTest.php) et [IncrementalUpdateTest](../tests/IncrementalUpdateTest.php) couvrent les nouvelles frontières. Des lectures indépendantes avec pypdf ont confirmé les vecteurs binaires et la reproduction de l'ambiguïté inline. qpdf contrôle tous les exemples en CI.

Cette revue n'est pas un audit des lecteurs PDF, de PHP, du serveur HTTP ou d'une future bibliothèque de mise en page. Les fichiers de définition générés expriment les contraintes de la norme ; ils ne forment pas une politique générale d'assainissement des documents.

[Politique et signalements privés](../SECURITY.md) · [Architecture](architecture.md) · [Couverture de la norme](specification.md)
