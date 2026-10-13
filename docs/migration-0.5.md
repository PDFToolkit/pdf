# Utiliser le core 0.5

Le core 0.5 conserve PHP 8.3 comme minimum, avec une CI sur PHP 8.3, 8.4 et 8.5.
Les modules 0.1.3 acceptent les séries 0.2, 0.3, 0.4 et 0.5 du core ; ils restent
facultatifs pour une application qui génère uniquement des PDF.

```bash
composer require 'roukmoute/pdf:^0.5'
```

Déclarer le dépôt VCS du core et ceux des modules choisis comme expliqué dans
[le guide des packages](packages.md). Pour développer dans le checkout du core,
utiliser `COMPOSER_ROOT_VERSION=0.5.x-dev` avant `composer install`.

## Révisions et identifiants

`FileRevision::previous` conserve la révision précédente. Les snapshots exposent
les dictionnaires réellement émis et les références remappées. Les flux ont une
preuve `RevisionStreamSnapshot` avec leurs octets encodés avant chiffrement.
Cette preuve ne se sérialise pas comme un nouveau flux ; construire explicitement
un nouvel objet pour fournir de nouvelles données et ses filtres.

`LinearizedWriter::revision()` retourne maintenant une révision exploitable par
`IncrementalWriter`. L'ajout d'une révision conserve le préfixe exact et rend le
fichier résultant non linéarisé, conformément à l'annexe F.

`ReleaseIndirectObject` et `ReuseObjectNumber` décrivent les opérations de liste
libre. Un alias retiré conserve son identifiant d'origine pour écrire une référence
obsolète ; `binding()` reste réservé aux objets vivants. Une valeur réutilisée
reçoit sa nouvelle génération et ne reprend pas les alias de la valeur retirée.
Voir [le tutoriel sur les générations](tutorials/25-object-lifecycle.md).

## Navigation et fichiers incorporés

Le namespace `PDF\Document\Navigation` propose les labels, destinations, articles,
beads, transitions et séquences de présentation. Les contrôles portent sur le
catalogue et les pages effectivement écrits, y compris après remplacement
incrémental. Un ancien objet devenu inaccessible peut rester dans l'historique.

Les namespaces `PDF\Document\Files` et `PDF\Document\Collections` construisent
les spécifications, les flux incorporés, leurs relations et les métadonnées de
collections. Les clés de nommage sont des octets PDF, distincts des chemins
locaux. Le contenu est fourni explicitement ; aucun chemin externe n'est chargé.

Les définitions générées corrigent deux interprétations de source : `CIEntry`
requiert maintenant un `IndirectReference` et l'entrée historique `F` n'impose
plus PDF 1.7. `UF` et les collections conservent leur seuil PDF 1.7. La formulation
imprimée et la justification de la correction restent accessibles.

## Signature d'un champ avec ses politiques

`SignatureFieldSigning` compose un champ, sa réservation et son contrat de
politique. `bindCatalog()` lie explicitement les références du document.
`PreparedSignature::signWithPolicy()` et `finalizeWithPolicy()` offrent le même
contrat à un backend externe ou à un résultat déjà produit. Le vérificateur doit
vérifier cryptographiquement le résultat sur les octets réservés exacts avant
que `Contents` soit rempli.

Les seeds, le verrou, le certificat, les apparences et les données du catalogue
proviennent du snapshot écrit. L'annonce d'une capacité de timestamp ou de
révocation ne remplace pas une preuve vérifiée sur la signature produite.
Le backend OpenSSL natif vérifie aussi les résultats de l'API de signature simple.

La négociation du digest reflète le backend réel : PHP émet actuellement le CMS
en SHA256, alors que le format PKCS#1 natif utilise RSA/SHA1. Un backend externe
peut proposer d'autres choix avec leur vérificateur. L'interprétation DSA/SHA1
de la table 234 est appliquée au seed `DigestMethod` ; un seed ne portant que
des raisons ne restreint pas les possibilités générales de la table 257.

Les contrôles intrinsèques historiques empêchent une fausse première certification,
une élévation de permission Adobe ou la signature d'un champ verrouillé.
La comparaison générale des modifications autorisées par DocMDP, FieldMDP et UR
reste distincte et ne doit pas être déduite de ces seuls contrôles.
Voir [le tutoriel de politique de champ](tutorials/26-signature-field-policy.md).

[Changelog](../CHANGELOG.md) · [Couverture vérifiée](coverage.md)
