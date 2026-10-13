# Construire la documentation

Le site est publié à [roukmoute.github.io/pdf](https://roukmoute.github.io/pdf/). Il documente la branche `master`. Le tag `v0.1.1` reste la dernière version publiée ; ses différences avec `master` figurent dans les guides et le changelog.

## Sources et génération

Les guides et tutoriels restent dans les fichiers Markdown du dépôt. `tools/export_api.php` découvre les classes, interfaces, enums et traits de `src/` et exporte leurs signatures, propriétés, constantes et liens vers le code. L'exporteur lit les expressions des paramètres par défaut dans le source sans instancier les objets qu'elles décrivent.

`tools/build_site.py` assemble ces données et les métadonnées de `resources/iso32000-1/` pour créer une fiche par composant, les index par famille et la recherche. Les définitions de la norme conservent leurs contraintes, leur tableau et leur page de référence. Une définition de dictionnaire ne remplace pas l'implémentation d'un moteur de chiffrement, de signature ou de police ; voir le guide de couverture.

Chaque script de `examples/` doit avoir une page de tutoriel ou de recette et un PDF. Le site présente les explications, un rendu de toutes les pages, le code complet et le PDF généré. Le PDF Adobe/ISO du dépôt est également accessible depuis les fiches de la norme, conformément à l'autorisation de redistribution du propriétaire du projet.

## Construction locale

PHP 8.3 ou supérieur, Composer, Python 3.13 et Poppler (`pdftoppm`) sont nécessaires. La dépendance Python de construction est verrouillée avec ses empreintes dans `tools/site-requirements.txt`. Le site publié est statique : aucun CDN ni bibliothèque JavaScript tierce n'est chargé.

Depuis la racine du dépôt, dans un shell POSIX :

```sh
composer install --no-dev --no-interaction --prefer-dist
python -m pip install --require-hashes -r tools/site-requirements.txt
mkdir -p _build/pdfs _build/pdf-pages
php tools/export_api.php _build/api.json
for example in examples/*.php; do
    name=$(basename "$example" .php)
    php "$example" "_build/pdfs/$name.pdf"
    pdftoppm -r 96 -png "_build/pdfs/$name.pdf" "_build/pdf-pages/$name"
done
python tools/build_site.py --api _build/api.json --pdf-dir _build/pdfs --pdf-preview-dir _build/pdf-pages --output _site --base-path /pdf --revision "$(git rev-parse HEAD)"
python tools/check_site.py _site
```

Sous PowerShell, la boucle équivalente est :

```powershell
New-Item -ItemType Directory -Force _build/pdfs | Out-Null
New-Item -ItemType Directory -Force _build/pdf-pages | Out-Null
Get-ChildItem examples/*.php | ForEach-Object {
    php $_.FullName "_build/pdfs/$($_.BaseName).pdf"
    pdftoppm -r 96 -png "_build/pdfs/$($_.BaseName).pdf" "_build/pdf-pages/$($_.BaseName)"
}
```

Utiliser un dossier de sortie vide lorsque des pages sont supprimées ou renommées. Le générateur ne supprime pas les fichiers d'un dossier existant ; le vérificateur signale les pages obsolètes. `_build/` et `_site/` sont ignorés par Git.

## Aperçu et vérification

Pour un aperçu local, construire avec `--base-path /` puis lancer :

```sh
python -m http.server 8000 --directory _site
```

Ouvrir [localhost:8000](http://localhost:8000/) pour vérifier la navigation, la recherche, le menu mobile, les aperçus et les téléchargements. Les images de chaque page sont rendues depuis le PDF avec Poppler et se consultent dans tous les navigateurs. Un lecteur PDF natif est également proposé dans une section dépliable ; son fonctionnement dépend du navigateur. Le bouton d'ouverture et le téléchargement restent disponibles sur chaque exemple.

`tools/check_site.py` vérifie l'inventaire des pages, les fichiers liés, les ancres, l'unicité des identifiants, la présence d'un titre principal, l'index de recherche et les couples PDF/source. Les références au code utilisent le commit fourni par `--revision`.

Le workflow qualité vérifie le projet et ses PDF d’exemple sous PHP 8.3, 8.4 et 8.5. Les deux workflows installent aussi le lecteur indépendant verrouillé dans `tools/validation-requirements.txt`. `tools/validate_mechanisms.py` contrôle l'extraction Unicode, les tables du programme de fonte incorporé, les échantillons JPEG/PNG et leur alpha, ainsi que chacune des trois révisions du nouvel exemple. Pour exécuter ces contrôles localement :

```sh
python -m pip install --require-hashes -r tools/validation-requirements.txt
python tools/validate_mechanisms.py _build/pdfs
```

## Publication

Le workflow `.github/workflows/pages.yml` construit le site pour les pull requests et à chaque push sur `master`. Il génère les PDF avec PHP 8.3, les valide avec `qpdf`, rend toutes leurs pages avec Poppler, vérifie le site, puis publie l'artefact sur GitHub Pages uniquement depuis `master`. Les permissions d'écriture Pages et d'identité sont réservées au job de déploiement.

Les fichiers HTML et les PDF générés ne sont pas commités : ils sont produits depuis les sources du commit publié. Pour ajouter un exemple, écrire le script, le tutoriel et son aperçu, puis déclarer sa route dans `tools/build_site.py`. La vérification échoue si un script n'a pas de page correspondante.
