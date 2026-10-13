"""Build the GitHub Pages documentation from repository guides and PHP metadata."""

from __future__ import annotations

import argparse
from collections import defaultdict
import hashlib
from html import escape
import json
from pathlib import Path
import posixpath
import re
import shutil
from urllib.parse import quote, urlsplit

import markdown


REPOSITORY = 'https://github.com/PDFToolkit/pdf'
GUIDES = [
    ('README.md', 'guides/demarrer.html', 'Démarrer', 'Guides'),
    ('docs/components.md', 'guides/composants.html', 'Comprendre les composants', 'Guides'),
    ('docs/architecture.md', 'guides/architecture.html', 'Architecture', 'Guides'),
    ('docs/packages.md', 'guides/modules.html', 'Core et modules optionnels', 'Guides'),
    ('docs/specification.md', 'guides/couverture.html', 'Couverture de la norme', 'Guides'),
    ('docs/security.md', 'guides/securite.html', 'Sécurité', 'Guides'),
    ('SECURITY.md', 'guides/signalements.html', 'Signaler une vulnérabilité', 'Guides'),
    ('CHANGELOG.md', 'guides/versions.html', 'Versions et changements', 'Guides'),
    ('docs/tutorials/README.md', 'tutorials/index.html', 'Parcours des tutoriels', 'Tutoriels'),
    ('docs/recipes/README.md', 'recipes/index.html', 'Recettes pratiques', 'Recettes'),
    ('docs/recipes/table.md', 'recipes/table.html', 'Un tableau explicite', 'Recettes'),
]
LESSONS = [
    ('01-first-document', '01-premier-document', 'Premier document', 'hello-world', 'Police, contenu, page et catalogue.'),
    ('02-pages-and-text', '02-pages-et-texte', 'Pages et texte', 'pages-and-text', 'Ressources partagées, héritage et lignes explicites.'),
    ('03-graphics', '03-dessin', 'Dessin et transformations', 'graphics', 'Chemins, couleurs, courbes et matrices.'),
    ('04-navigation', '04-navigation', 'Signets et destinations', 'navigation', 'Un plan hiérarchique et des destinations nommées.'),
    ('05-links', '05-liens', 'Liens cliquables', 'links', 'Annotations internes et actions URI.'),
    ('06-images', '06-images', 'Images', 'images', 'Un motif RGB en XObject et en image inline.'),
    ('07-gradient', '07-degrade', 'Dégradé et fonction', 'gradient', 'Une fonction de type 2 alimente un shading axial.'),
    ('08-object-stream', '08-objets-compresses', 'Objets compressés', 'object-stream', 'Filtres, flux d’objets et références croisées.'),
    ('09-embedded-font', '09-police-incorporee', 'Police TrueType et Unicode', 'embedded-font', 'Programme incorporé, CID, métriques et correspondance Unicode.'),
    ('10-image-files', '10-images-jpeg-png', 'Images JPEG et PNG', 'image-files', 'Fichiers image, couleurs, échantillons et transparence.'),
    ('11-incremental-update', '11-revisions-incrementales', 'Révisions incrémentales', 'incremental-update', 'Octets conservés, remplacements explicites et chaînage des xref.'),
    ('12-reading-pages', '12-lire-pages-pdf', 'Lire les pages d’un PDF externe', 'reading-pages', 'Arbre vérifié, attributs hérités et flux décodés explicitement.'),
]
EXTERNAL_EXAMPLES = {
    'reading-pages': {
        'package': 'pdftoolkit/pdf-reader',
        'source': 'examples/pages.php',
        'input': 'tests/Fixtures/page-tree/classic.pdf',
        'expectedPages': 3,
    },
}
GROUPS = [
    ('objets', 'Objets et références'), ('fichier', 'Écriture et fichier'),
    ('contenu', 'Contenu, texte et graphisme'), ('document', 'Structure documentaire'),
    ('fonctions', 'Fonctions PDF'), ('filtres', 'Filtres'), ('lexique', 'Conventions lexicales'),
    ('norme', 'Modèle de norme'), ('dictionnaires', 'Dictionnaires normalisés'),
    ('entrees', 'Entrées normalisées'), ('definitions', 'Définitions normalisées'),
    ('polices-externes', 'Module optionnel : fontes externes'),
    ('images-externes', 'Module optionnel : images externes'),
    ('lecture', 'Module optionnel : lecture de PDF'),
]
MODULE_GROUPS = {
    'pdftoolkit/pdf-fonts': 'polices-externes',
    'pdftoolkit/pdf-images': 'images-externes',
    'pdftoolkit/pdf-reader': 'lecture',
}


def e(value: object) -> str:
    return escape(str(value), quote=True)


def number(value: int) -> str:
    return format(value, ',').replace(',', '\u202f')


def component_route(name: str) -> str:
    short = name.rsplit('\\', 1)[-1]
    slug = re.sub(r'[^a-z0-9]+', '-', short.lower()).strip('-')[:48]
    digest = hashlib.sha256(name.encode()).hexdigest()[:12]
    return f'reference/components/{slug}-{digest}.html'


def group_for(component: dict) -> str:
    if component['package'] in MODULE_GROUPS:
        return MODULE_GROUPS[component['package']]
    path = component['source']
    if path.startswith('src/Specification/Definition/'):
        return 'definitions'
    if path.startswith('src/Specification/Dictionary/'):
        return 'entrees' if len(Path(path).parts) > 4 else 'dictionnaires'
    if path.startswith('src/Specification/'):
        return 'norme'
    if path.startswith('src/Syntax/Object/'):
        return 'objets'
    if path.startswith(('src/Serialization/', 'src/Syntax/FileStructure/')) or path == 'src/FileStructure.php':
        return 'fichier'
    if path.startswith(('src/Graphics/', 'src/Text/', 'src/Syntax/ContentStreamsResources/')):
        return 'contenu'
    if path.startswith('src/Functions/'):
        return 'fonctions'
    if path.startswith('src/Syntax/Filters/'):
        return 'filtres'
    if path.startswith('src/Syntax/LexicalConvention/'):
        return 'lexique'
    return 'document'


