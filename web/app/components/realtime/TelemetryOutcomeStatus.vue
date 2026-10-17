<script setup lang="ts">
import type { TelemetryIngestStatus } from '~/types/telemetry'
import { telemetryOutcomeStatusPresentation } from '~/utils/realtimeStatus'

const props = defineProps<{
  status: TelemetryIngestStatus
}>()

const presentation = computed(() => telemetryOutcomeStatusPresentation[props.status])
</script>

<template>
  <UBadge :color="presentation.color" variant="subtle" class="capitalize">
    <UIcon :name="presentation.icon" class="size-3.5" />
    {{ presentation.label }}
  </UBadge>
</template>
