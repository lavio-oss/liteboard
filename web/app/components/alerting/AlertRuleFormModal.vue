<script setup lang="ts">
import type { FormError, FormErrorEvent, TabsItem } from '@nuxt/ui'
import type { AlertRule, AlertRuleDefinition, AlertRuleInput, AlertSeverity, ComparisonOperator } from '~/types/alerting'
import type { DeviceProfile } from '~/types/admin'
import {
  OPERATOR_LABELS,
  RULE_DURATION_SECONDS,
  RULE_GAP_SECONDS,
  clearOperatorsFor,
  validateAlertRuleDefinition
} from '~/utils/alertRuleDefinition'

type SelectOption = { label: string, value: string }

const props = withDefaults(defineProps<{
  rule?: AlertRule | null
  projectOptions: SelectOption[]
  profiles: DeviceProfile[]
  loading?: boolean
}>(), {
  rule: null,
  loading: false
})

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  submit: [value: { projectId: string, input: AlertRuleInput }]
}>()

interface RuleFormState {
  projectId: string | undefined
  profileId: string | undefined
  code: string
  name: string
  field: string | undefined
  triggerOperator: ComparisonOperator
  triggerValue: number | undefined
  triggerDuration: number
  clearOperator: ComparisonOperator
  clearValue: number | undefined
  clearDuration: number
  gap: number
  severity: AlertSeverity
}

const state = reactive<RuleFormState>({
  projectId: undefined,
  profileId: undefined,
  code: '',
  name: '',
  field: undefined,
  triggerOperator: 'gt',
  triggerValue: undefined,
  triggerDuration: 30,
  clearOperator: 'lte',
  clearValue: undefined,
  clearDuration: 30,
  gap: 15,
  severity: 'critical'
})

const isRevision = computed(() => props.rule !== null)
const tabs: TabsItem[] = [
  { label: 'General', value: 'general', icon: 'i-lucide-settings-2', slot: 'general' as const },
  { label: 'Condition', value: 'condition', icon: 'i-lucide-sliders-horizontal', slot: 'condition' as const }
]
const GENERAL_FIELDS = ['projectId', 'profileId', 'name', 'code']
const activeTab = ref('general')
const title = computed(() => isRevision.value ? `New revision of ${props.rule?.name}` : 'Add alert rule')
const operatorItems = (operators: ComparisonOperator[]) => operators.map(value => ({ label: OPERATOR_LABELS[value], value }))
const severityItems = (['critical', 'major', 'minor', 'warning'] as const).map(value => ({ label: value, value }))

const profileOptions = computed(() => props.profiles
  .filter(profile => profile.project_id === state.projectId && profile.versions.length > 0)
  .map(profile => ({ label: `${profile.name} (${profile.code})`, value: profile.id })))

// Rules can only watch numeric fields of the newest profile version, as the API enforces.
const fieldOptions = computed(() => {
  const profile = props.profiles.find(item => item.id === state.profileId)
  const latest = profile?.versions.reduce<typeof profile.versions[number] | undefined>(
    (newest, version) => newest === undefined || version.version > newest.version ? version : newest,
    undefined
  )

  return Object.entries(latest?.definition.fields ?? {})
    .filter(([, field]) => field.type === 'integer' || field.type === 'float')
    .map(([name, field]) => ({ label: `${name} (${field.unit})`, value: name }))
})

watch(() => state.triggerOperator, (trigger) => {
  if (!clearOperatorsFor(trigger).includes(state.clearOperator)) {
    state.clearOperator = clearOperatorsFor(trigger)[0]!
  }
})

watch(() => state.profileId, () => {
  if (!fieldOptions.value.some(option => option.value === state.field)) {
    state.field = undefined
  }
})

