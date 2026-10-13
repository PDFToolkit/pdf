#!/usr/bin/env python3
"""Generate concrete object classes from the checked-in specification catalogue."""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path


TYPE_MAP = {
    'name': ['NameType'], 'integer': ['IntegerType'], 'number': ['NumericType'],
    'real': ['NumericType'], 'boolean': ['BooleanType'], 'array': ['ArrayType'],
    'rectangle': ['ArrayType'], 'dictionary': ['DictionaryType'],
    'name tree': ['DictionaryType'], 'number tree': ['DictionaryType'],
    'stream': ['StreamObject'], 'text stream': ['StreamObject'],
    'function': ['DictionaryType', 'StreamObject'],
    'file specification': ['StringType', 'DictionaryType'],
    'string': ['StringType'], 'text string': ['StringType'], 'byte string': ['StringType'],
    'ascii string': ['StringType'], 'pdfdocencoded string': ['StringType'],
    'date': ['StringType'], 'text': ['StringType'],
}

# These tables explicitly add entries to common dictionary tables. This map is
# deliberate rather than inferred from English titles. Inherited attributes and
# conditional rules are left for callers; this does not claim full conformance.
COMMON_TABLES = {
    '16': ['5'], '17': ['5', '15'], '19': ['15'], '21': ['20'], '23': ['20'], '27': ['25'],
    '39': ['38'], '40': ['38'], '41': ['38'], '45': ['5'], '66': ['5'],
    '75': ['5'], '79': ['78'], '80': ['78'], '81': ['78'],
    '82': ['5', '78'], '83': ['5', '78'], '84': ['5', '78'],
    '88': ['5'], '89': ['5'], '95': ['5'], '120': ['5'], '127': ['5'],
    '131': ['5'], '132': ['5'], '133': ['5'], '146': ['5', '89'], '147': ['96'],
    '170': ['164'], '172': ['164', '170'], '173': ['164'], '174': ['164', '170'],
    '175': ['164', '170'], '177': ['164', '170'], '178': ['164', '170'],
    '179': ['164', '170'], '180': ['164', '170'], '181': ['164', '170'],
    '182': ['164', '170'], '183': ['164'], '184': ['164', '170'], '185': ['164', '170'],
    '186': ['164'], '187': ['164'], '188': ['164'], '190': ['164'], '192': ['164', '170'],
    '199': ['193'], '200': ['193'], '201': ['193'], '203': ['193'], '205': ['193'],
    '206': ['193'], '208': ['193'], '209': ['193'], '210': ['193'], '212': ['193'],
    '213': ['193'], '214': ['193'], '215': ['193'], '216': ['193'], '217': ['193'],
    '222': ['220'], '227': ['220'], '229': ['220', '222'], '231': ['220', '222'],
    '232': ['220'], '236': ['193'], '238': ['193'], '240': ['193'], '244': ['5', '45'],
    '251': ['164'], '262': ['261'], '271': ['266'], '272': ['266'], '274': ['273'],
    '277': ['273'], '286': ['285'], '287': ['285'], '288': ['285'], '294': ['5'],
    '298': ['164'], '300': ['5'], '315': ['5'], '328': ['327'], '343': ['327'],
    '344': ['327'], '345': ['327'], '346': ['327'], '347': ['327'], '348': ['327'],
    '349': ['327'], '353': ['352'], '354': ['352'], '362': ['164'], '363': ['5', '95'],
    '366': ['164'], '367': ['5', '95'],
}


def php_string(value: str) -> str:
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"


def class_name(title: str, identifier: str) -> str:
    if len(title) > 300:
        raise ValueError(f'Suspicious table title: {identifier}: {title[:100]}')
    title = re.sub(r"(?i)^(?:additional |required )?entr(?:ies|y) (?:common to all |specific to |in |for )", '', title)
    title = re.sub(r"(?i)^optional parameters? for ", '', title)
    title = re.sub(r"(?i)^(?:a|an|the) ", '', title)
    title = re.sub(r"['’]s\b", '', title)
    title = re.sub(r"(?i)\bdictionaries\b", 'Dictionary', title)
    title = title.replace('3D', 'ThreeD')
    words = re.findall(r'[A-Za-z0-9]+', title)
    result = ''.join(word[:1].upper() + word[1:] for word in words)
    if not result or result[0].isdigit():
        result = 'Definition' + result
    return result[:90] + 'Table' + identifier.replace('.', '_')


def value_classes(entry: dict) -> list[str]:
    type_name = entry['type'].lower().strip(" ‘’")
    if type_name in ['(any)', '(various)', '(undefined)', 'any']:
        return ['BasicType']
    if type_name.startswith('array of '):
        classes = ['ArrayType']
    else:
        classes = []
        for part in re.split(r'\s*,\s*(?:or\s+)?|\s+or\s+', type_name):
            if part not in TYPE_MAP:
                raise ValueError(f"Unmapped PDF type: {type_name}")
            classes.extend(TYPE_MAP[part])
    # Streams may only be referenced. A declared indirect entry may never be
    # silently converted from a direct object into an indirect reference.
    classes = [] if entry['indirect'] else [item for item in classes if item != 'StreamObject']
    return list(dict.fromkeys(classes if entry.get('direct', False) else [*classes, 'IndirectReference']))


