"""Check all generated documentation links, API coverage and PDF downloads."""

import argparse
from html.parser import HTMLParser
import json
from pathlib import Path
import struct
from urllib.parse import unquote, urlsplit


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
            if path.startswith('/'):
                if not path.startswith(manifest['basePath']):
                    errors.append(f'{page}: URL escapes the project base path: {link}')
                    continue
                target = root / path[len(manifest['basePath']):]
            else:
                target = (root / page).parent / path if path else root / page
            target = target.resolve()
            if target.is_dir():
                target /= 'index.html'
            if not target.is_relative_to(root) or not target.is_file():
                errors.append(f'{page}: missing target {link}')
                continue
            relative = target.relative_to(root).as_posix()
            if url.fragment and relative in documents and unquote(url.fragment) not in documents[relative].ids:
                errors.append(f'{page}: missing anchor {link}')
    index = json.loads((root / 'assets' / 'search-index.json').read_text(encoding='utf-8'))
    if len(index) != manifest['components'] or len({item['title'] for item in index}) != len(index):
        errors.append('Search index does not cover every unique API component')
    for item in index:
        if item['url'] not in documents:
            errors.append('Missing API page: ' + item['title'])
    for name in manifest['examples']:
        pdf = root / 'examples' / (name + '.pdf')
        if not pdf.is_file() or not pdf.read_bytes().startswith(b'%PDF-'):
            errors.append('Missing PDF download: ' + name)
        if not (root / 'examples' / (name + '.php.txt')).is_file():
            errors.append('Missing source download: ' + name)
        previews = manifest.get('pdfPages', {}).get(name, [])
        if not previews:
            errors.append('Missing rendered PDF pages: ' + name)
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
    if errors:
        raise ValueError('\n'.join(errors[:40]) + f'\n{len(errors)} documentation errors')
    print(f'Checked {len(pages)} HTML pages, {len(index)} API records, all local anchors and {len(manifest["examples"])} PDF/source pairs')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    check(parser.parse_args().directory)