watch(
  () => [open.value, props.rule],
  () => {
    const rule = props.rule
    const definition = rule?.current_revision?.definition

    state.projectId = rule?.project_id ?? props.projectOptions[0]?.value
    state.profileId = rule?.device_profile_id
    state.code = rule?.code ?? ''
    state.name = rule?.name ?? ''
    state.field = definition?.field
    state.triggerOperator = definition?.trigger.operator ?? 'gt'
    state.triggerValue = definition?.trigger.value
    state.triggerDuration = definition?.trigger.duration_seconds ?? 30
    state.clearOperator = definition?.clear.operator ?? 'lte'
    state.clearValue = definition?.clear.value
    state.clearDuration = definition?.clear.duration_seconds ?? 30
    state.gap = definition?.max_sample_gap_seconds ?? 15
    state.severity = definition?.severity ?? 'critical'
    // A revision keeps its name, code and profile, so it opens straight on the condition.
    activeTab.value = rule === null ? 'general' : 'condition'
  },
  { immediate: true }
)

function definitionFrom(form: RuleFormState): AlertRuleDefinition {
  return {
    field: form.field ?? '',
    trigger: { operator: form.triggerOperator, value: form.triggerValue ?? Number.NaN, duration_seconds: form.triggerDuration },
    clear: { operator: form.clearOperator, value: form.clearValue ?? Number.NaN, duration_seconds: form.clearDuration },
    max_sample_gap_seconds: form.gap,
    severity: form.severity
  }
}

const FORM_FIELDS: Record<string, string> = {
  'field': 'field',
  'trigger.value': 'triggerValue',
  'trigger.duration_seconds': 'triggerDuration',
  'clear.operator': 'clearOperator',
  'clear.value': 'clearValue',
  'clear.duration_seconds': 'clearDuration',
  'max_sample_gap_seconds': 'gap'
}

function validate(form: RuleFormState): FormError[] {
  const errors: FormError[] = Object.entries(validateAlertRuleDefinition(definitionFrom(form)))
    .map(([key, message]) => ({ name: FORM_FIELDS[key] ?? key, message }))

  if (!isRevision.value) {
    if (form.projectId === undefined) errors.push({ name: 'projectId', message: 'Select a project.' })
    if (form.profileId === undefined) errors.push({ name: 'profileId', message: 'Select a device profile.' })
    if (form.code.trim() === '') errors.push({ name: 'code', message: 'Enter a code.' })
    if (form.name.trim().length < 2) errors.push({ name: 'name', message: 'Enter a name of at least 2 characters.' })
  }

  return errors
}

// Fields of the hidden tab stay mounted, so jump to the tab that holds the first error.
function onError(event: FormErrorEvent): void {
  const first = event.errors[0]?.name

  if (first !== undefined) {
    activeTab.value = GENERAL_FIELDS.includes(first) ? 'general' : 'condition'
  }
}

function onSubmit(): void {
  if (state.projectId === undefined || state.profileId === undefined) {
    throw new Error('Validation guarantees a project and a profile.')
  }

  emit('submit', {
    projectId: state.projectId,
    input: {
      code: state.code.trim(),
      name: state.name.trim(),
      device_profile_id: state.profileId,
      definition: definitionFrom(state)
    }
  })
}
</script>

