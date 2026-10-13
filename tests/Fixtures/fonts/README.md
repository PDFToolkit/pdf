# Fonte de test originale

`PDFFixture-Regular.ttf` est une fonte synthétique originale de ce projet,
redistribuée sous sa licence MIT (`LICENSE.txt`). Les contours sont de simples
rectangles créés par `build_fixture.py`, sans reprise d'une autre fonte.

La fonte comporte six glyphes à 2 048 unités par em. `A` et `Α` (alpha grec)
partagent le même glyphe ; `🙂` permet de tester UTF-16 et les `cmap` format 12.
Les caractères U+0100–U+017F sont aussi des alias synthétiques de `A`, pour
vérifier les blocs `/ToUnicode` de plus de 100 correspondances.
Les deux derniers glyphes partagent leur avance, pour tester la répétition du
dernier enregistrement `hmtx`. Il s'agit d'un fixture, pas d'une fonte destinée
à composer un document.

Pour reproduire le binaire : installer `fontTools==4.62.0` dans un environnement
Python de développement, puis exécuter `python tests/Fixtures/fonts/build_fixture.py`.
Les horodatages sont fixés ; les tests PHP lisent le binaire déjà commité et
ne requièrent ni Python ni fontTools.
