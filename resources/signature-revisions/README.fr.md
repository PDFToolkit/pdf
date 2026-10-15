# Révisions natives protégées

[English](README.md) | Français

Le core 0.8.1 ferme le contournement des déclarations historiques DocMDP,
FieldMDP et de droits d’utilisation par l’écriture incrémentale ordinaire. Tous
les points d’entrée d’`IncrementalUpdate` et les opérations directes
d’`IncrementalWriter` appliquent le même garde conservateur. Ce mécanisme couvre
un parcours natif borné, pas la validation générale des modifications de PDF
signés arbitraires.

Les références primaires conservées sont
[PDF32000_2008.pdf](../reference-documents/PDF32000_2008.pdf), pages physiques
478–482, et
[ISO_32000-2_sponsored_EC3.pdf](../reference-documents/ISO_32000-2_sponsored_EC3.pdf),
pages physiques 588–592, 606 et 607–608. Elles demandent de vérifier
mathématiquement la signature avant d’évaluer les modifications ultérieures.
PDF 2.0 exclut les mises à jour nécessaires du DSS et des horodatages documentaires
de l’évaluation DocMDP ; cette exclusion n’accorde aucun droit d’utilisation et
ne desserre pas un verrou Adobe additionnel de champ.

## Contrôles appliqués

- La sortie native du writer porte une capacité d’émission propre à l’objet,
  liée à cette instance exacte de `FileRevision`. Des snapshots fournis au
  constructeur, des clones et la désérialisation PHP ne remplacent pas l’émission.
- La finalisation modifie exactement les réservations `Contents` et `ByteRange`.
  Elle conserve chaque politique et référence gelée de la signature. L’historique
  vérifié lie tous les octets finaux, l’identifiant objet, l’unique intervalle
  Contents exclu, le format, le conteneur réellement complété et le certificat
  du signataire. Un MAC attaché à la signature est installé avant la vérification
  mathématique finale.
- Le garde examine toutes les révisions natives historiques : supprimer `Perms`,
  remplacer `Root`, détacher l’AcroForm ou renommer un champ n’efface aucune
  restriction signée. Le nom `SignedRevision` et le résultat opaque de
  `finalize()` n’apportent aucune autorité mathématique.
- Les historiques P2/P3 et FieldMDP permissifs peuvent recevoir une signature
  native dans un champ non signé existant, une réservation préexistante inchangée
  ou un champ terminal unique ajouté en fin de liste. La comparaison complète
  conserve les autres objets, entrées du catalogue, propriétaires des entrées du
  trailer et paramètres de chiffrement. Les nouvelles dépendances, apparences,
  actions et transformations des champs et signatures restent restreintes.
- PDF 2.0 peut recevoir un horodatage documentaire prouvé ou une mise à jour
  explicite du DSS. La même comparaison complète s’applique sous P1, P2, P3 et
  dans les historiques protégés uniquement par FieldMDP. Les preuves de validation
  suivent un graphe reconnu et borné ; les modifications annexes, charges
  orphelines, faux flux structurels, suppressions d’objets et réutilisations de
  numéros libres historiques sont refusées. Les verrous Adobe P1 restent actifs.

## Migration et limites explicites

Conserver la véritable révision retournée par `FileStructure::toRevision()`,
`FileWriter::revision()`, `LinearizedWriter::revision()` ou le parcours natif de
signature. Un `FileRevision` construit séparément, même avec les mêmes octets,
n’est pas accepté comme entrée incrémentale. Son constructeur reste utilisable
pour représenter des métadonnées explicites ; il ne lit et n’authentifie aucun
historique de PDF externe.

`PreparedSignature::signWithPolicy()` et `finalizeWithPolicy()` vérifient la
sortie réelle avec le vérificateur choisi. Le `sign()` de bas niveau conserve
une preuve d’historique lorsque son backend fournit à la fois le certificat
effectif et un véritable vérificateur de sortie. Le `finalize()` opaque reste
disponible pour compléter les octets, mais n’autorise aucune mise à jour protégée
ultérieure. Les vérificateurs cryptographiques personnalisés sont des capacités
applicatives explicites. La vérification mathématique n’établit ni confiance PKI,
ni statut de révocation, ni identité habilitée à accorder des droits d’utilisation.

Le remplissage général P2, l’instanciation de modèles de pages, l’édition
d’annotations P3, la régénération d’apparences et la lecture ou classification
authentifiée de chaînes de révisions externes restent non implémentés. Le garde
refuse ces modifications protégées non prises en charge, sans supposer qu’un
niveau de permission les rend sûres. Les historiques de droits d’utilisation
ne peuvent être étendus sans identité habilitée et intersection complète des
droits et modifications. Les définitions de construction ISO 1.7 et PDF 2.0
conservent leurs sémantiques distinctes.

## Preuves

[coverage.json](coverage.json) décrit le périmètre exact et les sources
conservées. `IncrementalSignaturePermissionsTest` couvre les points d’entrée
communs, les véritables certifications et approbations, les modifications
cachées, FieldMDP, les sorties opaques et les historiques fournis par l’appelant.
`tools/validate_incremental_permissions.py` compare de vraies signatures natives
avec pyHanko non modifié et produit des mises à jour incrémentales hostiles
indépendantes. La validité mathématique du CMS, la politique de modification et
la confiance du certificat sont distinguées. La politique générale de pyHanko
sert de comparaison indépendante ; ses autorisations ne sont pas toutes celles
de ce garde plus restreint.
