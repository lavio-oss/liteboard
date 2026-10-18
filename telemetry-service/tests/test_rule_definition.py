from __future__ import annotations

import json
import unittest
from datetime import timedelta

from app.rules.definition import InvalidRuleDefinition, parse_rule_revision

DEFINITION = {
    'field': 'temperature',
    'trigger': {'operator': 'gt', 'value': 80, 'duration_seconds': 30},
    'clear': {'operator': 'lte', 'value': 75, 'duration_seconds': 30},
    'max_sample_gap_seconds': 15,
    'severity': 'critical',
}


def parse(definition: object):
    return parse_rule_revision(
        rule_id='rule-1', rule_code='high_temperature', revision_id='rev-1', revision=1, definition=definition,
    )


class ParseRuleRevisionTest(unittest.TestCase):
    def test_parses_dict_and_json_text(self) -> None:
        for raw in (DEFINITION, json.dumps(DEFINITION)):
            with self.subTest(kind=type(raw).__name__):
                parsed = parse(raw)

                self.assertEqual('temperature', parsed.field)
                self.assertEqual('gt', parsed.trigger.operator)
                self.assertEqual(80.0, parsed.trigger.value)
                self.assertEqual(timedelta(seconds=30), parsed.clear.duration)
                self.assertEqual(timedelta(seconds=15), parsed.max_sample_gap)
                self.assertEqual('critical', parsed.severity)

    def test_rejects_invalid_definitions(self) -> None:
        cases = {
            'not an object': [],
            'missing field': {key: value for key, value in DEFINITION.items() if key != 'field'},
            'unknown operator': {**DEFINITION, 'trigger': {**DEFINITION['trigger'], 'operator': 'eq'}},
            'boolean value': {**DEFINITION, 'trigger': {**DEFINITION['trigger'], 'value': True}},
            'string value': {**DEFINITION, 'clear': {**DEFINITION['clear'], 'value': '75'}},
            'zero duration': {**DEFINITION, 'trigger': {**DEFINITION['trigger'], 'duration_seconds': 0}},
            'float gap': {**DEFINITION, 'max_sample_gap_seconds': 1.5},
            'missing severity': {key: value for key, value in DEFINITION.items() if key != 'severity'},
        }

        for name, raw in cases.items():
            with self.subTest(case=name):
                with self.assertRaises(InvalidRuleDefinition):
                    parse(raw)