def php_bool(value: bool) -> str:
    return 'true' if value else 'false'


def nullable_string(value: str | None) -> str:
    return 'null' if value is None else php_string(value)


def source_expression(dictionary: dict, page: int) -> str:
    return f"new SourceReference({php_string(dictionary['id'])}, {nullable_string(dictionary['section'])}, {page})"


def entry_expression(dictionary: dict, entry: dict, variable: bool) -> str:
    constraint = 'new ObjectTypeConstraint(' + ', '.join([
        php_string(entry['type']), php_bool(entry['indirect']), php_bool(entry.get('direct', False)),
        php_bool(entry.get('array_elements_direct', False)), nullable_string(entry.get('fixed_name')),
    ]) + ')'
    requirement = 'new Requirement(' + ', '.join([
        nullable_string(entry['qualifier']), php_bool(entry['required']),
        php_bool(entry['conditional']), php_bool(entry['inheritable']), nullable_string(entry['introduced_in']),
    ]) + ')'
    arguments = [
        php_string(entry['name']), constraint, requirement,
        source_expression(dictionary, entry['pdf_page']), php_string(entry['description']), php_bool(variable),
    ]
    if entry.get('implementation_correction'):
        correction = entry['implementation_correction']
        arguments.append('new \\PDF\\Specification\\ImplementationCorrection(' + ', '.join(php_string(correction[key]) for key in ['id', 'rationale', 'evidence_url', 'evidence_locator']) + ')')
    return 'new EntryDefinition(' + ', '.join(arguments) + ')'


