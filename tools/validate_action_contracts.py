"""Independent emitted-byte action checks, ISO 32000-2 12.6.4 / 12.7.6."""
from __future__ import annotations
import argparse
import base64
import hashlib
import io
import json
from pathlib import Path
import subprocess
from pypdf import PdfReader
from pypdf.generic import IndirectObject, ArrayObject


class ActionOracle:
    def __init__(self):
        self.checks = 0
        self.documents = []

    def require(self, condition, message):
        self.checks += 1
        if not condition:
            raise AssertionError(message)

    def identity(self, value):
        self.require(isinstance(value, IndirectObject), 'Native indirect target')
        return value.idnum, value.generation

    def check(self, data, updated, label):
        reader = PdfReader(io.BytesIO(data), strict=True)
        catalog = reader.trailer['/Root']
        page = reader.pages[0]
        screen = page['/Annots'][0]
        group = catalog['/OCProperties']['/OCGs'][0]
        action = catalog['/OpenAction']
        self.require(action['/S'] == '/URI' and action['/URI'] == ('updated/path' if updated else 'relative/path'), 'Effective URI action')
        following = action['/Next']
        if updated:
            self.require(following['/S'] == '/Rendition' and following['/OP'] == 1, 'Rendition operation')
            self.require(self.identity(following.raw_get('/AN')) == self.identity(screen), 'Rendition points to page annotation')
            self.require(screen.get_object()['/Subtype'] == '/Screen', 'Actual screen subtype')
            self.require(self.identity(screen.get_object().raw_get('/P')) == self.identity(page.indirect_reference), 'Screen actual page owner')
            following = following['/Next']
        else:
            self.require('/P' not in screen.get_object(), 'Page pointer added by incremental update')
        self.require(isinstance(following, ArrayObject), 'Ordered Next action array')
        actions = [value.get_object() for value in following]
        self.require([str(a['/S']) for a in actions] == ['/Named', '/JavaScript', '/Launch', '/Hide', '/SetOCGState', '/Trans', '/SubmitForm', '/ResetForm', '/ImportData'], 'Exact action sequence')
        named, script, launch, hide, state, transition, submit, reset, imported = actions
        self.require(named['/N'] == '/NextPage', 'Named action argument')
        self.require(str(script['/JS']).startswith('/* explicitly supplied'), 'Opaque supplied script preserved')
        self.require(launch['/Win']['/F'] == 'example.pdf' and launch['/Win']['/O'] == 'open', 'Launch native Win arguments')
        self.require(self.identity(hide['/T'][0]) == self.identity(screen) and not hide['/H'].value, 'Hide actual annotation target')
        self.require(state['/State'][0] == '/ON' and state['/State'][2] == '/Toggle', 'State modes')
        for index in (1, 3):
            self.require(self.identity(state['/State'][index]) == self.identity(group), 'Registered group reused explicitly')
        self.require(transition['/Trans']['/S'] == '/Dissolve' and transition['/Trans']['/D'] == 2, 'Transition values')
        self.require(submit['/F']['/FS'] == '/URL' and submit['/F']['/F'] == 'https://example.invalid/submit' and submit['/Flags'] == 12, 'URL specification and compatible HTML GET flags')
        self.require(reset['/Flags'] == 1 and '/Fields' not in reset, 'All-field reset selection')
        self.require(imported['/F'] == 'example.fdf', 'Import source emitted without I/O')
        self.documents.append({'name': label, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})

    def run(self, php):
        result = subprocess.run([php, str(Path(__file__).with_name('fixtures') / 'action_contracts.php')], capture_output=True, text=True, check=True)
        for case in json.loads(result.stdout)['cases']:
            initial, updated = [base64.b64decode(case[key], validate=True) for key in ('initial', 'updated')]
            self.require(updated.startswith(initial), 'Earlier revision bytes preserved')
            for suffix, data in [('initial', initial), ('updated', updated)]:
                self.check(data, suffix == 'updated', f"{case['edition']}-{case['format']}-{suffix}")
        return {'status': 'PASS', 'pdfs': len(self.documents), 'checks': self.checks, 'documents': self.documents}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    encoded = json.dumps(ActionOracle().run(args.php), indent=2) + '\n'
    if args.report:
        args.report.write_text(encoded)
    print(encoded, end='')


if __name__ == '__main__':
    main()
