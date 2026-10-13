"""Check all generated documentation links, API coverage and PDF downloads."""

import argparse
import hashlib
from html import escape
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import struct
from urllib.parse import quote, unquote, urlsplit


class Document(HTMLParser):
    def __init__(self, text):
        super().__init__(convert_charrefs=True)
        self.ids = set()
        self.duplicates = set()
        self.links = []
        self.heading_count = 0
        self.feed(text)

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if 'id' in attrs:
            if attrs['id'] in self.ids:
                self.duplicates.add(attrs['id'])
            self.ids.add(attrs['id'])
        if tag == 'h1':
            self.heading_count += 1
        for key in ['href', 'src']:
            if attrs.get(key):
                self.links.append(attrs[key])
        if tag in {'script', 'iframe'} and attrs.get('src', '').startswith(('https://', 'http://', '//')):
            raise ValueError('Unexpected third-party runtime resource: ' + attrs['src'])


def check(root):
    root = root.resolve()
    manifest = json.loads((root / 'site-manifest.json').read_text(encoding='utf-8'))
    pages = manifest['pages']
    actual = {path.relative_to(root).as_posix() for path in root.rglob('*.html')}
    if actual != set(pages):
        raise ValueError('Stale or missing HTML pages: ' + str(actual ^ set(pages)))
    documents = {page: Document((root / page).read_text(encoding='utf-8')) for page in pages}
    errors = []
    targets = {}
    for page, document in documents.items():
        if document.duplicates:
            errors.append(f'{page}: duplicate IDs {document.duplicates}')
        if document.heading_count != 1:
            errors.append(f'{page}: expected one main heading, got {document.heading_count}')
        for link in document.links:
            url = urlsplit(link)
            if url.scheme or url.netloc:
                continue
            path = unquote(url.path)
            if path.startswith('/') and not path.startswith(manifest['basePath']):
                errors.append(f'{page}: URL escapes the project base path: {link}')
                continue
            context = '' if path.startswith('/') else (str(Path(page).parent) if path else page)
            key = (context, path)
            if key not in targets:
                if path.startswith('/'):
                    target = root / path[len(manifest['basePath']):]
                else:
                    target = (root / page).parent / path if path else root / page
                target = target.resolve()
                if target.is_dir():
                    target /= 'index.html'
                targets[key] = target.relative_to(root).as_posix() if target.is_relative_to(root) and target.is_file() else None
            relative = targets[key]
            if relative is None:
                errors.append(f'{page}: missing target {link}')
                continue
            if url.fragment and relative in documents and unquote(url.fragment) not in documents[relative].ids:
                errors.append(f'{page}: missing anchor {link}')
    index = json.loads((root / 'assets' / 'search-index.json').read_text(encoding='utf-8'))
    if len(index) != manifest['components'] or len({item['title'] for item in index}) != len(index):
        errors.append('Search index does not cover every unique API component')
    packages = {item['name']: item for item in manifest['packages']}
    if len(packages) != len(manifest['packages']) or 'roukmoute/pdf' not in packages:
        errors.append('Package inventory needs unique names and the core')
    if sum(package['components'] for package in packages.values()) != len(index):
        errors.append('Package inventory does not cover every API component')
    for name, package in packages.items():
        if package['components'] != sum(item['package'] == name for item in index):
            errors.append('Incorrect API package component count: ' + name)
        if package['optional'] and not re.fullmatch(r'[0-9a-f]{40}', package['revision']):
            errors.append('Optional package source is not pinned to a commit: ' + name)
    for item in index:
        if item['url'] not in documents:
            errors.append('Missing API page: ' + item['title'])
            continue
        package = packages.get(item['package'])
        if package is None or item['optional'] != package['optional']:
            errors.append('Missing or inconsistent API package: ' + item['title'])
            continue
        expected_source = package['repository'] + '/blob/' + package['revision'] + '/src/'
        if not item['sourceUrl'].startswith(expected_source) or item['sourceUrl'] not in documents[item['url']].links:
            errors.append('Incorrect API declaration source link: ' + item['title'])
    examples = manifest['examples']
    sources = manifest.get('exampleSources', {})
    if len(set(examples)) != len(examples) or set(sources) != set(examples):
        errors.append('Example source inventory must cover every example exactly once')
    if set(manifest.get('pdfPages', {})) != set(examples):
        errors.append('Rendered page inventory must cover every example exactly once')
    for name in examples:
        pdf = root / 'examples' / (name + '.pdf')
        if not pdf.is_file() or not pdf.read_bytes().startswith(b'%PDF-'):
            errors.append('Missing PDF download: ' + name)
        source = root / 'examples' / (name + '.php.txt')
        if not source.is_file():
            errors.append('Missing source download: ' + name)
        record = sources.get(name, {})
        package = packages.get(record.get('package'))
        page = record.get('page')
        if package is None or page not in documents:
            errors.append('Missing example package or documented page: ' + name)
        else:
            source_path = record.get('source', '')
            expected_source = package['repository'] + '/blob/' + package['revision'] + '/' + quote(source_path, safe='/')
            if record.get('sourceUrl') != expected_source or expected_source not in documents[page].links:
                errors.append('Incorrect example source link: ' + name)
            if source.is_file() and hashlib.sha256(source.read_bytes()).hexdigest() != record.get('sourceSha256'):
                errors.append('Example source bytes do not match their inventory: ' + name)
            if pdf.is_file() and hashlib.sha256(pdf.read_bytes()).hexdigest() != record.get('pdfSha256'):
                errors.append('Example PDF bytes do not match their inventory: ' + name)
            if record.get('kind') == 'generated':
                if package['name'] != 'roukmoute/pdf' or source_path != f'examples/{name}.php':
                    errors.append('Generated example must retain its core writer source: ' + name)
            elif record.get('kind') == 'input':
                input_path = record.get('input', '')
                expected_input = package['repository'] + '/blob/' + package['revision'] + '/' + quote(input_path, safe='/')
                if not package['optional'] or not input_path.startswith('tests/Fixtures/') or '..' in Path(input_path).parts:
                    errors.append('External input must belong to its pinned optional package: ' + name)
                if record.get('inputSourceUrl') != expected_input or expected_input not in documents[page].links:
                    errors.append('Incorrect input PDF source link: ' + name)
                if 'resultat-lecture' not in documents[page].ids:
                    errors.append('Missing reader console result panel: ' + name)
                console_path = f'examples/{name}.txt'
                console = root / console_path
                if record.get('console') != console_path or not console.is_file():
                    errors.append('Missing reader console download: ' + name)
                else:
                    result = console.read_text(encoding='utf-8')
                    if not result.strip() or hashlib.sha256(console.read_bytes()).hexdigest() != record.get('consoleSha256'):
                        errors.append('Reader console result does not match its inventory: ' + name)
                    if escape(result, quote=True) not in (root / page).read_text(encoding='utf-8'):
                        errors.append('Reader console result is missing or incorrectly escaped: ' + name)
            else:
                errors.append('Unknown example source kind: ' + name)
        previews = manifest.get('pdfPages', {}).get(name, [])
        if not previews:
            errors.append('Missing rendered PDF pages: ' + name)
        if record.get('kind') == 'input' and len(previews) != record.get('expectedPages'):
            errors.append('Missing pages from the external input preview: ' + name)
        for page_number, preview in enumerate(previews, start=1):
            expected = f'assets/pdf-pages/{name}-{page_number}.png'
            if preview != expected:
                errors.append(f'{name}: unexpected preview sequence {preview}')
                continue
            path = root / expected
            header = path.read_bytes()[:24] if path.is_file() else b''
            if len(header) < 24 or not header.startswith(b'\x89PNG\r\n\x1a\n'):
                errors.append('Missing PNG preview: ' + expected)
            elif min(struct.unpack('>II', header[16:24])) <= 0:
                errors.append('Invalid PNG dimensions: ' + expected)
    specifications = manifest.get('specificationSources', [])
    if {item['source'] for item in specifications} != {'PDF32000_2008.pdf', 'adobe_supplement_iso32000.pdf'}:
        errors.append('The documentation must retain both original specification sources')
    for source in specifications:
        target = root / source['download']
        if not target.is_file() or hashlib.sha256(target.read_bytes()).hexdigest() != source['sha256']:
            errors.append('Missing or modified specification source: ' + source['source'])
    references = manifest.get('referenceSources', [])
    expected_references = {
        'pdf_17_errata.pdf', 'pdf_reference.pdf',
        'pdf_reference1-7_addendum_redaction.pdf', 'pdf_reference_1-7.pdf',
    }
    if len(references) != len(expected_references) or {item['source'] for item in references} != expected_references:
        errors.append('The documentation must retain all four original archival Adobe references')
    guide = documents.get('guides/references-adobe.html')
    for source in references:
        download = 'downloads/adobe-references/' + source['source']
        if source.get('role') != 'archival-reference' or source.get('download') != download:
            errors.append('Incorrect archival reference role or download: ' + source['source'])
            continue
        target = root / download
        if not target.is_file() or target.stat().st_size != source['bytes'] or hashlib.sha256(target.read_bytes()).hexdigest() != source['sha256']:
            errors.append('Missing or modified archival reference: ' + source['source'])
        expected_link = manifest['basePath'] + quote(download, safe='/')
        if guide is None or not any(link.split('#', 1)[0] == expected_link for link in guide.links):
            errors.append('The archival guide must link the original download: ' + source['source'])
    if errors:
        raise ValueError('\n'.join(errors[:40]) + f'\n{len(errors)} documentation errors')
    print(f'Checked {len(pages)} HTML pages, {len(index)} API records, all local anchors and {len(manifest["examples"])} PDF/source pairs')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    check(parser.parse_args().directory)