class Site:
    def __init__(self, root: Path, output: Path, api: Path, pdfs: Path, pdf_previews: Path, base: str, revision: str):
        self.root, self.output, self.pdfs = root, output, pdfs
        self.pdf_previews = pdf_previews
        self.pdf_pages = {}
        self.base = '/' + base.strip('/') + '/' if base.strip('/') else '/'
        self.revision = revision
        inventory = json.loads(api.read_text(encoding='utf-8'))
        self.packages = {item['name']: item for item in inventory['packages']}
        self.components = inventory['components']
        for item in self.components:
            package = self.packages.get(item['package'])
            if package is None or item['repository'] != package['repository'] or item['revision'] != package['revision']:
                raise ValueError('Inconsistent API package metadata: ' + item['name'])
            if item['optional'] and not re.fullmatch(r'[0-9a-f]{40}', item['revision'] or ''):
                raise ValueError('Optional API sources must use their locked commit: ' + item['name'])
            if not item['source'].startswith('src/') or '..' in Path(item['source']).parts:
                raise ValueError('Invalid API source path: ' + item['name'])
        self.by_name = {item['name']: item for item in self.components}
        if len(self.by_name) != len(self.components):
            raise ValueError('Duplicate API component names')
        self.by_source = {item['source']: item for item in self.components if not item['optional']}
        self.by_package_source = {(item['package'], item['source']): item for item in self.components}
        self.routes = {item['name']: component_route(item['name']) for item in self.components}
        self.groups = defaultdict(list)
        for item in self.components:
            self.groups[group_for(item)].append(item)
        self.pages = list(GUIDES)
        self.examples = {'table': 'recipes/table.html'}
        for source, slug, title, example, _ in LESSONS:
            route = f'tutorials/{slug}.html'
            self.pages.append((f'docs/tutorials/{source}.md', route, title, 'Tutoriels'))
            self.examples[example] = route
        if not set(EXTERNAL_EXAMPLES) <= set(self.examples):
            raise ValueError('Every external example needs a documented site page')
        self.example_sources = {}
        for example, route in self.examples.items():
            external = EXTERNAL_EXAMPLES.get(example)
            package_name = external['package'] if external else 'roukmoute/pdf'
            package = self.packages.get(package_name)
            if package is None:
                raise ValueError('Missing example source package: ' + package_name)
            source = external['source'] if external else f'examples/{example}.php'
            directory = root / 'vendor' / package_name if external else root
            record = {
                'kind': 'input' if external else 'generated',
                'package': package_name,
                'page': route,
                'source': source,
                'sourceUrl': self.source_url(source, owner=package),
                'path': directory / source,
            }
            if external:
                if not package['optional'] or not re.fullmatch(r'[0-9a-f]{40}', package['revision'] or ''):
                    raise ValueError('External examples need an installed package pinned to a commit')
                record.update(
                    input=external['input'],
                    inputPath=directory / external['input'],
                    inputSourceUrl=self.source_url(external['input'], owner=package),
                    expectedPages=external['expectedPages'],
                )
            self.example_sources[example] = record
        self.markdown_routes = {source: route for source, route, _, _ in self.pages}
        resources = root / 'resources' / 'iso32000-1'
        self.metadata = json.loads((resources / 'metadata.json').read_text(encoding='utf-8'))
        self.tables = {item['id']: item for item in json.loads((resources / 'dictionaries.json').read_text(encoding='utf-8'))}
        self.catalogue = json.loads((resources / 'classes.json').read_text(encoding='utf-8'))
        self.table_classes = {item['table']: item for item in self.catalogue}
        self.spec_by_name = {}
        self.entry_classes = {}
        for item in self.catalogue:
            self.spec_by_name[item['class']] = (item['table'], None)
            self.spec_by_name[item['definition_class']] = (item['table'], None)
            for entry in item['entries']:
                self.spec_by_name[entry['class']] = (item['table'], entry['name'])
                self.entry_classes[(item['table'], entry['name'])] = entry['class']
        self.corrections = json.loads((resources / 'corrections.json').read_text(encoding='utf-8'))
        self.children = defaultdict(list)
        for item in self.components:
            if item['parent']:
                self.children[item['parent']].append(item)
        self.written = []

    def url(self, route: str) -> str:
        return self.base + route

    def source_url(self, source: str, line: int | None = None, owner: dict | None = None) -> str:
        repository = owner['repository'] if owner else REPOSITORY
        revision = (owner['revision'] if owner else None) or self.revision
        url = f'{repository}/blob/{revision}/{quote(source, safe="/")}'
        return url + (f'#L{line}' if line else '')

    def link_type(self, value: str | None) -> str:
        if value is None:
            return ''
        parts, previous = [], 0
        for match in re.finditer(r'PDF\\[A-Za-z_][A-Za-z0-9_\\]*', value):
            parts.append(e(value[previous:match.start()]))
            name = match.group()
            if name in self.routes:
                parts.append(f'<a href="{e(self.url(self.routes[name]))}">{e(name)}</a>')
            else:
                parts.append(e(name))
            previous = match.end()
        return ''.join(parts) + e(value[previous:])

    def write(self, route: str, html: str) -> None:
        file = self.output / route
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(html, encoding='utf-8', newline='\n')
        self.written.append(route)

    def sidebar(self, current: str) -> str:
        groups = [
            ('Découvrir', [('index.html', 'Accueil'), ('guides/demarrer.html', 'Démarrer'), ('guides/modules.html', 'Core et modules'), ('guides/composants.html', 'Les composants'), ('guides/architecture.html', 'Architecture')]),
            ('Apprendre', [('tutorials/index.html', 'Tous les tutoriels'), *[(f'tutorials/{slug}.html', f'{i:02d}. {title}') for i, (_, slug, title, _, _) in enumerate(LESSONS, 1)], ('recipes/table.html', 'Recette : tableau')]),
            ('Explorer', [('reference/index.html', 'Référence des composants'), ('guides/couverture.html', 'Couverture de la norme'), ('guides/securite.html', 'Sécurité'), ('guides/versions.html', 'Versions')]),
        ]
        html = ''
        for label, links in groups:
            html += f'<div class="nav-group"><h2>{e(label)}</h2>'
            for route, title in links:
                active = current == route
                selected = ' aria-current="page"' if active else ''
                html += f'<a class="nav-link{" active" if active else ""}" href="{e(self.url(route))}"{selected}>{e(title)}</a>'
            html += '</div>'
        return html

    def page(self, route: str, title: str, category: str, body: str, toc: list[dict] | None = None, description: str = '') -> None:
        toc_html = ''
        if toc:
            links = ''.join(f'<li><a href="#{e(item["id"])}">{e(item["name"])}</a></li>' for item in toc if item['level'] == 2)
            if links:
                toc_html = f'<aside class="toc" aria-label="Sur cette page"><h2>Sur cette page</h2><ul>{links}</ul></aside>'
        header = '' if route == 'index.html' else f'<header class="page-header"><p class="eyebrow">{e(category)}</p><h1>{e(title)}</h1></header>'
        html = f'''<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{e(title)} · PDF</title><meta name="description" content="{e(description or title + ' — modèle objet PDF pour PHP 8.3+.')}">
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-src 'self'; object-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'">
<link rel="icon" href="{e(self.url('assets/favicon.svg'))}" type="image/svg+xml">
<link rel="stylesheet" href="{e(self.url('assets/site.css'))}"><script defer src="{e(self.url('assets/site.js'))}"></script></head>
<body><a class="skip-link" href="#main">Aller au contenu</a>
<header class="site-header"><a class="brand" href="{e(self.url('index.html'))}"><span class="brand-mark" aria-hidden="true">◈</span> PDF <small>objet par objet</small></a>
<nav class="top-nav" aria-label="Navigation principale"><a href="{e(self.url('tutorials/index.html'))}">Tutoriels</a><a href="{e(self.url('reference/index.html'))}">Référence</a><a href="{REPOSITORY}">GitHub ↗</a></nav>
<button id="menu-button" class="menu-button" type="button" aria-expanded="false" aria-controls="site-nav">Menu</button></header>
<div class="site-shell"><aside id="site-nav" class="sidebar" aria-label="Documentation">{self.sidebar(route)}<p class="sidebar-version">Documentation de <strong>master</strong><br>PHP 8.3+ · PDF 1.7</p></aside>
<main id="main" data-root="{e(self.base)}">{header}<div class="content-grid"><article class="prose">{body}</article>{toc_html}</div>
<footer class="site-footer"><span>PDF · ISO 32000-1:2008 · MIT</span><span>Référence de master · <a href="{REPOSITORY}/commit/{e(self.revision)}">{e(self.revision[:7])}</a> · <a href="{REPOSITORY}/releases/tag/v0.1.1">Stable 0.1.1</a></span></footer></main></div></body></html>'''
        self.write(route, html)

    def rewrite_link(self, source: str, href: str) -> str:
        parsed = urlsplit(href)
        if parsed.netloc == 'github.com':
            match = re.fullmatch(r'/PDFToolkit/(pdf-fonts|pdf-images|pdf-reader)/blob/(?:master|[0-9a-f]{40})/(.+)', parsed.path)
            if match:
                package_name, path = 'pdftoolkit/' + match.group(1), match.group(2)
                item = self.by_package_source.get((package_name, path))
                if item and not parsed.fragment:
                    return self.url(self.routes[item['name']])
                for name, record in self.example_sources.items():
                    if record['package'] == package_name and not parsed.fragment:
                        if record['source'] == path:
                            return self.url(self.examples[name]) + '#code-source'
                        if record.get('input') == path:
                            return self.url(f'examples/{name}.pdf')
                package = self.packages.get(package_name)
                if package:
                    fragment = '#' + parsed.fragment if parsed.fragment else ''
                    return self.source_url(path, owner=package) + fragment
        if parsed.netloc == 'pdftoolkit.github.io' and parsed.path.startswith('/pdf/'):
            return self.url(parsed.path[len('/pdf/'):]) + ('#' + parsed.fragment if parsed.fragment else '')
        if parsed.scheme or parsed.netloc or not parsed.path:
            return href
        target = posixpath.normpath(posixpath.join(posixpath.dirname(source), parsed.path))
        fragment = '#' + parsed.fragment if parsed.fragment else ''
        if target in self.markdown_routes:
            return self.url(self.markdown_routes[target]) + fragment
        if target in self.by_source and not fragment:
            return self.url(self.routes[self.by_source[target]['name']])
        if target.startswith('examples/') and target.endswith('.php'):
            example = Path(target).stem
            if example in self.examples:
                return self.url(self.examples[example]) + '#code-source'
        if target.startswith('docs/tutorials/previews/'):
            return self.url('assets/previews/' + Path(target).name)
        if target == 'PDF32000_2008.pdf':
            return self.url('downloads/iso-32000-1.pdf') + fragment
        if (self.root / target).is_file():
            return self.source_url(target) + fragment
        if (self.root / target).is_dir():
            return f'{REPOSITORY}/tree/{self.revision}/{quote(target, safe="/")}' + fragment
        raise ValueError(f'Unresolved Markdown link: {source} -> {href}')

    def render_markdown(self, source: str) -> tuple[str, list[dict]]:
        renderer = markdown.Markdown(extensions=['tables', 'fenced_code', 'toc', 'sane_lists'])
        html = renderer.convert((self.root / source).read_text(encoding='utf-8'))
        html = re.sub(r'<h1\b[^>]*>.*?</h1>\s*', '', html, count=1, flags=re.S)
        def rewrite(match):
            from html import unescape
            value = self.rewrite_link(source, unescape(match.group(2)))
            return match.group(1) + e(value) + match.group(3)
        html = re.sub(r'((?:href|src)=")([^"]+)(")', rewrite, html)
        # The first tutorial's simple flowchart is rendered locally, without a CDN.
        def flowchart(match):
            from html import unescape
            code = unescape(match.group(1))
            nodes, edges = {}, []
            for line in code.splitlines():
                parts = re.match(r'\s*(\w+)(?:\[([^\]]+)\])?\s*-->\s*(\w+)(?:\[([^\]]+)\])?', line)
                if parts:
                    left, left_text, right, right_text = parts.groups()
                    nodes.setdefault(left, left_text or left)
                    nodes.setdefault(right, right_text or right)
                    edges.append((left, right))
            if not edges:
                return match.group(0)
            levels = {key: 0 for key in nodes}
            for _ in range(len(nodes)):
                for left, right in edges:
                    levels[right] = max(levels[right], levels[left] + 1)
            if max(levels.values()) >= len(nodes):
                return match.group(0)
            by_level = defaultdict(list)
            for key, level in levels.items():
                by_level[level].append(key)
            positions = {}
            width, height = 700, (max(levels.values()) + 1) * 90
            for level, keys in by_level.items():
                for i, key in enumerate(keys):
                    positions[key] = ((i + 0.5) * width / len(keys), level * 90 + 32)
            parts = [f'<svg class="object-graph" viewBox="0 0 {width} {height}" role="img" aria-label="Le catalogue relie l’arbre des pages, les contenus et les ressources">']
            for left, right in edges:
                x, y = positions[left]
                xx, yy = positions[right]
                parts.append(f'<path d="M{x} {y+23} L{xx} {yy-23}" stroke="#0a7166" stroke-width="2" fill="none"/>')
            for key, (x, y) in positions.items():
                parts.append(f'<rect x="{x-105}" y="{y-23}" width="210" height="46" rx="8" fill="#edf5f2" stroke="#a3c3bb"/><text x="{x}" y="{y+5}" text-anchor="middle" font-family="system-ui,sans-serif" font-size="14" fill="#17212d">{e(nodes[key])}</text>')
            return ''.join(parts) + '</svg>'
        html = re.sub(r'<pre><code class="language-mermaid">(.*?)</code></pre>', flowchart, html, flags=re.S)
        def flatten(items):
            return [token for item in items for token in [item, *flatten(item.get('children', []))]]
        return html, flatten(renderer.toc_tokens)

    def example_panel(self, example: str) -> str:
        record = self.example_sources[example]
        code = record['path'].read_text(encoding='utf-8')
        pdf = self.url(f'examples/{example}.pdf')
        source = self.url(f'examples/{example}.php.txt')
        pages = self.pdf_pages[example]
        is_input = record['kind'] == 'input'
        pdf_label = 'PDF d’entrée' if is_input else 'Résultat PDF'
        heading = 'Voir le PDF d’entrée' if is_input else 'Voir le résultat PDF'
        source_name = Path(record['source']).name
        page_images = ''.join(
            f'<figure class="pdf-page"><a href="{e(self.url("assets/pdf-pages/" + image["file"]))}" target="_blank" rel="noopener" aria-label="Agrandir la page {number} sur {len(pages)}">'
            f'<img src="{e(self.url("assets/pdf-pages/" + image["file"]))}" alt="{pdf_label} {e(example)} : page {number} sur {len(pages)}" width="{image["width"]}" height="{image["height"]}" loading="lazy"></a>'
            f'<figcaption>Page {number} sur {len(pages)}</figcaption></figure>'
            for number, image in enumerate(pages, 1)
        )
        description = (
            'Ce PDF est l’entrée du lecteur : une fixture originale créée avec pypdf et qpdf, indépendamment du writer PHP. '
            'Ses trois pages sont reproduites ci-dessous ; le résultat de la lecture figure ensuite dans la sortie console.'
            if is_input else
            'Ce fichier a été généré avec le code de cette documentation. Toutes ses pages sont reproduites ci-dessous ; ouvrir le PDF pour utiliser ses liens et ses signets.'
        )
        provenance = f'<p>Code de <code>{e(record["package"])}</code> · <a href="{e(record["sourceUrl"])}">Consulter la source au commit documenté ↗</a>'
        if is_input:
            provenance += f' · <a href="{e(record["inputSourceUrl"])}">Origine du PDF d’entrée ↗</a>'
        provenance += '</p>'
        console = ''
        if is_input:
            result = (self.pdfs / (example + '.txt')).read_text(encoding='utf-8')
            console = f'''<section class="source-panel" id="resultat-lecture"><h2>Résultat de la lecture</h2>
<p>Sortie réelle du script exécuté sur le PDF d’entrée pendant la construction de cette documentation.
<a href="{e(self.url(f"examples/{example}.txt"))}" download>Télécharger la sortie console</a>.</p>
<pre><code class="language-text">{e(result)}</code></pre></section>'''
        return f'''<section class="pdf-panel" id="resultat-pdf"><h2>{heading}</h2>
<p>{description}</p>
<div class="pdf-actions"><a class="button" href="{e(pdf)}" target="_blank" rel="noopener">Ouvrir le PDF ↗</a><a class="button secondary" href="{e(pdf)}" download>Télécharger</a><a class="button secondary" href="{e(source)}" download="{e(source_name)}">Télécharger le code PHP</a></div>
<div class="pdf-pages" role="region" aria-label="Aperçu des {len(pages)} pages du PDF {e(example)}" tabindex="0">{page_images}</div>
<details class="pdf-native"><summary>Utiliser le lecteur PDF du navigateur</summary>
<p class="search-hint">Ce lecteur dépend du navigateur. Si le document ne s’affiche pas, utiliser « Ouvrir le PDF » ou « Télécharger ».</p>
<iframe class="pdf-viewer" src="{e(pdf)}#view=FitH" title="{pdf_label} : {e(example)}" loading="lazy"></iframe>
</details></section>
{console}<section class="source-panel" id="code-source"><h2>Code source complet</h2>{provenance}<details><summary>Lire le script commenté : {e(source_name)}</summary><pre><code class="language-php">{e(code)}</code></pre></details></section>'''

    def tutorial_cards(self) -> str:
        html = '<div class="cards">'
        for i, (_, slug, title, example, text) in enumerate(LESSONS, 1):
            preview = 'assets/pdf-pages/' + self.pdf_pages[example][0]['file'] if example in EXTERNAL_EXAMPLES else 'assets/previews/' + example + '.png'
            html += f'<a class="card" href="{e(self.url("tutorials/" + slug + ".html"))}"><div class="card-preview"><img src="{e(self.url(preview))}" alt="Aperçu : {e(title)}" loading="lazy"></div><div class="card-body"><span class="eyebrow">Tutoriel {i:02d}</span><h3>{e(title)}</h3><p>{e(text)}</p><span class="card-link">Lire et voir le PDF →</span></div></a>'
        return html + '</div>'

    def component_cards(self, components: list[dict]) -> str:
        html = '<div class="reference-grid">'
        for item in components:
            description = item['description'].split('\n')[0][:200]
            package = f'<span class="badge">Optionnel · {e(item["package"])}</span>' if item['optional'] else ''
            html += f'<a class="component-card" href="{e(self.url(self.routes[item["name"]]))}"><span class="badge">{e(item["kind"])}</span>{package}<h3>{e(item["shortName"])}</h3><code>{e(item["namespace"])}</code><p>{e(description)}</p></a>'
        return html + '</div>'

    def search(self) -> str:
        return f'''<div class="search-field"><label for="component-search">Rechercher un composant<input id="component-search" type="search" placeholder="NameType, /MediaBox, FlateDecode…" data-search-index="{e(self.url('assets/search-index.json'))}" autocomplete="off"></label><label for="component-kind">Type de composant<select id="component-kind"><option value="">Tous les types</option><option value="class">Classes</option><option value="interface">Interfaces</option><option value="enum">Enums</option><option value="trait">Traits</option></select></label></div><p id="search-status" class="search-hint" role="status" aria-live="polite">{number(len(self.components))} composants dans le code, dont les définitions et entrées normalisées.</p><div id="search-results" class="search-results" hidden></div>'''

    def home(self) -> None:
        body = f'''<section class="hero"><div class="hero-copy"><p class="eyebrow">PHP 8.3+ · PDF 1.7 · ISO 32000-1</p><h1>Le format PDF.<br>Objet par objet.</h1><p class="hero-description">Comprendre les structures, composer les objets, maîtriser les octets. Une documentation pour explorer le format et construire vos propres fichiers PDF.</p><div class="pdf-actions"><a class="button" href="{e(self.url('tutorials/01-premier-document.html'))}">Créer mon premier PDF →</a><a class="button secondary" href="{e(self.url('reference/index.html'))}">Explorer les composants</a></div></div><div class="hero-visual" aria-label="Du catalogue à la page"><div class="graph-card"><span class="badge">01 / point d’entrée</span><code>DocumentCatalog</code></div><div class="graph-connector" aria-hidden="true">↓</div><div class="graph-card"><span class="badge">02 / arbre du document</span><code>PageTreeNode</code></div><div class="graph-connector" aria-hidden="true">↓</div><div class="graph-card"><span class="badge">03 / contenu & ressources</span><code>PageObject</code><span class="graph-leaves">StreamObject · ResourceDictionary</span></div></div></section>
<div class="stats"><div class="stat"><strong>{number(len(self.components))}</strong><span>composants documentés</span></div><div class="stat"><strong>{self.metadata['dictionary_table_count']}</strong><span>dictionnaires normalisés</span></div><div class="stat"><strong>{len(self.examples)}</strong><span>exemples PDF à explorer</span></div><div class="stat"><strong>{self.metadata['operator_count']}</strong><span>tokens d’opérateurs PDF</span></div></div>
<section><p class="eyebrow">Apprendre avec les objets</p><h2>Construire un document et explorer ses pages</h2><p>Chaque tutoriel relie les explications aux objets PHP et au PDF créé ou lu. Consultez ses pages, le script et le résultat de son exécution.</p>{self.tutorial_cards()}</section>
<section><h2>Un core PDF, des modules au choix</h2><p>Le core représente les objets et les mécanismes de la norme Adobe. La lecture de PDF externes et la conversion de fichiers image ou de fontes sont des dépendances optionnelles : chaque module dépend du core.</p><p><a href="{e(self.url('guides/modules.html'))}">Choisir les packages et comprendre leur frontière →</a></p></section>
<section><h2>Trois chemins pour explorer le projet</h2><div class="cards"><a class="card" href="{e(self.url('guides/composants.html'))}"><div class="card-body"><h3>Comprendre les responsabilités</h3><p>Valeurs, graphes, ressources, fonctions et écriture : comment les familles se composent.</p></div></a><a class="card" href="{e(self.url('reference/index.html'))}"><div class="card-body"><h3>Consulter la référence</h3><p>Les signatures et l’héritage de chaque composant, avec les sources et les tableaux de la norme.</p></div></a><a class="card" href="{e(self.url('recipes/table.html'))}"><div class="card-body"><h3>Assembler un exemple pratique</h3><p>Un tableau explicite : cellules, couleurs, grille et positionnement du texte.</p></div></a></div></section>
<aside class="callout"><strong>Cette documentation suit master.</strong> La version stable 0.1.1 reçoit les correctifs de sécurité ; les évolutions d’API présentées ici sont celles du code en développement. La couverture et les traitements restant à implémenter sont détaillés dans <a href="{e(self.url('guides/couverture.html'))}">la couverture de la norme</a>.</aside>'''
        self.page('index.html', 'Le format PDF, objet par objet', 'Accueil', body)

    def spec_entries(self, table: str, seen: set[str] | None = None) -> list[dict]:
        seen = set() if seen is None else seen
        if table in seen:
            raise ValueError('Cyclic common specification tables')
        seen.add(table)
        entries = {}
        for common in self.table_classes[table]['common_tables']:
            for entry in self.spec_entries(common, seen.copy()):
                entries[entry['name']] = entry
        record = self.tables[table]
        for entry in [*record['entries'], *record['variable_entries']]:
            entries[entry['name']] = dict(entry, table=table, section=record['section'])
        return list(entries.values())

    def spec_panel(self, component: dict) -> str:
        spec = self.spec_by_name.get(component['name'])
        if spec is None:
            for constant in component['constants']:
                if constant['name'] == 'TABLE':
                    table = constant['value'].strip("'\"")
                    if table in self.tables:
                        spec = (table, None)
                    break
        if spec is None:
            return ''
        table, key = spec
        record = self.tables[table]
        page = record['pdf_pages'][0]
        norm = self.url('downloads/iso-32000-1.pdf')
        html = f'<section id="norme"><h2>Définition dans la norme</h2><p><strong>Tableau {e(table)}</strong> · {e(record["title"])}<br>ISO 32000-1:2008, §{e(record["section"])} · <a href="{e(norm)}#page={page}">page PDF {page} ↗</a></p>'
        entries = self.spec_entries(table)
        if key is not None:
            entries = [entry for entry in entries if entry['name'] == key]
            for entry in entries:
                constraints = []
                if entry['indirect']:
                    constraints.append('Référence indirecte exigée')
                if entry['direct']:
                    constraints.append('Objet direct exigé')
                if entry['array_elements_direct']:
                    constraints.append('Éléments directs dans le tableau')
                if entry['fixed_name']:
                    constraints.append('Nom fixe /' + entry['fixed_name'])
                html += f'<p><span class="badge">/{e(entry["name"])}</span> <span class="badge">{e(entry["type"])}</span></p><p>{e(entry["description"])}</p><p><strong>Qualificateur :</strong> {e(entry["qualifier"] or "Non précisé")}</p>'
                if constraints:
                    html += '<ul>' + ''.join(f'<li>{e(value)}</li>' for value in constraints) + '</ul>'
        else:
            html += '<div class="table-scroll"><table><thead><tr><th>Entrée</th><th>Type PDF</th><th>Exigence de la source</th><th>Tableau</th></tr></thead><tbody>'
            for entry in entries:
                name = self.entry_classes.get((entry['table'], entry['name']))
                label = '/' + entry['name']
                link = f'<a href="{e(self.url(self.routes[name]))}"><code>{e(label)}</code></a>' if name in self.routes else e(label)
                html += f'<tr><td>{link}</td><td>{e(entry["type"])}</td><td>{e(entry["qualifier"] or "Non précisé")}</td><td>{e(entry["table"])}</td></tr>'
            html += '</tbody></table></div>'
        for correction in self.corrections:
            if correction['table'] == table and (key is None or correction['entry'] == key):
                html += f'<aside class="callout"><strong>Correction d’implémentation /{e(correction["entry"])}.</strong><p>{e(correction["rationale"])}</p><a href="{e(correction["evidence_url"])}">{e(correction["evidence_locator"])}</a></aside>'
        html += f'<p class="search-hint">Les descriptions conditionnelles restent traçables. La présence d’une définition ne réalise pas à elle seule un codec, une signature ou tous les traitements du format. <a href="{e(self.url("guides/couverture.html"))}">Lire la couverture.</a></p></section>'
        return html

    def method_html(self, method: dict, component: dict) -> str:
        parameters = []
        for parameter in method['parameters']:
            text = (self.link_type(parameter['type']) + ' ') if parameter['type'] else ''
            text += ('&amp;' if parameter['byReference'] else '') + ('...' if parameter['variadic'] else '') + '$' + e(parameter['name'])
            if parameter['default'] is not None:
                text += ' = ' + self.link_type(parameter['default'])
            parameters.append(text)
        modifiers = method['visibility'] + (' static' if method['static'] else '') + (' abstract' if method['abstract'] else '') + (' final' if method['final'] else '')
        signature = e(modifiers) + ' function ' + e(method['name']) + '(' + ', '.join(parameters) + ')'
        if method['returnType']:
            signature += ': ' + self.link_type(method['returnType'])
        owner = self.by_name.get(method['declaringClass'], component)
        source = self.source_url(method.get('source') or owner['source'], method['line'], method if 'package' in method else owner)
        description = f'<pre class="member-doc">{e(method["description"])}</pre>' if method['description'] else ''
        return f'<section class="member" id="method-{e(method["name"])}"><h3>{e(method["name"])}()</h3><div class="api-signature"><code>{signature}</code></div>{description}<p class="member-meta">Déclarée par {self.link_type(method["declaringClass"])} · <a href="{e(source)}">Voir le code ↗</a></p></section>'

    def api_pages(self) -> None:
        labels = dict(GROUPS)
        records = []
        for item in self.components:
            name = item['name']
            group = group_for(item)
            spec = self.spec_by_name.get(name)
            search_extra = (' /' + spec[1] + ' tableau ' + spec[0]) if spec and spec[1] else ''
            records.append({'title': name, 'shortName': item['shortName'], 'namespace': item['namespace'], 'kind': item['kind'], 'url': self.routes[name], 'description': item['description'].split('\n')[0][:240] + search_extra, 'group': labels[group], 'package': item['package'], 'optional': item['optional'], 'sourceUrl': self.source_url(item['source'], item['line'], item)})
            flags = [item['kind'], *[flag for flag in ['abstract', 'final', 'readonly'] if item[flag]]]
            flags.append(('Optionnel · ' if item['optional'] else 'Core · ') + item['package'])
            body = f'<p class="breadcrumbs"><a href="{e(self.url("reference/index.html"))}">Référence</a> / <a href="{e(self.url("reference/groups/" + group + ".html"))}">{e(labels[group])}</a></p><p>' + ' '.join(f'<span class="badge">{e(flag)}</span>' for flag in flags) + f'</p><div class="api-signature"><code>{e(name)}</code></div>'
            if item['description']:
                body += f'<pre class="member-doc">{e(item["description"])}</pre>'
            body += f'<p><a href="{e(self.source_url(item["source"], item["line"], item))}">Lire la déclaration source ↗</a></p>'
            if item['optional']:
                body += f'<aside class="callout">Ce composant appartient à <code>{e(item["package"])}</code>, un module installé séparément du core. <a href="{e(self.url("guides/modules.html"))}">Installation et responsabilités des packages.</a></aside>'
            body += '<section id="relations"><h2>Héritage et contrats</h2>'
            relations = [('Parent', [item['parent']] if item['parent'] else []), ('Interfaces', item['interfaces']), ('Traits', item['traits'])]
            for label, values in relations:
                if values:
                    body += f'<p><strong>{e(label)} :</strong> ' + ', '.join(self.link_type(value) for value in values) + '</p>'
            if not any(values for _, values in relations):
                body += '<p>Ce type ne déclare pas de parent, d’interface ou de trait.</p>'
            children = self.children[name]
            if children:
                body += f'<details><summary>{len(children)} types dérivés directement</summary><ul>' + ''.join(f'<li>{self.link_type(child["name"])}</li>' for child in children) + '</ul></details>'
            body += '</section>' + self.spec_panel(item)
            if item['constructor']:
                body += '<section id="construction"><h2>Construction</h2>' + self.method_html(item['constructor'], item) + '</section>'
            if item['methods']:
                body += '<section id="methodes"><h2>Méthodes déclarées</h2>' + ''.join(self.method_html(method, item) for method in item['methods']) + '</section>'
            if item['inheritedMethods']:
                body += f'<section id="methodes-heritees"><h2>Méthodes héritées</h2><details><summary>{len(item["inheritedMethods"])} méthodes des parents et contrats</summary>' + ''.join(self.method_html(method, item) for method in item['inheritedMethods']) + '</details></section>'
            if item['properties']:
                body += '<section id="proprietes"><h2>Propriétés</h2><div class="table-scroll"><table><thead><tr><th>Déclaration</th><th>Provenance et documentation</th></tr></thead><tbody>'
                for prop in item['properties']:
                    signature = e(prop['visibility'] + (' static' if prop['static'] else '') + (' readonly' if prop['readonly'] else '')) + ' ' + self.link_type(prop['type']) + ' $' + e(prop['name'])
                    body += f'<tr><td><code>{signature}</code></td><td>{self.link_type(prop["declaringClass"])}<pre class="member-doc">{e(prop["description"])}</pre></td></tr>'
                body += '</tbody></table></div></section>'
            if item['constants']:
                body += '<section id="constantes"><h2>Constantes</h2><div class="table-scroll"><table><thead><tr><th>Nom</th><th>Valeur PHP</th><th>Provenance</th></tr></thead><tbody>'
                for constant in item['constants']:
                    body += f'<tr><td><code>{e(constant["name"])}</code></td><td><pre><code>{e(constant["value"])}</code></pre></td><td>{self.link_type(constant["declaringClass"])}</td></tr>'
                body += '</tbody></table></div></section>'
            if item['cases']:
                body += '<section id="cas"><h2>Cas de l’enum</h2><ul>' + ''.join(f'<li><code>{e(case["name"])}</code>' + (f' = <code>{e(json.dumps(case["value"], ensure_ascii=False))}</code>' if case['value'] is not None else '') + '</li>' for case in item['cases']) + '</ul></section>'
            toc = [{'id': key, 'name': label, 'level': 2} for key, label in [('relations', 'Héritage'), ('norme', 'Norme'), ('construction', 'Construction'), ('methodes', 'Méthodes'), ('methodes-heritees', 'Méthodes héritées'), ('proprietes', 'Propriétés'), ('constantes', 'Constantes'), ('cas', 'Cas de l’enum')] if f'id="{key}"' in body]
            self.page(self.routes[name], item['shortName'], labels[group], body, toc)
        (self.output / 'assets' / 'search-index.json').write_text(json.dumps(records, ensure_ascii=False, separators=(',', ':')), encoding='utf-8')
        body = '<p>Chaque classe, interface, enum et trait du core et des modules installés pour cette documentation possède une fiche. Les composants optionnels portent leur nom de package ; les installer dépend des traitements nécessaires à votre application. Les signatures sont extraites par Reflection ; les descriptions normalisées proviennent du catalogue de la référence PDF.</p>' + self.search()
        body += '<div id="component-list"><h2>Explorer par famille</h2><div class="cards">'
        for group, label in GROUPS:
            items = self.groups[group]
            if items:
                body += f'<a class="card" href="{e(self.url("reference/groups/" + group + ".html"))}"><div class="card-body"><span class="eyebrow">{len(items)} composants</span><h3>{e(label)}</h3><span class="card-link">Consulter la famille →</span></div></a>'
                group_body = '<p>Les composants de cette famille sont tous accessibles ci-dessous. La recherche globale permet aussi de retrouver une entrée PDF ou un nom de type.</p>' + self.component_cards(items)
                self.page('reference/groups/' + group + '.html', label, 'Référence', group_body)
        body += '</div><h2>Repères pour commencer</h2>'
        featured_names = ['PDF\\FileStructure', 'PDF\\Syntax\\Object\\NameType', 'PDF\\Syntax\\Object\\DictionaryType', 'PDF\\Syntax\\Object\\StreamObject', 'PDF\\Syntax\\DocumentStructure\\DocumentCatalog', 'PDF\\Syntax\\DocumentStructure\\PageTree\\PageObject', 'PDF\\Syntax\\ContentStreamsResources\\ContentStreams', 'PDF\\Serialization\\SerializationContext']
        body += self.component_cards([self.by_name[name] for name in featured_names]) + '</div>'
        self.page('reference/index.html', 'Tous les composants', 'Référence API', body)

    def build(self) -> None:
        if self.output == self.root or self.root in self.output.parents and self.output.relative_to(self.root).parts[0] in {'src', 'docs', 'examples', 'tools', 'resources'}:
            raise ValueError('Build output must be separate from source directories')
        self.output.mkdir(parents=True, exist_ok=True)
        for asset in ['site.css', 'site.js']:
            target = self.output / 'assets' / asset
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(self.root / 'docs' / 'site' / 'assets' / asset, target)
        previews = self.output / 'assets' / 'previews'
        previews.mkdir(parents=True, exist_ok=True)
        for image in (self.root / 'docs' / 'tutorials' / 'previews').glob('*.png'):
            shutil.copyfile(image, previews / image.name)
        downloads = self.output / 'downloads'
        downloads.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(self.root / 'PDF32000_2008.pdf', downloads / 'iso-32000-1.pdf')
        examples = self.output / 'examples'
        examples.mkdir(parents=True, exist_ok=True)
        expected = set(self.examples)
        actual = {path.stem for path in (self.root / 'examples').glob('*.php')}
        core_examples = expected - set(EXTERNAL_EXAMPLES)
        if actual != core_examples:
            raise ValueError('Every writer example needs a documented site page: ' + str(actual ^ core_examples))
        for example in sorted(expected):
            record = self.example_sources[example]
            source_pdf = self.pdfs / (example + '.pdf')
            if not source_pdf.is_file() or not source_pdf.read_bytes().startswith(b'%PDF-'):
                raise ValueError('Generate the example PDF first: ' + example)
            shutil.copyfile(source_pdf, examples / source_pdf.name)
            if not record['path'].is_file():
                raise ValueError('Missing example source in its package: ' + example)
            shutil.copyfile(record['path'], examples / (example + '.php.txt'))
            record['sourceSha256'] = hashlib.sha256(record['path'].read_bytes()).hexdigest()
            record['pdfSha256'] = hashlib.sha256(source_pdf.read_bytes()).hexdigest()
            if record['kind'] == 'input':
                if not record['inputPath'].is_file() or source_pdf.read_bytes() != record['inputPath'].read_bytes():
                    raise ValueError('The reader example PDF must preserve its original input: ' + example)
                console_path = self.pdfs / (example + '.txt')
                if not console_path.is_file() or not console_path.read_text(encoding='utf-8').strip():
                    raise ValueError('Run the reader example and retain its console result first: ' + example)
                shutil.copyfile(console_path, examples / console_path.name)
                record['console'] = f'examples/{example}.txt'
                record['consoleSha256'] = hashlib.sha256(console_path.read_bytes()).hexdigest()
            images = {}
            for image in self.pdf_previews.glob(example + '-*.png'):
                match = re.fullmatch(re.escape(example) + r'-(\d+)\.png', image.name)
                if not match:
                    raise ValueError('Unexpected PDF preview filename: ' + image.name)
                number = int(match.group(1))
                if number < 1 or number in images:
                    raise ValueError('Duplicate or invalid PDF preview page: ' + image.name)
                images[number] = image
            if not images or sorted(images) != list(range(1, len(images) + 1)):
                raise ValueError('Render every PDF page in sequence first: ' + example)
            if record['kind'] == 'input' and len(images) != record['expectedPages']:
                raise ValueError('Render every page of the external input PDF: ' + example)
            self.pdf_pages[example] = []
            for number, image in sorted(images.items()):
                with image.open('rb') as stream:
                    header = stream.read(24)
                if len(header) != 24 or header[:8] != b'\x89PNG\r\n\x1a\n' or header[12:16] != b'IHDR':
                    raise ValueError('Invalid PDF page PNG: ' + image.name)
                width, height = int.from_bytes(header[16:20], 'big'), int.from_bytes(header[20:24], 'big')
                if not width or not height:
                    raise ValueError('Empty PDF page PNG: ' + image.name)
                filename = f'{example}-{number}.png'
                target = self.output / 'assets' / 'pdf-pages' / filename
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(image, target)
                self.pdf_pages[example].append({'file': filename, 'width': width, 'height': height})
        self.write('assets/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#0a7166"/><path d="M9 7h10l5 5v13H9z" fill="none" stroke="white" stroke-width="2"/><path d="M19 7v6h5" fill="none" stroke="white" stroke-width="2"/></svg>')
        self.home()
        for source, route, title, category in self.pages:
            body, toc = self.render_markdown(source)
            if route == 'tutorials/index.html':
                body = self.tutorial_cards() + body
            example = next((name for name, target in self.examples.items() if target == route), None)
            if example:
                body += self.example_panel(example)
                if example in EXTERNAL_EXAMPLES:
                    toc += [{'id': 'resultat-pdf', 'name': 'PDF d’entrée', 'level': 2}, {'id': 'resultat-lecture', 'name': 'Résultat de la lecture', 'level': 2}]
                else:
                    toc += [{'id': 'resultat-pdf', 'name': 'Résultat PDF', 'level': 2}]
                toc += [{'id': 'code-source', 'name': 'Code source', 'level': 2}]
            self.page(route, title, category, body, toc)
        self.api_pages()
        self.page('404.html', 'Page introuvable', 'Documentation', f'<p>Cette adresse ne correspond pas à une page publiée.</p><p><a href="{e(self.url("reference/index.html"))}">Rechercher un composant</a> ou <a href="{e(self.url("index.html"))}">revenir à l’accueil</a>.</p>')
        (self.output / '.nojekyll').write_text('')
        packages = [dict(package, revision=package['revision'] or self.revision, components=sum(item['package'] == name for item in self.components)) for name, package in self.packages.items()]
        sources = {example: {key: value for key, value in record.items() if key not in {'path', 'inputPath'}} for example, record in self.example_sources.items()}
        manifest = {'basePath': self.base, 'revision': self.revision, 'packages': packages, 'components': len(self.components), 'examples': sorted(expected), 'exampleSources': sources, 'pdfPages': {example: ['assets/pdf-pages/' + image['file'] for image in images] for example, images in self.pdf_pages.items()}, 'pages': sorted(route for route in self.written if route.endswith('.html'))}
        (self.output / 'site-manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
        print(f'Built {len(manifest["pages"])} pages, {len(self.components)} API components and {len(expected)} PDF examples into {self.output}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--api', type=Path, required=True)
    parser.add_argument('--pdf-dir', type=Path, required=True)
    parser.add_argument('--pdf-preview-dir', type=Path, required=True, help='PNG pages rendered with pdftoppm: <example>-1.png, <example>-2.png, ...')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--base-path', default='/pdf')
    parser.add_argument('--revision', default='master')
    args = parser.parse_args()
    if not re.fullmatch(r'(?:[0-9a-f]{40}|master)', args.revision):
        parser.error('revision must be a commit SHA or master')
    if not re.fullmatch(r'(?:/[A-Za-z0-9_.-]+)*/?', args.base_path):
        parser.error('base-path must contain only URL path segments')
    Site(args.root.resolve(), args.output.resolve(), args.api.resolve(), args.pdf_dir.resolve(), args.pdf_preview_dir.resolve(), args.base_path, args.revision).build()
