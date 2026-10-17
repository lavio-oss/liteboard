<script setup lang="ts">
import { formatDateTime } from '~/composables/useDateTime'
import type { DeviceConnectionState } from '~/types/admin'
import { deviceConnectionStatePresentation } from '~/utils/realtimeStatus'

const props = withDefaults(defineProps<{
  state: DeviceConnectionState
  lastSeenAt: string | null
  compact?: boolean
}>(), {
  compact: false
})

const presentation = computed(() => deviceConnectionStatePresentation[props.state])
const lastSeenLabel = computed(() => props.lastSeenAt === null
  ? 'Never seen'
  : `Last seen ${formatDateTime(props.lastSeenAt)}`)
</script>

<template>
  <div class="flex min-w-0 items-center gap-2 whitespace-nowrap">
    <UBadge
      :color="presentation.color"
      variant="subtle"
      class="gap-1.5 capitalize"
      :title="lastSeenLabel"
    >
      <span aria-hidden="true" class="size-1.5 rounded-full bg-current" />
      {{ presentation.label }}
    </UBadge>
    <span v-if="!compact" class="text-xs text-muted">
      {{ lastSeenLabel }}
    </span>
  </div>
</template>
