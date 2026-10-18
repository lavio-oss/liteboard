from __future__ import annotations

import unittest
from dataclasses import replace
from datetime import datetime, timedelta, timezone

from app.rules.definition import parse_rule_revision
from app.rules.engine import Decision, EvaluatedSample, EvaluationState, evaluate

T0 = datetime(2026, 10, 18, 8, 0, tzinfo=timezone.utc)
DEFINITION = {
    'field': 'temperature',
    'trigger': {'operator': 'gt', 'value': 80, 'duration_seconds': 30},
    'clear': {'operator': 'lte', 'value': 75, 'duration_seconds': 30},
    'max_sample_gap_seconds': 15,
    'severity': 'critical',
}


def rule(revision_id: str = 'rev-1', **overrides: object):
    return parse_rule_revision(
        rule_id='rule-1', rule_code='high_temperature', revision_id=revision_id, revision=1,
        definition={**DEFINITION, **overrides},
    )


def sample(seconds: float, temperature: object, index: int | None = None) -> EvaluatedSample:
    values = {} if temperature is ... else {'temperature': temperature}
    sample_index = index if index is not None else int(seconds * 1000)
    return EvaluatedSample(
        id=f'00000000-0000-0000-0000-{sample_index:012d}',
        message_id=f'm-{sample_index}',
        received_at=T0 + timedelta(seconds=seconds),
        values=values,
    )


def run(series: list[tuple[float, object]], severity: str | None = None, rule_revision=None):
    """Feed samples through evaluate, applying open/clear to the alert like storage does."""
    current = rule_revision or rule()
    state = EvaluationState()
    decisions: list[Decision] = []
    for seconds, temperature in series:
        decision = evaluate(state, current, severity, sample(seconds, temperature))
        decisions.append(decision)
        state = decision.state
        if decision.action == 'open':
            severity = current.severity
        elif decision.action == 'clear':
            severity = None
    return decisions, state, severity


def actions(decisions: list[Decision]) -> list[str]:
    return [decision.action for decision in decisions]


class TriggerTest(unittest.TestCase):
    def test_opens_once_the_condition_has_held_for_the_full_duration(self) -> None:
        decisions, _, severity = run([(0, 85), (10, 85), (20, 85), (30, 85), (40, 85)])

        self.assertEqual(['none', 'none', 'none', 'open', 'seen'], actions(decisions))
        self.assertEqual('critical', severity)

    def test_does_not_open_one_second_short_of_the_duration(self) -> None:
        decisions, state, _ = run([(0, 85), (10, 85), (20, 85), (29, 85)])

        self.assertNotIn('open', actions(decisions))
        self.assertEqual(T0, state.trigger_since)

    def test_threshold_equality_does_not_satisfy_greater_than(self) -> None:
        decisions, state, _ = run([(0, 80), (10, 80), (20, 80), (30, 80)])

        self.assertNotIn('open', actions(decisions))
        self.assertIsNone(state.trigger_since)

    def test_value_below_threshold_restarts_the_continuous_window(self) -> None:
        decisions, state, _ = run([(0, 85), (10, 85), (20, 70), (25, 85), (40, 85), (55, 85)])

        self.assertEqual(['none', 'none', 'none', 'none', 'none', 'open'], actions(decisions))

    def test_gte_and_lt_operators(self) -> None:
        current = rule(
            trigger={'operator': 'lt', 'value': 10, 'duration_seconds': 5},
            clear={'operator': 'gte', 'value': 12, 'duration_seconds': 5},
            max_sample_gap_seconds=5,
        )

        decisions, _, _ = run([(0, 9), (5, 9.5), (10, 12), (15, 12)], rule_revision=current)

        self.assertEqual(['none', 'open', 'none', 'clear'], actions(decisions))


class GapAndMissingValueTest(unittest.TestCase):
    def test_gap_longer_than_max_sample_gap_resets_the_window(self) -> None:
        decisions, state, _ = run([(0, 85), (10, 85), (26, 85), (40, 85)])

        self.assertNotIn('open', actions(decisions))
        self.assertEqual(T0 + timedelta(seconds=26), state.trigger_since)

    def test_gap_equal_to_max_sample_gap_keeps_the_window(self) -> None:
        decisions, _, _ = run([(0, 85), (15, 85), (30, 85)])

        self.assertEqual(['none', 'none', 'open'], actions(decisions))

    def test_missing_field_resets_the_window(self) -> None:
        decisions, _, _ = run([(0, 85), (10, ...), (20, 85), (30, 85)])

        self.assertNotIn('open', actions(decisions))

    def test_non_numeric_values_reset_the_window(self) -> None:
        for bad in (True, '85', None, float('nan')):
            with self.subTest(value=bad):
                decisions, state, _ = run([(0, 85), (10, bad), (20, 85), (30, 85)])

                self.assertNotIn('open', actions(decisions))
                self.assertIsNone(decisions[1].value)
                self.assertEqual(T0 + timedelta(seconds=30), state.cursor_received_at)

    def test_integer_values_are_accepted(self) -> None:
        decisions, _, _ = run([(0, 81), (15, 90), (30, 81)])

        self.assertEqual('open', decisions[-1].action)


