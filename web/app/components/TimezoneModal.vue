<script setup lang="ts">
import { MAJOR_TIMEZONES, formatUtcOffset, useDateTime } from '~/composables/useDateTime'

const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const { timezone, setTimezone, detectBrowserTimezone } = useDateTime()
const toast = useToast()
const search = ref('')

const filteredTimezones = computed(() => {
  const query = search.value.trim().toLowerCase()
  if (!query) {
    return MAJOR_TIMEZONES
  }

  return MAJOR_TIMEZONES.filter((tz) => {
    const offsetLabel = formatUtcOffset(tz.value, tz.offset).toLowerCase()
    const labelMatch = tz.label.toLowerCase().includes(query)
    const valueMatch = tz.value.toLowerCase().includes(query)
    const citiesMatch = tz.cities?.toLowerCase().includes(query) ?? false
    const offsetMatch = tz.offset.includes(query) || offsetLabel.includes(query)

    return labelMatch || valueMatch || citiesMatch || offsetMatch
  })
})

function selectTimezone(val: string, offset?: string): void {
  setTimezone(val)
  emit('update:open', false)
  const label = formatUtcOffset(val, offset)
  toast.add({
    title: 'Timezone updated',
    description: `Display timezone set to ${label} (${val}).`,
    color: 'success',
    icon: 'i-lucide-check'
  })
}

function handleAutoDetect(): void {
  const detected = detectBrowserTimezone()
  setTimezone(detected)
  emit('update:open', false)
  const label = formatUtcOffset(detected)
  toast.add({
    title: 'Timezone detected',
    description: `Timezone set to ${label} (${detected}).`,
    color: 'success',
    icon: 'i-lucide-check'
  })
}
</script>

<template>
  <UModal
    :open="props.open"
    title="Select Timezone"
    @update:open="emit('update:open', $event)"
  >
    <template #body>
      <div class="space-y-3">
        <p class="text-sm text-muted">
          Choose your preferred display timezone. System timestamps remain strictly UTC+0.
        </p>

        <div class="flex items-center gap-2">
          <UInput
            v-model="search"
            icon="i-lucide-search"
            placeholder="Search timezone, city, or UTC offset (e.g. +7, Hanoi, Tokyo)..."
            class="flex-1"
            autofocus
          />
          <UButton
            icon="i-lucide-locate"
            label="Auto-detect"
            color="neutral"
            variant="outline"
            size="sm"
            title="Auto-detect from browser"
            @click="handleAutoDetect"
          />
        </div>

        <div class="max-h-80 overflow-y-auto divide-y divide-default rounded-lg border border-default">
          <button
            v-for="tz in filteredTimezones"
            :key="tz.value"
            type="button"
            class="flex w-full items-center justify-between px-3.5 py-2.5 text-left text-sm transition-colors hover:bg-elevated/60 cursor-pointer"
            :class="{ 'bg-elevated/40': timezone === tz.value }"
            @click="selectTimezone(tz.value, tz.offset)"
          >
            <div class="flex items-center gap-2.5 min-w-0">
              <UBadge
                :color="timezone === tz.value ? 'primary' : 'neutral'"
                variant="subtle"
                class="shrink-0 text-xs font-medium"
              >
                {{ formatUtcOffset(tz.value, tz.offset) }}
              </UBadge>
              <div class="min-w-0">
                <div class="font-medium text-highlighted truncate">
                  {{ tz.label }}
                </div>
                <div v-if="tz.cities" class="text-xs text-muted truncate">
                  {{ tz.cities }}
                </div>
              </div>
            </div>

            <UIcon
              v-if="timezone === tz.value"
              name="i-lucide-check"
              class="size-4 shrink-0 text-primary"
            />
          </button>

          <div
            v-if="filteredTimezones.length === 0"
            class="p-6 text-center text-sm text-muted"
          >
            No timezones found matching "{{ search }}".
          </div>
        </div>

        <div class="flex justify-end pt-1">
          <UButton
            label="Close"
            color="neutral"
            variant="subtle"
            @click="emit('update:open', false)"
          />
        </div>
      </div>
    </template>
  </UModal>
</template>
