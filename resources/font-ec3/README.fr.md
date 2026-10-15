# Descripteurs de polices et métadonnées ToUnicode EC3

[English](README.md) | Français

Le document inchangé `ISO_32000-2_sponsored_EC3.pdf`, SHA-256
`90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230`,
page physique 364, problème #5, autorise les métriques et classifications propres
à une classe de glyphes du tableau 120. `CIDFontMetricOverride` accepte
`FontBBox`, `FontFamily`, `FontStretch` et `FontWeight` en plus des métriques
existantes. Les programmes de police et entrées CID du tableau 122 restent
interdits. `CIDFontDescriptors::effective()` applique ces substitutions locales
et conserve les valeurs générales non remplacées. Les poids PDF2 sont des entiers
de 1 à 1000 ; les poids historiques conservent leur règle par pas de 100.

Le problème #462, pages physiques 372 et 1022 (tableau 125a), relie les métadonnées
du flux ToUnicode à son programme CMap. `UnicodeCMap` accepte `systemInfo` et
`mode` optionnels après son argument existant `base`. Les valeurs par défaut
restent Adobe/UCS/0 et l’écriture horizontale. `ToUnicodeCMap` émet Type,
CMapName, CIDSystemInfo et WMode depuis ces mêmes valeurs immuables et conserve
l’héritage UseCMap. La collection ToUnicode n’a pas à correspondre à la
collection Identity-H/Identity-V de la police.

Ces objets construisent les correspondances depuis des codes et séquences
Unicode fournis explicitement. Ils ne téléchargent pas de CMap nommé et
n’exécutent pas de PostScript arbitraire. Les tests natifs couvrent substitutions,
clés interdites, frontières d’édition, métadonnées verticales et héritage.
`tools/validate_cmaps.py` compare les métadonnées aux octets du programme et
vérifie indépendamment le texte extrait avec pypdf et Poppler.