<template>
  <UModal
    v-model:open="open"
    :title="title"
    :ui="{ content: 'max-w-3xl' }"
  >
    <template #body>
      <UForm
        :state="state"
        :validate="validate"
        :disabled="props.loading"
        class="space-y-5"
        @submit="onSubmit"
        @error="onError"
      >
        <UTabs
          v-model="activeTab"
          :items="tabs"
          :unmount-on-hide="false"
          variant="link"
          class="w-full"
        >
          <template #general>
            <div class="grid gap-4 pt-4 sm:grid-cols-2">
              <UFormField
                name="projectId"
                label="Project"
                required
              >
                <USelect
                  v-model="state.projectId"
                  :items="props.projectOptions"
                  class="w-full"
                  :disabled="isRevision"
                />
              </UFormField>
              <UFormField
                name="profileId"
                label="Device profile"
                required
              >
                <USelect
                  v-model="state.profileId"
                  :items="profileOptions"
                  placeholder="Select a profile"
                  class="w-full"
                  :disabled="isRevision"
                />
              </UFormField>
              <UFormField
                name="name"
                label="Name"
                required
              >
                <UInput
                  v-model="state.name"
                  class="w-full"
                  placeholder="Enter rule name"
                  :disabled="isRevision"
                />
              </UFormField>
              <UFormField
                name="code"
                label="Code"
                required
              >
                <UInput
                  v-model="state.code"
                  class="w-full"
                  placeholder="Enter rule code"
                  :disabled="isRevision"
                />
              </UFormField>
            </div>
          </template>

          <template #condition>
            <div class="space-y-5 pt-4">
              <div class="grid gap-4 sm:grid-cols-2">
                <UFormField
                  name="field"
                  label="Field"
                  required
                >
                  <USelect
                    v-model="state.field"
                    :items="fieldOptions"
                    placeholder="Select a field"
                    class="w-full"
                    :disabled="state.profileId === undefined"
                  />
                </UFormField>
                <UFormField
                  name="severity"
                  label="Severity"
                  required
                >
                  <USelect
                    v-model="state.severity"
                    :items="severityItems"
                    class="w-full"
                  />
                </UFormField>
              </div>

              <div class="grid gap-4 sm:grid-cols-3">
                <UFormField
                  name="triggerOperator"
                  label="Trigger when"
                  required
                >
                  <USelect
                    v-model="state.triggerOperator"
                    :items="operatorItems(['gt', 'gte', 'lt', 'lte'])"
                    class="w-full"
                  />
                </UFormField>
                <UFormField
                  name="triggerValue"
                  label="Threshold"
                  required
                >
                  <UInputNumber
                    v-model="state.triggerValue"
                    class="w-full"
                    :step="0.1"
                    placeholder="Enter threshold"
                  />
                </UFormField>
                <UFormField
                  name="triggerDuration"
                  label="For (seconds)"
                  required
                >
                  <UInputNumber
                    v-model="state.triggerDuration"
                    class="w-full"
                    :min="RULE_DURATION_SECONDS.min"
                    :max="RULE_DURATION_SECONDS.max"
                  />
                </UFormField>
              </div>

              <div class="grid gap-4 sm:grid-cols-3">
                <UFormField
                  name="clearOperator"
                  label="Clear when"
                  required
                >
                  <USelect
                    v-model="state.clearOperator"
                    :items="operatorItems(clearOperatorsFor(state.triggerOperator))"
                    class="w-full"
                  />
                </UFormField>
                <UFormField
                  name="clearValue"
                  label="Recovery threshold"
                  required
                >
                  <UInputNumber
                    v-model="state.clearValue"
                    class="w-full"
                    :step="0.1"
                    placeholder="Enter recovery threshold"
                  />
                </UFormField>
                <UFormField
                  name="clearDuration"
                  label="For (seconds)"
                  required
                >
                  <UInputNumber
                    v-model="state.clearDuration"
                    class="w-full"
                    :min="RULE_DURATION_SECONDS.min"
                    :max="RULE_DURATION_SECONDS.max"
                  />
                </UFormField>
              </div>

              <UFormField
                name="gap"
                label="Max sample gap (seconds)"
                required
              >
                <UInputNumber
                  v-model="state.gap"
                  class="w-full sm:w-48"
                  :min="RULE_GAP_SECONDS.min"
                  :max="RULE_GAP_SECONDS.max"
                />
              </UFormField>
            </div>
          </template>
        </UTabs>

        <UAlert
          v-if="isRevision"
          color="info"
          variant="subtle"
          icon="i-lucide-info"
          description="Saving creates a new immutable revision. Running windows restart; active alerts stay open until the new clear condition holds."
        />

        <div class="flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="subtle"
            :disabled="props.loading"
            @click="open = false"
          />
          <UButton
            :label="isRevision ? 'Create revision' : 'Create rule'"
            type="submit"
            :loading="props.loading"
          />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
