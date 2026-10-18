<script setup lang="ts">
import type { AlertSeverity } from '~/types/alerting'

const props = defineProps<{ severity: AlertSeverity }>()

const COLORS = { critical: 'error', major: 'warning', minor: 'info', warning: 'neutral' } as const
</script>

<template>
  <UBadge
    :color="COLORS[props.severity]"
    variant="solid"
    class="capitalize"
    :label="props.severity"
  />
</template>
