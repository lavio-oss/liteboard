<script setup lang="ts">
import type { Overview } from '~/types/overview'

const props = defineProps<{
  buckets: Overview['ingest']['buckets']
}>()

// Status palette from the dataviz reference (good / critical) plus a neutral for duplicates and the rest.
// Each series also carries an icon and a label, so colour never carries the meaning alone.
const SERIES = [
  { key: 'persisted', label: 'Persisted', icon: 'i-lucide-check', color: '#0ca30c' },
  { key: 'rejected', label: 'Rejected', icon: 'i-lucide-x', color: '#d03b3b' },
  { key: 'other', label: 'Duplicate / other', icon: 'i-lucide-minus', color: '#94a3b8' }
] as const

const showTable = ref(false)
const peak = computed(() => Math.max(1, ...props.buckets.map(bucket => bucket.persisted + bucket.rejected + bucket.other)))
const hourOf = (start: string) => formatDateTime(start).slice(11, 16)
</script>

<template>
  <div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div class="flex flex-wrap items-center gap-3 text-xs text-muted">
        <span v-for="series in SERIES" :key="series.key" class="flex items-center gap-1.5">
          <span class="flex size-3.5 items-center justify-center rounded-sm text-white" :style="{ backgroundColor: series.color }">
            <UIcon :name="series.icon" class="size-2.5" />
          </span>
          {{ series.label }}
        </span>
      </div>
      <UButton
        :label="showTable ? 'Chart' : 'Table'"
        :icon="showTable ? 'i-lucide-chart-column' : 'i-lucide-table'"
        color="neutral"
        variant="ghost"
        size="xs"
        @click="showTable = !showTable"
      />
    </div>

    <div v-if="!showTable">
      <div class="flex h-40 items-end gap-0.5 border-b border-default">
        <UTooltip
          v-for="bucket in props.buckets"
          :key="bucket.start"
          :text="`${hourOf(bucket.start)} · ${bucket.persisted} persisted · ${bucket.rejected} rejected · ${bucket.other} other`"
        >
          <!-- The whole column is the hit target, larger than the stacked mark. -->
          <div class="flex h-full flex-1 cursor-default flex-col justify-end rounded-t hover:bg-elevated/60">
            <div class="flex flex-col-reverse gap-0.5 overflow-hidden rounded-t">
              <div
                v-for="series in SERIES"
                v-show="bucket[series.key] > 0"
                :key="series.key"
                :style="{ height: `${(bucket[series.key] / peak) * 152}px`, backgroundColor: series.color }"
              />
            </div>
          </div>
        </UTooltip>
      </div>
      <div class="mt-1 flex justify-between text-[11px] text-muted">
        <span v-for="(bucket, index) in props.buckets" v-show="index % 6 === 0 || index === props.buckets.length - 1" :key="bucket.start">
          {{ hourOf(bucket.start) }}
        </span>
      </div>
    </div>

    <div v-else class="max-h-56 overflow-y-auto rounded-md border border-default">
      <table class="w-full text-sm">
        <thead class="sticky top-0 bg-elevated text-left text-xs text-muted">
          <tr>
            <th class="px-3 py-1.5 font-medium">
              Hour
            </th>
            <th v-for="series in SERIES" :key="series.key" class="px-3 py-1.5 text-right font-medium">
              {{ series.label }}
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-default">
          <tr v-for="bucket in [...props.buckets].reverse()" :key="bucket.start">
            <td class="px-3 py-1.5 text-muted">
              {{ formatDateTime(bucket.start).slice(0, 16) }}
            </td>
            <td v-for="series in SERIES" :key="series.key" class="px-3 py-1.5 text-right tabular-nums">
              {{ bucket[series.key] }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
