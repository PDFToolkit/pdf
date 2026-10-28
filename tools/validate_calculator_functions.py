"""Compare emitted Type4 streams with Ghostscript, with explicit implementation-limit boundaries."""
from __future__ import annotations
import argparse
import base64
import hashlib
import io
import json
import math
from pathlib import Path
import subprocess
from pypdf import PdfReader


class CalculatorOracle:
    def __init__(self, ghostscript):
        self.ghostscript = ghostscript
        self.checks = 0
        self.documents = []
        self.evaluations = 0

    def require(self, condition, message):
        self.checks += 1
        if not condition:
            raise AssertionError(message)

    def check(self, case):
        data = base64.b64decode(case['pdf'], validate=True)
        reader = PdfReader(io.BytesIO(data), strict=True)
        function = reader.trailer['/Root']['/ExampleCalculator']
        self.require(function['/FunctionType'] == 4, 'Actual Type4 stream')
        source = function.get_data().decode('ascii')
        self.require(source.startswith('{') and source.endswith('}'), 'Outer calculator braces')
        domain = function['/Domain']
        ranges = function['/Range']
        self.require(len(domain) == 2 and len(ranges) == 2 * len(case['evaluations'][0]['outputs']), 'Actual function dimensions')
        for evaluation in case['evaluations']:
            inputs = [max(domain[2*i], min(domain[2*i+1], value)) for i, value in enumerate(evaluation['inputs'])]
            # The procedure comes from actual PDF bytes; no PHP source or evaluator is loaded by GS.
            code = ' '.join(str(value) for value in inputs) + ' ' + source + ' exec count dup == array astore == quit'
            result = subprocess.run([self.ghostscript, '-q', '-dNODISPLAY', '-dSAFER', '-c', code], capture_output=True, text=True, check=True)
            lines = result.stdout.splitlines()
            self.require(len(lines) == 2, 'Independent interpreter output framing')
            self.require(int(lines[0]) == len(evaluation['outputs']), 'Exact final operand count')
            values = [float(token) for token in lines[1].strip('[]').split()]
            self.require(len(values) == len(evaluation['outputs']) and all(math.isfinite(v) for v in values), 'All numeric finite outputs')
            for i, (actual, expected) in enumerate(zip(values, evaluation['outputs'])):
                actual = max(float(ranges[2*i]), min(float(ranges[2*i+1]), actual))
                self.require(math.isclose(actual, expected, rel_tol=3e-6, abs_tol=3e-6), f"{case['name']} emitted program output differs: {actual} vs {expected}")
            self.evaluations += 1
        self.documents.append({'name': case['name'], 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})

    def run(self, php):
        fixture = Path(__file__).with_name('fixtures') / 'calculator_functions.php'
        result = subprocess.run([php, str(fixture)], capture_output=True, text=True, check=True)
        for case in json.loads(result.stdout)['cases']:
            self.check(case)
        version = subprocess.run([self.ghostscript, '--version'], capture_output=True, text=True, check=True).stdout.strip()
        return {'status': 'PASS', 'engine': {'name': 'Ghostscript', 'version': version}, 'pdfs': len(self.documents), 'evaluations': self.evaluations, 'checks': self.checks, 'scope': 'Every permitted operator; actual emitted streams and finite test inputs. No universal-domain proof.', 'implementationBoundary': 'Integer word size and negative right shifts are implementation-sensitive; zero-filled 64-bit right shifts are tested directly against PLRM p539, not against Ghostscript arithmetic-right-shift behaviour.', 'documents': self.documents}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--ghostscript', default='gs')
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    encoded = json.dumps(CalculatorOracle(args.ghostscript).run(args.php), indent=2) + '\n'
    if args.report:
        args.report.write_text(encoded)
    print(encoded, end='')


if __name__ == '__main__':
    main()
