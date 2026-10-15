# Migration vers le core 0.11

[English](../migration-0.11.md) | Français

Le guide complet est maintenu dans la [documentation centrale](https://pdftoolkit.github.io/fr/guides/migration-0-11.html).

- Remplacer `DocumentPart::addGroup()` par `addChildren()` et des objets parents explicites pour la hiérarchie. Déplacer le XMP DPart vers `DocumentPartRoot` ; renommer les arguments nommés `nodeNames:` en `entries:`. Voir les [contrats des parties documentaires](../../resources/document-parts/README.fr.md).
- Un `FontDescriptor` Type3 peut omettre `FontName`. La propriété `fontName` devient nullable ; appeler `requiredName()` si le code requiert un nom. Les autres domaines de polices exigent toujours un nom.
- Choisir `ButtonFieldSemantics::Pdf20` pour les apparences radio PDF2 sans `Off` ou les valeurs des boutons poussoirs. Le comportement historique reste celui par défaut. Une valeur texte null est traitée comme absente pour l’héritage, tout en conservant sa valeur physique null.
- Choisir `SignatureSeedSemantics::Pdf20` pour les contraintes de signature PDF2 et fournir un `IntegerType` pour `V`. La sémantique Adobe/PDF1.7 par défaut conserve une capacité réelle. Les entrées natives PDF2 ne demandent plus de déclaration d’extension Adobe. Le writer figé vérifie toujours l’édition PDF réelle.
- La poursuite UR3 chiffrée requiert un `PasswordUsageRightsAuthentication` ou `CertificateUsageRightsAuthentication` explicite dans chaque `UsageRightsAuthorization`, en plus de l’autorité applicative et du périmètre d’opérations. Une clé de fichier fournie par l’appelant n’est pas un identifiant d’authentification. Un nouveau champ de signature exige aussi `UsageRight::AddField`.
- Le remplissage enrichi exige `RichTextFilling` et un analyseur borné explicites. L’instanciation de modèles de pages et les valeurs texte indirectes restent soumises aux preuves de propriétaires figés et d’objets historiques. Voir les [contrats et limites des révisions protégées](../../resources/signature-revisions/README.fr.md).

Conserver la `FileRevision` native d’origine retournée par le writer. L’inspection des révisions du Reader ne crée pas d’autorité de permissions pour le core. UR ancien, l’import arbitraire de révisions externes, la modification générale de l’arbre des champs et les nouvelles ressources d’apparence libres restent hors de cette version.
