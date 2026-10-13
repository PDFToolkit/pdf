# 16. Organiser un PDF pour l'accès progressif

Le [script complet](../../examples/linearized.php) écrit trois pages, deux polices
partagées, des informations documentaires et des signets. La première page et
ses dépendances arrivent au début du fichier.

```sh
php examples/linearized.php linearized.pdf
qpdf --check-linearization linearized.pdf
```

Le graphe reste explicite : ressources, contenus, pages et catalogue sont
construits avant le writer. `LinearizedWriter` organise leurs octets selon
l'annexe F. Une police partagée conserve une seule identité.

Le dictionnaire annonce longueur `/L`, numéro de première page `/O`, plages du
flux d'indices `/H` et position de la section principale `/T`. Ces valeurs
dépendent des octets réellement écrits. Le writer calcule les indices binaires
et les offsets avec des champs de largeur déterminée.

Les valeurs héritées sont rendues disponibles dans les dictionnaires des pages.
Les indices distinguent les ressources de la première page des objets partagés
ensuite. Le plan et les informations documentaires ont aussi leurs plages.

La linéarisation facilite les requêtes HTTP portant sur des plages d'octets ;
elle ne fournit pas de serveur HTTP. Une mise à jour incrémentale invalide les
indices de l'ancien fichier et doit être traitée distinctement.

La CI compare les indices à un oracle binaire et à qpdf. Une page initiale autre
que zéro est permise par l'annexe F ; la limite de qpdf sur cette variante est
signalée et le contrôle passe par l'oracle séparé.

Référence : ISO annexe F, pages physiques 683–702.

[Index](README.md) · [Code complet](../../examples/linearized.php)
