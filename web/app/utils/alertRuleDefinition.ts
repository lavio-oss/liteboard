import type { AlertRuleDefinition, ComparisonOperator } from '~/types/alerting'

// Mirrors backend AlertRuleDefinitionRules so the form explains problems before the request.
export const RULE_DURATION_SECONDS = { min: 5, max: 3600 }
export const RULE_GAP_SECONDS = { min: 5, max: 600 }

export const OPERATOR_LABELS: Record<ComparisonOperator, string> = {
  gt: '>',
  gte: '≥',
  lt: '<',
  lte: '≤'
}

const isUpward = (operator: ComparisonOperator): boolean => operator === 'gt' || operator === 'gte'
const isInclusive = (operator: ComparisonOperator): boolean => operator === 'gte' || operator === 'lte'

/** Clear operators that point away from the trigger, e.g. trigger `>` → clear `<` or `≤`. */
export function clearOperatorsFor(trigger: ComparisonOperator): ComparisonOperator[] {
  return isUpward(trigger) ? ['lte', 'lt'] : ['gte', 'gt']
}

/** Returns field-keyed messages; an empty object means the definition is acceptable. */
export function validateAlertRuleDefinition(definition: AlertRuleDefinition): Record<string, string> {
  const errors: Record<string, string> = {}
  const { trigger, clear } = definition

  if (definition.field === '') {
    errors.field = 'Select a numeric profile field.'
  }

  for (const [key, condition] of [['trigger', trigger], ['clear', clear]] as const) {
    if (!Number.isFinite(condition.value)) {
      errors[`${key}.value`] = 'Enter a number.'
    }
    if (!Number.isInteger(condition.duration_seconds)
      || condition.duration_seconds < RULE_DURATION_SECONDS.min
      || condition.duration_seconds > RULE_DURATION_SECONDS.max) {
      errors[`${key}.duration_seconds`] = `Use ${RULE_DURATION_SECONDS.min}–${RULE_DURATION_SECONDS.max} seconds.`
    }
  }

  const gap = definition.max_sample_gap_seconds
  if (!Number.isInteger(gap) || gap < RULE_GAP_SECONDS.min || gap > RULE_GAP_SECONDS.max) {
    errors.max_sample_gap_seconds = `Use ${RULE_GAP_SECONDS.min}–${RULE_GAP_SECONDS.max} seconds.`
  } else if (gap > trigger.duration_seconds) {
    errors.max_sample_gap_seconds = 'The sample gap cannot exceed the trigger duration.'
  }

  if (isUpward(trigger.operator) === isUpward(clear.operator)) {
    errors['clear.operator'] = 'The clear operator must point opposite to the trigger.'
  } else if (Number.isFinite(trigger.value) && Number.isFinite(clear.value)) {
    const [lower, upper] = isUpward(trigger.operator) ? [trigger.value, clear.value] : [clear.value, trigger.value]
    const overlaps = isInclusive(trigger.operator) && isInclusive(clear.operator) ? upper >= lower : upper > lower

    if (overlaps) {
      errors['clear.value'] = 'The recovery range must not overlap the trigger range.'
    }
  }

  return errors
}

/** One-line human summary such as "temperature > 80 for 30s · clear ≤ 75 for 30s". */
export function describeAlertRule(definition: AlertRuleDefinition): string {
  const { field, trigger, clear } = definition

  return `${field} ${OPERATOR_LABELS[trigger.operator]} ${trigger.value} for ${trigger.duration_seconds}s · `
    + `clear ${OPERATOR_LABELS[clear.operator]} ${clear.value} for ${clear.duration_seconds}s`
}
