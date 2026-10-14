import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('wtpdf_requirements', ROOT / 'tools/check_wtpdf_requirements.py')
CHECKER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CHECKER)


class WtPdfRequirementsInventoryTest(unittest.TestCase):
    def setUp(self):
        self.inventory = json.loads((ROOT / 'resources/wtpdf-1.0/requirements.json').read_text(encoding='utf-8'))
        self.summary = json.loads((ROOT / 'resources/wtpdf-1.0/coverage.json').read_text(encoding='utf-8'))

    def test_current_source_and_all_leaf_clauses_are_traceable(self):
        report = CHECKER.check()
        self.assertEqual(report['physical_pages'], 57)
        self.assertEqual(report['link_annotations'], 173)
        self.assertFalse(report['certification'])

    def test_omitting_a_normative_clause_is_rejected(self):
        self.inventory['requirements'] = [row for row in self.inventory['requirements'] if row['clause'] != '8.4.4']
        with patch.object(CHECKER.json, 'loads', side_effect=[self.inventory, self.summary]):
            with self.assertRaisesRegex(ValueError, 'Unclassified normative leaf clauses: 8.4.4'):
                CHECKER.check()

    def test_missing_or_escaping_evidence_is_rejected(self):
        self.inventory['requirements'][0]['evidence'] = ['../outside-evidence.php']
        with patch.object(CHECKER.json, 'loads', side_effect=[self.inventory, self.summary]):
            with self.assertRaisesRegex(ValueError, 'Missing/unsafe evidence path'):
                CHECKER.check()

    def test_changed_source_identity_is_rejected(self):
        self.inventory['sha256'] = '0' * 64
        with patch.object(CHECKER.json, 'loads', side_effect=[self.inventory, self.summary]):
            with self.assertRaisesRegex(ValueError, 'retained original'):
                CHECKER.check()


if __name__ == '__main__':
    unittest.main()
