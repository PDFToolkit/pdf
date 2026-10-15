# Emplacement des fichiers incorporés

`EmbeddedFilePlacementValidation` applique le problème EC3 #481 de
`ISO_32000-2_sponsored_EC3.pdf`, pages physiques 153 et 656, au graphe effectif
d’un document PDF 2.0. La source reste inchangée dans `reference-documents` ;
son SHA-256 est `90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230`.

Un flux de fichier incorporé est référencé par les entrées `EF` ou `RF` d’une
spécification de fichier. Une clé privée simplement nommée `EF` ne reçoit pas
ce rôle. Le validateur reconnaît aussi un flux dont le `Type` facultatif est
absent dès qu’une spécification de fichier désigne son rôle. Une deuxième
référence depuis un autre dictionnaire ou tableau est refusée.

Les spécifications de fichier peuvent être associées à tout objet approprié du
document ; l’arbre de noms `EmbeddedFiles` du catalogue n’est pas obligatoire en
général. Chaque annotation `FileAttachment` désigne une spécification de fichier
indirecte contenant un vrai flux incorporé. Les paires nom de fichier/flux de
fichiers liés restent valides.

La validation incrémentale utilise le graphe effectif figé et préserve les octets
antérieurs. Les anciens objets devenus inaccessibles depuis le catalogue ne
représentent plus des pièces jointes actives. La limite par défaut est d’un
million de positions du graphe ; aucun contenu joint n’est ouvert et aucun
fichier distant n’est récupéré. L’interprétation PDF 1.x reste distincte.

Les tests exécutables sont dans `tests/EmbeddedFilePlacementTest.php`. Les oracles
existants de métadonnées, fichiers associés et RichMedia inspectent indépendamment
les propriétaires autorisés et les octets des fichiers incorporés.