class LateAndDuplicateSampleTest(unittest.TestCase):
    def test_late_or_duplicate_samples_do_not_rewind_state(self) -> None:
        current = rule()
        state = evaluate(EvaluationState(), current, None, sample(10, 85)).state
        state = evaluate(state, current, None, sample(20, 85)).state

        for stale in (sample(20, 85), sample(5, 70), sample(20, 70, index=1)):
            with self.subTest(sample=stale.id):
                decision = evaluate(state, current, None, stale)

                self.assertTrue(decision.stale)
                self.assertEqual(state, decision.state)
                self.assertEqual('none', decision.action)

    def test_same_received_at_is_ordered_by_sample_id(self) -> None:
        current = rule()
        state = evaluate(EvaluationState(), current, None, sample(10, 85, index=5)).state

        self.assertFalse(evaluate(state, current, None, sample(10, 85, index=6)).stale)
        self.assertTrue(evaluate(state, current, None, sample(10, 85, index=4)).stale)

    def test_cursor_without_sample_id_rejects_samples_at_or_before_the_cursor(self) -> None:
        state = EvaluationState(revision_id='rev-1', cursor_received_at=T0 + timedelta(seconds=60))

        self.assertTrue(evaluate(state, rule(), None, sample(60, 85)).stale)
        self.assertFalse(evaluate(state, rule(), None, sample(61, 85)).stale)


class ClearAndHysteresisTest(unittest.TestCase):
    OPEN = [(0, 85), (10, 85), (20, 85), (30, 85)]

    def test_clears_after_recovery_threshold_holds_for_clear_duration(self) -> None:
        decisions, _, severity = run(self.OPEN + [(40, 75), (50, 74), (60, 70), (70, 70)])

        self.assertEqual(['none', 'none', 'none', 'clear'], actions(decisions)[4:])
        self.assertIsNone(severity)

    def test_hysteresis_band_neither_clears_nor_keeps_the_clear_window(self) -> None:
        decisions, state, severity = run(self.OPEN + [(40, 70), (50, 78), (60, 70), (75, 70), (85, 70)])

        self.assertEqual(['none', 'none', 'none', 'none', 'none'], actions(decisions)[4:])
        self.assertEqual('critical', severity)
        self.assertEqual(T0 + timedelta(seconds=60), state.clear_since)

    def test_gap_during_recovery_resets_the_clear_window(self) -> None:
        decisions, _, severity = run(self.OPEN + [(40, 70), (60, 70), (70, 70), (80, 70)])

        self.assertNotIn('clear', actions(decisions))
        self.assertEqual('critical', severity)

    def test_still_hot_marks_alert_seen_without_clearing(self) -> None:
        decisions, _, _ = run(self.OPEN + [(40, 90), (50, 79)])

        self.assertEqual(['seen', 'none'], actions(decisions)[4:])

    def test_reopens_only_after_a_fresh_full_trigger_window(self) -> None:
        recovery = [(40, 70), (50, 70), (60, 70), (70, 70)]
        decisions, _, severity = run(self.OPEN + recovery + [(80, 85), (95, 85), (110, 85)])

        self.assertEqual(['none', 'none', 'open'], actions(decisions)[8:])
        self.assertEqual('critical', severity)


class RevisionTest(unittest.TestCase):
    def test_new_revision_resets_timers(self) -> None:
        state = run([(0, 85), (10, 85), (20, 85)])[1]

        decision = evaluate(state, rule(revision_id='rev-2'), None, sample(30, 85))

        self.assertEqual('none', decision.action)
        self.assertEqual('rev-2', decision.state.revision_id)
        self.assertEqual(T0 + timedelta(seconds=30), decision.state.trigger_since)

    def test_severity_change_on_active_alert_is_reported(self) -> None:
        state = run(ClearAndHysteresisTest.OPEN)[1]

        decision = evaluate(state, rule(revision_id='rev-2', severity='major'), 'critical', sample(40, 85))

        self.assertTrue(decision.severity_changed)
        self.assertEqual('seen', decision.action)

    def test_same_severity_is_not_reported(self) -> None:
        state = run(ClearAndHysteresisTest.OPEN)[1]

        self.assertFalse(evaluate(state, rule(), 'critical', sample(40, 85)).severity_changed)


class DeterminismTest(unittest.TestCase):
    def test_replaying_the_same_series_yields_identical_decisions(self) -> None:
        series = [(0, 85), (10, 85), (26, 85), (40, 85), (56, 85), (60, 70), (70, 78), (80, 70), (95, 70), (110, 70)]

        first = run(series)
        second = run(series)

        self.assertEqual(first, second)

    def test_replay_after_completion_is_entirely_stale(self) -> None:
        series = [(0, 85), (10, 85), (20, 85), (30, 85)]
        decisions, state, severity = run(series)

        for seconds, temperature in series:
            replayed = evaluate(state, rule(), severity, sample(seconds, temperature))
            self.assertTrue(replayed.stale)
            self.assertEqual(state, replayed.state)


class StateShapeTest(unittest.TestCase):
    def test_evaluation_records_cursor_and_revision(self) -> None:
        decision = evaluate(EvaluationState(), rule(), None, sample(0, 85))

        self.assertEqual(
            replace(
                EvaluationState(),
                revision_id='rev-1',
                trigger_since=T0,
                cursor_received_at=T0,
                cursor_sample_id=sample(0, 85).id,
            ),
            decision.state,
        )
        self.assertEqual(85.0, decision.value)
