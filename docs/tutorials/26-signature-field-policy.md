# 26. Appliquer les conditions d'un champ de signature

Le [script complet](../../examples/signature-field-policy.php) produit une page,
un champ texte `Reference` et un champ signature `Approval`. La signature CMS
détachée est réellement calculée et vérifiée. Le widget du champ affiche une
apparence explicite, tandis que son verrou FieldMDP sélectionne `Reference`.

```bash
php examples/signature-field-policy.php signature-field-policy.pdf
```

Ce tutoriel utilise l'API introduite en **0.5.0**, PHP 8.3 ou supérieur et
l'extension OpenSSL. Le certificat et la clé sont les fixtures **publiques de
test** du dépôt. Ils reproduisent une signature mathématique ; ils n'identifient
aucune personne de confiance.

## Décrire des obligations, puis choisir comment les satisfaire

`SignatureSeedValue` représente `/SV`, les conditions du champ. `SignatureSeedFlags`
indique lesquelles sont obligatoires. Ici, les quatre bits sélectionnés imposent :

- `/Filter /Adobe.PPKLite`, l'identité du handler choisi ;
- le premier `/SubFilter` pris en charge, ici `/adbe.pkcs7.detached` ;
- `/DigestMethod [/SHA256]` pour la clé RSA réellement utilisée ;
- une `/Reason` appartenant à `/Reasons`, ici `Étudié et approuvé`.

```php
$seed = new SignatureSeedValue(
    new SignatureSeedFlags(
        SignatureSeedFlag::Filter,
        SignatureSeedFlag::SubFilter,
        SignatureSeedFlag::DigestMethod,
        SignatureSeedFlag::Reasons,
    ),
    new Entry(new NameType('Filter'), new NameType('Adobe.PPKLite')),
    new Entry(new NameType('SubFilter'), new ArrayType(new NameType('adbe.pkcs7.detached'))),
    new Entry(new NameType('DigestMethod'), new ArrayType(new NameType('SHA256'))),
    new Entry(new NameType('Reasons'), new ArrayType($reason)),
);
```

`SignatureSigningIntent` fournit les choix de l'application : raison, digest
facultatif, certification, verrou documentaire, apparence et demandes de preuves.
Une raison demandée doit être celle qui figure dans le dictionnaire signé. Les
conditions sont aussi relues dans le fichier préparé : modifier un objet de
construction après son écriture ne change pas les obligations déjà émises.

Un tableau `SubFilter` conserve son ordre : le premier format pris en charge est
choisi, même si la préférence est facultative. Si aucun ne convient et que le bit
est facultatif, un autre format explicitement pris en charge peut être choisi.
Une liste de raisons facultative autorise une autre raison. Avec le bit obligatoire,
l'absence de `Reasons` ou la valeur spéciale `[.]` impose l'omission de `Reason`.

## Construire le champ et son verrou

`SignatureFieldSigning` compose la réservation `/Contents`, le champ `/Sig`,
sa valeur `/V` et les références de transformation nécessaires. Il garde des
objets distincts pour les ressources, apparences, widgets et conditions.

```php
$lock = new SignatureFieldLock(
    SignatureLockAction::Include,
    new ArrayType(new TextString('Reference')),
);
$signing = new SignatureFieldSigning(
    $backend,
    new PartialFieldName(new TextString('Approval')),
    new SignatureCapacity(8192),
    new SignatureSigningIntent(reason: $reason),
    $seed,
    $lock,
);
```

Le verrou sélectionne un autre champ du vrai formulaire. L'opération copie ses
entrées `Action` et `Fields` dans les paramètres **directs** de `FieldMDP`.
`All`, `Include` et `Exclude` conservent leur sens ; sélectionner un nom de groupe
sélectionne également ses descendants.

Le script fournit une `FormAppearanceStream` et une `WidgetAppearance`, puis
attache le widget à `Approval` avec `addWidget()`. La page référence le widget
dans `Annots`, et `bindToPage()` définit son véritable `P`. Le texte visible ne
remplace pas le CMS contenu dans `/V/Contents`.

`SignatureAppearanceSelection` donne un nom applicatif à une apparence fournie.
Le contrat vérifie qu'elle est bien l'apparence normale sélectionnée de chaque
widget du champ, y compris un champ/widget fusionné et les dictionnaires d'états.
Il ne dessine pas une apparence et ne reproduit pas le dialogue d'un lecteur PDF.

L'apparence du champ texte `Reference` suit aussi la procédure de texte variable
du [tutoriel AcroForm](14-acroform-fields.md) : `/Tx BMC ... EMC` encadre le texte
et le clipping, tandis que le cadre reste à l'extérieur. Elle est distincte de
l'apparence statique de signature. Le marquage ne rend pas le champ modifiable
après signature lorsque le lecteur applique son verrou FieldMDP.

## Lier le catalogue, figer les octets et signer

