#!/usr/bin/env python3
"""Compile explicitly reviewed relations, never infer constraints from prose."""
from __future__ import annotations

import json
from pathlib import Path
from typing import Callable


def load_rules(catalogue: Path) -> dict[str, list[dict]]:
    path = catalogue / 'semantic-rules.json'
    if not path.is_file():
        return {}
    resource = json.loads(path.read_text(encoding='utf-8'))
    result: dict[str, list[dict]] = {}
    identifiers = set()
    for rule in resource['rules']:
        if rule['id'] in identifiers or rule['physical_pdf_page'] < 1:
            raise ValueError(f'Invalid semantic rule identity: {rule}')
        identifiers.add(rule['id'])
        result.setdefault(rule['table'], []).append(rule)
    return result


def php_value(value: object) -> str:
    if value is None:
        return 'null'
    if isinstance(value, bool):
        return 'true' if value else 'false'
    if isinstance(value, str):
        return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"
    if isinstance(value, (int, float)):
        return str(value)
    if isinstance(value, list):
        return '[' + ', '.join(php_value(item) for item in value) + ']'
    raise ValueError(f'Unsupported semantic argument: {value!r}')


def render_rules(rules: list[dict], source: Callable[[int], str]) -> str:
    kinds = {'NumberEntry', 'NumberArray', 'NameEntry', 'ConditionalEntries', 'FilterParameters',
             'CrossReferenceIndex', 'CrossReferenceDirectObjects', 'CalibratedColour',
             'FunctionDimensions', 'ImageSamples', 'PredictorParameters', 'IccComponents',
             'AnnotationPath', 'ChoiceOptions', 'DirectDictionaryObjects', 'EmbeddedFileSubtype',
             'TreeKeyPairs', 'NonNullStructureEntries'}
    expressions = []
    for rule in rules:
        kind = rule['constraint']
        if kind not in kinds:
            raise ValueError(f'Unknown reviewed semantic constraint: {kind}')
        arguments = ', '.join(key + ': ' + php_value(value) for key, value in rule.get('arguments', {}).items())
        expressions.append('new \\PDF\\Specification\\SemanticRule(' + php_value(rule['id']) + ', '
                           + source(rule['physical_pdf_page']) + ', new \\PDF\\Specification\\Constraint\\'
                           + kind + '(' + arguments + '))')
    if not expressions:
        return ''
    return '            [\n' + ''.join('                ' + item + ',\n' for item in expressions) + '            ],\n'
