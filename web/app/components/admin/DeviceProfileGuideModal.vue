<script setup lang="ts">
const open = defineModel<boolean>('open', { required: true })

const tabs = [
  { label: 'Required fields', value: 'required', slot: 'required' as const },
  { label: 'Entering values', value: 'values', slot: 'values' as const }
]

const topic = 'liteboard/<project_id>/devices/<device_id>/telemetry'
const message = JSON.stringify({
  message_id: 'c0a8f2e4-0001',
  schema_version: 1,
  occurred_at: '2026-10-17T08:30:00Z',
  values: { temperature: 36.5, status: 'running', alarm: false }
}, null, 2)
const schema = JSON.stringify({
  temperature: { type: 'float', unit: '°C', required: true, min: -20, max: 100 },
  status: { type: 'string', unit: 'state', required: false, allowed_values: ['idle', 'running', 'fault'] },
  alarm: { type: 'boolean', unit: 'flag', required: false }
}, null, 2)
</script>

<template>
  <UModal
    v-model:open="open"
    title="Schema guide"
    :ui="{ content: 'max-w-3xl' }"
  >
    <template #body>
      <UTabs
        :items="tabs"
        default-value="required"
        variant="link"
        :ui="{ list: 'w-full border-default p-0', trigger: 'flex-1 justify-center rounded-none py-3', content: 'pt-3' }"
        class="w-full"
      >
        <template #required>
          <div class="flex flex-col gap-3 text-sm select-text">
            <p class="text-muted">
              Each telemetry message is a JSON object (up to 64 KiB) published to the device's telemetry topic:
            </p>
            <pre class="overflow-auto rounded-lg border border-default bg-elevated/50 p-3 font-mono text-xs">{{ topic }}</pre>
            <p class="text-muted">
              For a device with an assigned profile version, all four keys below are required:
            </p>
            <ul class="list-disc space-y-1 pl-5 text-muted">
              <li><code>message_id</code>: non-empty string, up to 128 characters. It is not generated for you. Resending identical content is a duplicate; changed content under the same ID is rejected.</li>
              <li><code>schema_version</code>: positive integer equal to the version number currently assigned to the device.</li>
              <li><code>occurred_at</code>: ISO 8601 timestamp with timezone, at most 5 minutes in the future (late data is accepted).</li>
              <li><code>values</code>: object of metrics, described by the schema (see the next tab).</li>
            </ul>
            <pre class="overflow-auto rounded-lg border border-default bg-elevated/50 p-3 font-mono text-xs">{{ message }}</pre>
          </div>
        </template>

        <template #values>
          <div class="flex flex-col gap-4 text-sm select-text">
            <p class="text-muted">
              The schema is a JSON object. Each key is a metric name; its value describes that metric.
            </p>

            <div>
              <h4 class="mb-1 font-semibold text-highlighted">
                Field attributes
              </h4>
              <ul class="list-disc space-y-1 pl-5 text-muted">
                <li><code>type</code> (required): <code>integer</code>, <code>float</code>, <code>string</code> or <code>boolean</code>. <code>integer</code> rejects decimals, <code>float</code> accepts any finite number, booleans are only valid for <code>boolean</code>.</li>
                <li><code>unit</code> (required): display unit, 1–64 characters, e.g. <code>°C</code>.</li>
                <li><code>required</code> (required): <code>true</code> if every message must include this field.</li>
                <li><code>min</code> / <code>max</code> (optional): numeric fields only; <code>max</code> must be ≥ <code>min</code>.</li>
                <li><code>allowed_values</code> (optional): string fields only; 1–100 unique values without leading/trailing spaces.</li>
              </ul>
            </div>

            <div>
              <h4 class="mb-1 font-semibold text-highlighted">
                Rules
              </h4>
              <ul class="list-disc space-y-1 pl-5 text-muted">
                <li>At least one field is required.</li>
                <li>Field names start with a letter and use only letters, numbers and underscores.</li>
                <li>Strict mode (set when creating the version) rejects telemetry containing fields not listed here.</li>
              </ul>
            </div>

            <div>
              <h4 class="mb-1 font-semibold text-highlighted">
                Example schema
              </h4>
              <pre class="overflow-auto rounded-lg border border-default bg-elevated/50 p-3 font-mono text-xs">{{ schema }}</pre>
            </div>
          </div>
        </template>
      </UTabs>
    </template>
  </UModal>
</template>
