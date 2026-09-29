<script setup lang="ts">
import type { Asset, AssetType } from '~/types/admin'

const props = withDefaults(defineProps<{
  asset: Asset | null
  projectName?: string
  parentName?: string
  canEdit?: boolean
}>(), {
  projectName: undefined,
  parentName: undefined,
  canEdit: false
})

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  edit: [asset: Asset]
}>()

const assetTypeLabels: Record<AssetType, string> = {
  site: 'Site',
  factory: 'Factory',
  area: 'Area',
  production_line: 'Production Line',
  station: 'Station',
  equipment: 'Equipment'
}

const assetTypeColors: Record<AssetType, 'success' | 'secondary' | 'primary' | 'warning' | 'neutral' | 'error'> = {
  site: 'success',
  factory: 'secondary',
  area: 'primary',
  production_line: 'warning',
  station: 'neutral',
  equipment: 'error'
}
</script>

<template>
  <UModal
    v-model:open="open"
    :ui="{ content: 'max-w-lg' }"
  >
    <template #title>
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-box" class="size-5 text-primary" />
        <span>{{ props.asset?.name ?? 'Asset details' }}</span>
        <UBadge
          v-if="props.asset !== null"
          class="capitalize"
          :color="props.asset.status === 'active' ? 'success' : 'neutral'"
          variant="subtle"
        >
          {{ props.asset.status }}
        </UBadge>
      </div>
    </template>

    <template #body>
      <div v-if="props.asset !== null" class="space-y-4">
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div class="space-y-1">
            <span class="text-xs text-muted">Name</span>
            <p class="font-medium text-highlighted">{{ props.asset.name }}</p>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Code</span>
            <p class="text-highlighted">{{ props.asset.code }}</p>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Asset type</span>
            <div>
              <UBadge
                :color="assetTypeColors[props.asset.asset_type]"
                variant="subtle"
                class="capitalize"
              >
                {{ assetTypeLabels[props.asset.asset_type] }}
              </UBadge>
            </div>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Project</span>
            <p class="text-highlighted">{{ props.projectName ?? '—' }}</p>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Parent asset</span>
            <p class="text-highlighted">{{ props.parentName ?? 'Root' }}</p>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Location</span>
            <p class="text-highlighted">{{ props.asset.location || '—' }}</p>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Created at</span>
            <p class="text-highlighted">{{ props.asset.created_at ? formatDateTime(props.asset.created_at) : '—' }}</p>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Updated at</span>
            <p class="text-highlighted">{{ props.asset.updated_at ? formatDateTime(props.asset.updated_at) : '—' }}</p>
          </div>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between">
        <div v-if="props.canEdit && props.asset !== null">
          <UButton
            label="Edit asset"
            icon="i-lucide-pencil"
            color="neutral"
            variant="outline"
            size="sm"
            @click="emit('edit', props.asset); open = false"
          />
        </div>
        <div v-else />

        <UButton
          label="Close"
          color="neutral"
          variant="subtle"
          @click="open = false"
        />
      </div>
    </template>
  </UModal>
</template>