```php
$signing->bindCatalog(new IndirectReference($catalog));
$prepared = (new SignatureWriter())->prepare($file, $signing->signature);
$signed = $signing->sign($prepared);
$signed->writeTo($path);
```

`bindCatalog()` ferme explicitement la référence `FieldMDP.Data`. Elle désigne ce
catalogue et son formulaire ; une référence vers un autre document est refusée.
`prepare()` écrit le PDF et fixe `/ByteRange` avant le calcul cryptographique.
Les octets couverts incluent le formulaire, le seed, le verrou, les ressources
et les apparences ; seule la réservation `/Contents` est exclue.

Le contrat parcourt `AcroForm/Fields/Kids` dans les snapshots réellement émis.
Il résout les champs hérités, leurs noms complets et les relations `Parent`,
distingue les widgets séparés et refuse les cycles ou possessions incohérentes.
Un dictionnaire quelconque nommé `V`, placé hors de cet arbre, ne devient pas
un champ de signature.

Le backend annonce les digests qu'il sait **émettre**. PHP OpenSSL utilise ici
SHA-256 pour CMS ; son API ne sélectionne pas arbitrairement SHA-384 ou SHA-512.
Le vérificateur lit le digest du résultat CMS, vérifie mathématiquement le message
et identifie le certificat réellement sélectionné. Il lie ses faits aux octets
exacts de l'entrée et de la sortie. Un certificat substitué, un CMS modifié ou
des faits réutilisés depuis un autre document sont refusés avant finalisation.

Seule cette vérification permet de remplir la réservation. Si le CMS dépasse
sa capacité, il faut préparer une nouvelle réservation et signer les nouveaux
octets. Les offsets et la longueur du fichier final restent fixes.

## Historique et composants externes

Une certification `DocMDP` doit être le premier champ signé, et le document
en contient au maximum une. Son lien `Perms/DocMDP` est facultatif : la signature
du champ permet la détection ; ce lien ajoute la prévention des modifications.
`certificationPermissions()` fournit cette contribution explicite au catalogue.

Chaque signature ultérieure utilise une nouvelle révision incrémentale. Le core
conserve les snapshots précédents : retirer un ancien champ ou libérer l'objet de
sa signature n'efface pas son ordre historique. Une référence de valeur retirée
se résout en `null` dans la révision courante.

Pour l'opération de signature, un ancien DocMDP ou verrou Adobe `P1` interdit
un nouveau `V`. Un ancien `FieldMDP` interdit la signature d'un champ sélectionné,
y compris ses descendants. Les permissions Adobe restent le minimum historique :
un nouveau `P3` n'augmente pas un ancien `P2`.

Ces contrôles de l'opération ne réalisent pas encore la **comparaison générale de
toutes les modifications** d'une révision : valeurs de tous les champs, retraits
ou déplacements, contenu de pages, ressources partagées, apparences, annotations
et instanciation de modèles de pages. L'analyse complète DocMDP/FieldMDP/UR reste
un mécanisme à poursuivre. Une signature UR mathématiquement valide ne prouve
pas non plus que son signataire est une autorité habilitée à accorder des droits.

`PolicySignatureBackend`, `SignatureOutputVerifier` et `SignatureSigningEvidence`
permettent une intégration externe explicite. `finalizeWithPolicy()` applique le
même contrat à une signature calculée ailleurs. Un horodatage RFC3161 ou des
preuves OCSP/CRL exigent un composant qui les produit et les vérifie dans le vrai
CMS. Leur capacité annoncée ne remplace pas cette preuve ; le backend natif
refuse ces demandes. Les politiques de confiance PKI restent à fournir par
l'application, et aucun accès réseau n'est exécuté implicitement par le core.

## Preuves et références

Le corpus indépendant de politiques inspecte huit PDF et vérifie neuf signatures
mathématiques RSA/DSA avec qpdf, pypdf, ASN.1 et cryptographie indépendante, sur
PHP 8.3, 8.4 et 8.5. Il couvre les conditions réellement écrites, une certification
avec et sans `Perms`, deux révisions signées, `FieldMDP.Data`, un verrou Adobe et
une apparence attachée. Le rendu et le formulaire de ce script sont aussi inspectés.

Sources conservées : [conditions et certificats, pages455–459](../../PDF32000_2008.pdf#page=455),
[transformations et signatures, pages474–487](../../PDF32000_2008.pdf#page=474),
[verrous Adobe et apparences, pages43–45](../../adobe_supplement_iso32000.pdf#page=43).
La clause DSA/SHA1 de `DigestMethod` est appliquée lorsque cette entrée du seed
existe ; un seed vide ou `Reasons` seul ne crée pas une restriction supplémentaire
à la capacité générale DSA/SHA256 de la table257. Cette interprétation est située
dans les deux descriptions, sans être présentée comme un erratum officiel.

[Parcours des tutoriels](README.md) · [Code complet](../../examples/signature-field-policy.php)