def generate(root: Path, check: bool = False) -> None:
    catalogue_path = root / 'resources' / 'iso32000-1'
    dictionaries = json.loads((catalogue_path / 'dictionaries.json').read_text(encoding='utf-8'))
    output = root / 'src' / 'Specification' / 'Dictionary'
    if not check:
        output.mkdir(parents=True, exist_ok=True)
    definitions_output = root / 'src' / 'Specification' / 'Definition'
    if not check:
        definitions_output.mkdir(parents=True, exist_ok=True)
    differences = []

    def save(path: Path, content: str) -> None:
        expected_bytes = content.encode('utf-8')
        if check:
            if not path.is_file() or path.read_bytes() != expected_bytes:
                differences.append(str(path.relative_to(root)))
        else:
            if not path.is_file() or path.read_bytes() != expected_bytes:
                path.write_bytes(expected_bytes)
    classes = []
    files = []
    available = {dictionary['id'] for dictionary in dictionaries}
    for dictionary in dictionaries:
        name = class_name(dictionary['title'], dictionary['id'])
        table = dictionary['id']
        common = COMMON_TABLES.get(table, [])
        if any(identifier not in available for identifier in common):
            raise ValueError(f"Missing common definition for table {table}: {common}")
        title = dictionary['title'].replace('*/', '* /')
        php = "<?php\n\n// Generated by tools/generate_specification.py; edit the catalogue or generator.\n\ndeclare(strict_types=1);\n\n"
        definition_name = name + 'Definition'
        php += f"namespace PDF\\Specification\\Dictionary;\n\nuse PDF\\Specification\\SchemaDictionary;\nuse PDF\\Specification\\Definition\\{definition_name};\nuse PDF\\Syntax\\Object\\DictionaryObject\\Entry;\n\n"
        php += f"/** ISO 32000-1:2008, table {table}: {title}. */\n"
        php += f"final class {name} extends SchemaDictionary\n{{\n    public const string TABLE = {php_string(table)};\n"
        php += "    public const array COMMON_TABLES = [" + ', '.join(php_string(identifier) for identifier in common) + "];\n"
        php += f"\n    public function __construct(Entry ...$entries)\n    {{\n        parent::__construct(new {definition_name}(), ...$entries);\n    }}\n}}\n"
        path = output / f'{name}.php'
        save(path, php)
        files.append(str(path.relative_to(root)).replace('\\', '/'))
        definition_php = "<?php\n\n// Generated by tools/generate_specification.py; edit the catalogue or generator.\n\ndeclare(strict_types=1);\n\n"
        definition_php += "namespace PDF\\Specification\\Definition;\n\nuse PDF\\Specification\\EntryDefinition;\nuse PDF\\Specification\\ObjectTypeConstraint;\nuse PDF\\Specification\\Requirement;\nuse PDF\\Specification\\SourceReference;\nuse PDF\\Specification\\TableDefinition;\n\n"
        definition_php += f"/** Table {table}: {title}. */\nfinal readonly class {definition_name} extends TableDefinition\n{{\n    public function __construct()\n    {{\n        parent::__construct(\n            {source_expression(dictionary, dictionary['pdf_pages'][0])},\n            {php_string(dictionary['title'])},\n            [\n"
        for variable in [False, True]:
            for entry in dictionary['variable_entries' if variable else 'entries']:
                definition_php += '                ' + entry_expression(dictionary, entry, variable) + ',\n'
        definition_php += '            ],\n            [' + ', '.join('new ' + class_name(next(item for item in dictionaries if item['id'] == identifier)['title'], identifier) + 'Definition()' for identifier in common) + '],\n        );\n    }\n}\n'
        definition_path = definitions_output / f'{definition_name}.php'
        save(definition_path, definition_php)
        files.append(str(definition_path.relative_to(root)).replace('\\', '/'))
        entry_folder = output / name
        if not check:
            entry_folder.mkdir(exist_ok=True)
        generated_entries = []
        for variable in [False, True]:
            for index, entry in enumerate(dictionary['variable_entries' if variable else 'entries']):
                key = entry['name']
                entry_name = ('NamedEntry' if index == 0 else f'NamedEntry{index + 1}') if variable else ('Key' if key[0].isdigit() else '') + key + 'Entry'
                entry_name = entry_name.replace('.', '_').replace('-', '_')
                if not variable and sum(item['name'].lower() == key.lower() for item in dictionary['entries']) > 1:
                    # PHP class names and Windows file names are insensitive to
                    # case; PDF names are not (for example /OP and /op).
                    entry_name += 'Name' + key.encode('ascii').hex().upper()
                types = value_classes(entry)
                imports = [f'use PDF\\Syntax\\FileStructure\\{item};' if item == 'BasicType' else f'use PDF\\Syntax\\Object\\{item};' for item in sorted(set(types + (['NameType'] if variable else [])))]
                entry_php = "<?php\n\n// Generated by tools/generate_specification.py; edit the catalogue or generator.\n\ndeclare(strict_types=1);\n\n"
                entry_php += f"namespace PDF\\Specification\\Dictionary\\{name};\n\nuse PDF\\Specification\\Definition\\{definition_name};\n" + '\n'.join(imports) + '\n\n'
                qualifier = (entry['qualifier'] or 'Unspecified requirement').replace('*/', '* /')
                entry_php += f"/** Table {table}, /{key}; PDF type: {entry['type']}; {qualifier}. */\n"
                entry_php += f"final class {entry_name} extends \\PDF\\Specification\\SchemaEntry\n{{\n    public const string TABLE = {php_string(table)};\n    public const string KEY = {php_string(key)};\n"
                if variable:
                    entry_php += "    public const bool VARIABLE = true;\n"
                arguments = ('NameType $key, ' if variable else '') + '|'.join(types) + ' $value'
                definition_expression = f"(new {definition_name}())->" + ('variableEntry' if variable else 'entry') + f"({php_string(key)})"
                entry_php += f"\n    public function __construct({arguments})\n    {{\n        parent::__construct({definition_expression}, $value" + (', $key' if variable else '') + ");\n    }\n}\n"
                entry_path = entry_folder / f'{entry_name}.php'
                save(entry_path, entry_php)
                files.append(str(entry_path.relative_to(root)).replace('\\', '/'))
                generated_entries.append({'name': key, 'variable': variable, 'class': f'PDF\\Specification\\Dictionary\\{name}\\{entry_name}'})
        classes.append({'table': table, 'title': dictionary['title'], 'class': f'PDF\\Specification\\Dictionary\\{name}', 'definition_class': f'PDF\\Specification\\Definition\\{definition_name}', 'common_tables': common, 'entries': generated_entries})
    # Only remove stale files bearing this generator's own marker, inside its
    # two dedicated output directories. User-authored files are never removed.
    wanted = {(root / file).resolve() for file in files}
    for folder in [output, definitions_output]:
        if not folder.resolve().is_relative_to((root / 'src' / 'Specification').resolve()):
            raise ValueError('Generated output path is outside Specification')
        for file in folder.rglob('*.php'):
            if file.resolve() not in wanted and file.read_text(encoding='utf-8').startswith('<?php\n\n// Generated by tools/generate_specification.py;'):
                if check:
                    differences.append(str(file.relative_to(root)) + ' (stale generated file)')
                else:
                    file.unlink()
    save(catalogue_path / 'classes.json', json.dumps(classes, ensure_ascii=False, indent=2) + '\n')
    save(catalogue_path / 'generated-files.json', json.dumps(files, indent=2) + '\n')
    if differences:
        print('\n'.join(differences[:30]))
        raise SystemExit(f'{len(differences)} generated files differ; run the generator without --check.')
    print(f'Generated {len(classes)} dictionary classes, {len(classes)} immutable table definition classes and {len(files) - 2 * len(classes)} entry classes.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--check', action='store_true', help='Compare generated output without writing files')
    arguments = parser.parse_args()
    generate(arguments.root, arguments.check)
