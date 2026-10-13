# 18. Un encodage composé différent d'Identity-H

Le [script complet](../../examples/cmap-font.php) affiche `ABCDEF éΩ fi` avec
un encodage d'un octet dans une CMap incorporée. La police provient du module
optionnel de conversion ; les objets PDF et les CMaps restent dans le core.

```sh
php examples/cmap-font.php cmap-font.pdf
```

La police Type0 relie les codes à des CID par `/Encoding`. Sa descendante relie
les CID aux glyphes et métriques. `/ToUnicode` associe les codes originaux au
texte Unicode pour la recherche et l'extraction. Ces trois liens sont distincts.

La CMap de base définit un codespace, une plage CID et des correspondances
`notdef`. La CMap étendue la référence par `/UseCMap` puis ajoute des codes.
Les bornes du codespace sont contrôlées octet par octet ; un intervalle entier
ordinaire ne représente pas correctement toutes les plages multioctets.

La CMap Unicode montre `bfchar`, une plage `bfrange` séquentielle et une plage
avec tableau de destinations. Le code de `fi` produit deux caractères Unicode :
le texte extrait peut différer du nombre de glyphes. UTF-16BE et les paires de
substituts sont vérifiés explicitement.

Modifier les codes exige des changements cohérents dans le contenu et dans
`ToUnicode`. La CMap ne réécrit pas le programme de police. Le mode vertical
et ses métriques constituent également un choix explicite.

La CI vérifie le texte extrait avec pypdf et Poppler.

Référence : ISO 9.7 et 9.10, pages physiques 268–292 et 299–302.

[Index](README.md) · [Code complet](../../examples/cmap-font.php)
