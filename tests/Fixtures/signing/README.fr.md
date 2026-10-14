# Fixtures de signature publiques

[English](README.md) | Français

Fixtures de test publiques et auto-signées, générées uniquement pour les tests
d'interopérabilité CMS/signature. Les clés privées commitées sont volontairement
publiques et ne doivent jamais servir hors des tests. Aucun identifiant utilisateur
réel ni certificat de production n'est inclus.

Les fixtures RSA1024, RSA4098, DSA1024 et EC-P256 exercent les bornes réelles
de taille de clé et d'algorithme du tableau 257. RSA4098 est volontairement hors
de la limite normalisée 4096 bits de PDF 1.7 ; EC est volontairement hors des
trois formats ISO modélisés. Ce sont des fixtures auto-signées valides du
2026-10-13 au 2036-10-13, pas des ancres de confiance ni identités de production.
