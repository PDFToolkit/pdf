# Parties documentaires et migration EC3

[English](README.md) | Français

Le graphe natif suit les corrections ISO 32000-2 EC3 #86, #290, #609 et #612 dans
`ISO_32000-2_sponsored_EC3.pdf` conservé, pages physiques 850–853, SHA-256
`90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230`.
Les identités exactes des annotations et les chaînes d’approbation figurent dans
[le registre sémantique](../iso32000-2-2020/ec3-semantic-reviews.json).
#612 et #609 sont des corrections **Accepted** par la PDF Association ; #86 et
#290 sont **Completed** par ISO. Le PDF source reste inchangé.

`DocumentPart::addChildren($first, ...$others)` remplace `addGroup()`. Les références
enfants forment un tableau `/DParts` ordonné, non vide et **plat**. Pour exprimer un
groupe hiérarchique, construire un `DocumentPart` parent et lui rattacher ses
enfants explicitement. Des appels successifs ajoutent des frères dans l’ordre.
Le builder conserve un parent par enfant, refuse les cycles et les doublons, et
ne rattache aucun enfant d’un lot refusé. Une feuille de pages ne peut recevoir
d’enfants.

`Metadata` est interdit sur `DocumentPart`, y compris dans les remplacements
incrémentaux effectifs. Garder les données métier dans `DocumentPartMetadata`
(`/DPM`). Pour décrire la hiérarchie en XMP, transmettre un `MetadataEntry` explicite
au constructeur `DocumentPartRoot` après la racine et le niveau d’enregistrement
facultatif, avec les `NameType` des niveaux. Le paramètre variadique s’appelle
maintenant `entries` ; les appels utilisant le nom `nodeNames:` doivent employer
`entries:`. Les noms transmis par position gardent leur sens. L’exemple exécutable
[`pdf20-metadata-declarations.php`](../../examples/pdf20-metadata-declarations.php)
illustre les portées distinctes du catalogue, de DPartRoot, de DPM et du contenu
marqué.

DPM accepte aussi les valeurs PDF `NameType`, éventuellement imbriquées ou
indirectes, dont les octets décodés forment du UTF-8 valide. `NameType` réalise
l’échappement `#HH` ; fournir les octets du nom décodé. Les clés de dictionnaire
restent soumises aux règles XML Name. Les valeurs nulles, flux et noms UTF-8
invalides restent refusés.

Le schéma généré du tableau409 contrôle la liste plate des enfants et interdit
`Metadata`. La définition historique `MetadataEntry` reste dans l’inventaire de
la source de base pour conserver sa provenance ; elle n’autorise pas son émission.
Le graphe natif contrôle aussi les liens parent/page réciproques, l’ordre des
plages, l’unicité, les limites de profondeur/nombre de nœuds, les niveaux
d’enregistrement et les noms par niveau.

`tools/validate_pdf20_metadata.py` vérifie indépendamment les PDF produits avec
pypdf, un parseur XML et qpdf. Ses fixtures à trois pages couvrent la hiérarchie
corrigée avec tables, flux et références croisées hybrides. PHPUnit refuse aussi
les remplacements incrémentaux réintroduisant des tableaux d’enfants imbriqués,
du XMP sur DPart ou des noms DPM invalides.
