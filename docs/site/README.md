# Construire la documentation

Le site est publié à [pdftoolkit.github.io/pdf](https://pdftoolkit.github.io/pdf/). Il documente la branche `master`. Le tag `v0.2.1` est la dernière version publiée du core ; les modules optionnels disposent chacun du tag `v0.1.0`. Les différences ultérieures avec `master` figurent dans les guides et le changelog.

## Sources et génération

Les guides et tutoriels restent dans les fichiers Markdown du dépôt. `tools/export_api.php` découvre les classes, interfaces, enums et traits de `src/` et des trois modules optionnels installés pour le développement. Il utilise `Composer\InstalledVersions` pour leurs chemins et `composer.lock` pour leurs commits de source, puis exporte signatures, propriétés, constantes et provenance par package. L'exporteur lit les expressions des paramètres par défaut dans le source sans instancier les objets qu'elles décrivent.

`tools/build_site.py` assemble ces données et les métadonnées de `resources/iso32000-1/` pour créer une fiche par composant, les index par famille et la recherche. Les définitions de la norme conservent leurs contraintes, leur tableau et leur page de référence. Une définition de dictionnaire ne remplace pas l'implémentation d'un moteur de chiffrement, de signature ou de police ; voir le guide de couverture.

Chaque script de `examples/` doit avoir une page de tutoriel ou de recette et un PDF. Le site présente les explications, un rendu de toutes les pages, le code complet et le PDF généré. Le PDF Adobe/ISO du dépôt est également accessible depuis les fiches de la norme, conformément à l'autorisation de redistribution du propriétaire du projet.

Les exemples des modules sont déclarés séparément dans `EXTERNAL_EXAMPLES`
du générateur. Le tutoriel 12 prend le script `examples/pages.php` et la fixture
`tests/Fixtures/page-tree/classic.pdf` dans le module lecteur installé.
Il publie les octets du PDF d'entrée, ses trois aperçus et la sortie console
de la lecture. La provenance du script et de l'entrée utilise le commit du
module fixé dans l'inventaire d'API ; le core conserve ses douze exemples
de génération.

## Construction locale

PHP 8.3 ou supérieur, Composer, Python 3.13 et Poppler (`pdftoppm`) sont nécessaires. La dépendance Python de construction est verrouillée avec ses empreintes dans `tools/site-requirements.txt`. Le site publié est statique : aucun CDN ni bibliothèque JavaScript tierce n'est chargé.

Depuis la racine du dépôt, dans un shell POSIX :

```sh
composer install --no-interaction --prefer-dist
python -m pip install --require-hashes -r tools/site-requirements.txt
mkdir -p _build/pdfs _build/pdf-pages
php tools/export_api.php _build/api.json
for example in examples/*.php; do
    name=$(basename "$example" .php)
    php "$example" "_build/pdfs/$name.pdf"
    pdftoppm -r 96 -png "_build/pdfs/$name.pdf" "_build/pdf-pages/$name"
done
module=vendor/pdftoolkit/pdf-reader
cp "$module/tests/Fixtures/page-tree/classic.pdf" _build/pdfs/reading-pages.pdf
qpdf --check _build/pdfs/reading-pages.pdf
php "$module/examples/pages.php" _build/pdfs/reading-pages.pdf > _build/pdfs/reading-pages.txt
pdftoppm -r 96 -png _build/pdfs/reading-pages.pdf _build/pdf-pages/reading-pages
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
$module = 'vendor/pdftoolkit/pdf-reader'
Copy-Item -LiteralPath "$module/tests/Fixtures/page-tree/classic.pdf" -Destination _build/pdfs/reading-pages.pdf
qpdf --check _build/pdfs/reading-pages.pdf
php "$module/examples/pages.php" _build/pdfs/reading-pages.pdf |
    Set-Content -Encoding utf8 _build/pdfs/reading-pages.txt
pdftoppm -r 96 -png _build/pdfs/reading-pages.pdf _build/pdf-pages/reading-pages
```

Utiliser un dossier de sortie vide lorsque des pages sont supprimées ou renommées. Le générateur ne supprime pas les fichiers d'un dossier existant ; le vérificateur signale les pages obsolètes. `_build/` et `_site/` sont ignorés par Git.

## Aperçu et vérification

Pour un aperçu local, construire avec `--base-path /` puis lancer :

```sh
python -m http.server 8000 --directory _site
```

Ouvrir [localhost:8000](http://localhost:8000/) pour vérifier la navigation, la recherche, le menu mobile, les aperçus et les téléchargements. Les images de chaque page sont rendues depuis le PDF avec Poppler et se consultent dans tous les navigateurs. Un lecteur PDF natif est également proposé dans une section dépliable ; son fonctionnement dépend du navigateur. Le bouton d'ouverture et le téléchargement restent disponibles sur chaque exemple.

`tools/check_site.py` vérifie l'inventaire des pages, les fichiers liés, les ancres, l'unicité des identifiants, la présence d'un titre principal, l'index de recherche, la couverture des packages et les couples PDF/source. Le manifeste `exampleSources` distingue les PDF générés des entrées de lecture, avec empreintes des PDF, sources et sorties console. Le vérificateur contrôle les liens de provenance, l'échappement de la sortie console et les trois pages de l'entrée. Les références au code du core utilisent le commit fourni par `--revision` ; celles des modules utilisent leur propre commit fixé dans `composer.lock`. Les modules ne sont installés que pour les exemples et la documentation, sans dépendance de production du core.

Le workflow qualité vérifie le projet et ses PDF d’exemple sous PHP 8.3, 8.4 et 8.5. Les deux workflows installent aussi le lecteur indépendant verrouillé dans `tools/validation-requirements.txt`. `tools/validate_mechanisms.py` contrôle l'extraction Unicode, les tables du programme de fonte incorporé, les échantillons JPEG/PNG et leur alpha, ainsi que chacune des trois révisions du nouvel exemple. Pour exécuter ces contrôles localement :

```sh
python -m pip install --require-hashes -r tools/validation-requirements.txt
python tools/validate_mechanisms.py _build/pdfs
```

## Publication

Le workflow `.github/workflows/pages.yml` construit le site pour les pull requests et à chaque push sur `master`. Il génère les douze PDF avec PHP 8.3, copie l'entrée du lecteur depuis son module verrouillé, les valide avec `qpdf` et rend leurs vingt pages avec Poppler. Il exécute aussi le script de lecture sur cette entrée. Il vérifie le site, puis publie l'artefact sur GitHub Pages uniquement depuis `master`. Les permissions d'écriture Pages et d'identité sont réservées au job de déploiement.

Les fichiers HTML et les PDF générés ne sont pas commités : ils sont produits depuis les sources du commit publié. Pour ajouter un exemple de génération, écrire le script, le tutoriel et son aperçu, puis déclarer sa route dans `tools/build_site.py`. La vérification échoue si un script du core n'a pas de page correspondante. Pour un exemple de module, déclarer également son package, ses chemins source/entrée et son nombre attendu de pages dans `EXTERNAL_EXAMPLES`, puis conserver sa sortie console et ses aperçus pendant la construction.
