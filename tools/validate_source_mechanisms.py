#!/usr/bin/env python3
"""Run independent native-mechanism validations against generated examples and fixtures."""

from pathlib import Path
import argparse
import subprocess
import sys
import tempfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('examples', type=Path)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--pdftotext', default='pdftotext')
    parser.add_argument('--poppler-glib-bin', type=Path)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    examples = args.examples.resolve()

    def run(*command):
        subprocess.run(list(map(str, command)), cwd=root, check=True)

    with tempfile.TemporaryDirectory(prefix='pdf-source-mechanisms-') as temporary:
        directory = Path(temporary)
        run(args.php, 'tools/fixtures/acroform_fields.php', directory)
        run(sys.executable, 'tools/validate_forms.py', directory)
        run(sys.executable, 'tools/validate_tagging.py', examples)
        mesh = directory / 'colour-meshes.json'
        with mesh.open('wb') as output:
            subprocess.run([args.php, 'tools/fixtures/colour_meshes.php'], cwd=root, check=True, stdout=output)
        run(sys.executable, 'tools/validate_colour_patterns.py', examples / 'colours-patterns.pdf', mesh)
        graphics = directory / 'native-graphics.json'
        with graphics.open('wb') as output:
            subprocess.run([args.php, 'tools/fixtures/native_graphics.php'], cwd=root, check=True, stdout=output)
        run(sys.executable, 'tools/validate_native_graphics.py', examples / 'native-graphics.pdf', graphics)
        poppler = ['--poppler-glib-bin', args.poppler_glib_bin] if args.poppler_glib_bin else ['--pdftotext', args.pdftotext]
        run(sys.executable, 'tools/validate_cmaps.py', examples, *poppler)
        run(sys.executable, 'tools/validate_native_fonts.py', examples / 'native-fonts.pdf', *poppler)
        run(sys.executable, 'tools/validate_lzw_predictors.py', '--php', args.php, '--qpdf', args.qpdf)
        run(sys.executable, 'tools/validate_linearization.py', '--php', args.php, '--qpdf', args.qpdf)
        run(sys.executable, 'tools/validate_linearization_graphs.py', '--php', args.php, '--qpdf', args.qpdf)
        run(sys.executable, 'tools/validate_hybrid_references.py', '--php', args.php, '--qpdf', args.qpdf)
        run(sys.executable, 'tools/validate_iso_security.py', '--php', args.php, '--qpdf', args.qpdf)
        run(sys.executable, 'tools/validate_public_key_security.py', '--php', args.php)
        run(sys.executable, 'tools/validate_pdf_signatures.py', '--php', args.php, '--qpdf', args.qpdf)
        run(sys.executable, 'tools/validate_signature_algorithms.py', '--php', args.php, '--qpdf', args.qpdf)
    print('Native source mechanisms: all independent validators passed.')


if __name__ == '__main__':
    main()
