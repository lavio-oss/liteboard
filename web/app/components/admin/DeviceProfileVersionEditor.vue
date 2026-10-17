<script setup lang="ts">
import { deviceProfileVersionInputSchema } from '~/types/admin'
import type { DeviceProfileVersion, DeviceProfileVersionInput } from '~/types/admin'

const props = defineProps<{
  base: DeviceProfileVersion | null
  meta: Pick<DeviceProfileVersionInput, 'schema_code' | 'compatibility_note'> & { strict_mode: boolean }
  canWrite: boolean
  creating: boolean
  draftText?: string | null
}>()

const emit = defineEmits<{
  create: [payload: DeviceProfileVersionInput]
  saveDraft: [text: string]
}>()

const starterFields = { temperature: { type: 'float', unit: '°C', required: true } }
const text = ref(props.draftText ?? JSON.stringify({
  message_id: 'string, up to 128 characters',
  schema_version: props.base?.version ?? 'next version number',
  occurred_at: 'ISO 8601 timestamp with timezone',
  values: props.base?.definition.fields ?? starterFields
}, null, 2))
const description = computed(() => props.canWrite
  ? 'Define metrics under values. Other keys are ignored when saving.'
  : 'Saved versions are read-only. Use New version from this version to edit a copy.')
const readonly = computed(() => !props.canWrite || props.creating)

const result = computed(() => {
  let fields: unknown

  try {
    const parsed: unknown = JSON.parse(text.value)

    fields = typeof parsed === 'object' && parsed !== null && 'values' in parsed ? parsed.values : undefined
  } catch (error: unknown) {
    return { errors: [error instanceof SyntaxError ? `Invalid JSON: ${error.message}` : 'Fields must be valid JSON.'] }
  }

  const parsed = deviceProfileVersionInputSchema.safeParse({
    schema_code: props.meta.schema_code,
    compatibility_note: props.meta.compatibility_note,
    definition: { strict_mode: props.meta.strict_mode, fields }
  })

  return parsed.success
    ? { payload: parsed.data, errors: [] }
    : {
        errors: parsed.error.issues.map((issue) => {
          const path = issue.path.map(String).filter(part => part !== 'definition').join(' › ')

          return path === '' ? issue.message : `${path}: ${issue.message}`
        })
      }
})

function submit(): void {
  if (result.value.payload !== undefined) {
    emit('create', result.value.payload)
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-col gap-1">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-mono text-sm font-semibold text-highlighted">{{ meta.schema_code }}</span>
          <UBadge
            v-if="meta.strict_mode"
            color="neutral"
            variant="outline"
            size="xs"
            icon="i-lucide-shield-check"
          >
            Strict mode
          </UBadge>
        </div>

        <slot name="actions" />
      </div>
      <p v-if="meta.compatibility_note" class="text-sm text-muted">
        {{ meta.compatibility_note }}
      </p>
    </div>

    <UFormField
      label="Schema (JSON)"
      :description="description"
    >
      <AdminCodeEditor
        v-model="text"
        :readonly="readonly"
        :rows="22"
        @save="canWrite && emit('saveDraft', text)"
      />
    </UFormField>

    <UAlert
      v-if="canWrite && result.errors.length > 0"
      color="warning"
      variant="subtle"
      title="Fix the following before saving"
    >
      <template #description>
        <ul class="list-disc pl-4">
          <li v-for="message in result.errors" :key="message">
            {{ message }}
          </li>
        </ul>
      </template>
    </UAlert>

    <div v-if="canWrite" class="flex justify-end border-t border-default pt-4">
      <UButton
        label="Create version"
        icon="i-lucide-save"
        :loading="creating"
        :disabled="result.errors.length > 0"
        @click="submit"
      />
    </div>
  </div>
</template>
