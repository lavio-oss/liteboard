<script setup lang="ts">
// `parent` ties an item to one of the parentItems; with parentItems the user picks the parent first.
type PickerItem = { label: string, value: string, description?: string, parent?: string }

const props = withDefaults(defineProps<{
  title: string
  description?: string
  confirmLabel?: string
  confirmIcon?: string
  items: PickerItem[]
  parentItems?: PickerItem[]
  parentLabel?: string
  itemLabel?: string
  defaultValue?: string
  canCreate: boolean
  loading?: boolean
}>(), {
  description: 'Select an existing one to show it on the canvas, or add a new one.',
  confirmLabel: 'Show on canvas',
  confirmIcon: 'i-lucide-locate-fixed',
  parentItems: undefined,
  parentLabel: 'Parent',
  itemLabel: 'Item',
  defaultValue: undefined,
  loading: false
})

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  pick: [value: string]
  create: []
}>()

const parent = ref<string | undefined>(undefined)
const selected = ref<string | undefined>(props.defaultValue)

const childItems = computed(() => props.parentItems === undefined
  ? props.items
  : props.items.filter(item => item.parent === parent.value))

watch(open, () => {
  parent.value = undefined
  selected.value = props.defaultValue
})

// Another parent invalidates the picked child.
watch(parent, () => {
  selected.value = undefined
})
</script>

<template>
  <UModal
    v-model:open="open"
    :title="props.title"
    :description="props.description"
  >
    <template #body>
      <div class="space-y-4">
        <UFormField v-if="props.parentItems !== undefined" :label="props.parentLabel">
          <USelectMenu
            v-model="parent"
            :items="props.parentItems"
            value-key="value"
            :placeholder="`Select ${props.parentLabel.toLowerCase()}`"
            class="w-full"
          />
        </UFormField>

        <UFormField v-if="props.parentItems === undefined || parent !== undefined" :label="props.parentItems === undefined ? undefined : props.itemLabel">
          <div class="flex gap-2">
            <USelectMenu
              v-model="selected"
              :items="childItems"
              value-key="value"
              :placeholder="`Select ${props.parentItems === undefined ? 'one' : props.itemLabel.toLowerCase()}`"
              class="flex-1"
            />
            <UButton
              v-if="props.canCreate && props.parentItems === undefined"
              label="Add new"
              icon="i-lucide-plus"
              color="neutral"
              variant="outline"
              @click="emit('create')"
            />
          </div>
        </UFormField>

        <div class="flex items-center justify-between gap-2">
          <UButton
            v-if="props.canCreate && props.parentItems !== undefined"
            label="Add new"
            icon="i-lucide-plus"
            color="neutral"
            variant="ghost"
            @click="emit('create')"
          />
          <span v-else />
          <div class="flex gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="subtle"
              :disabled="props.loading"
              @click="open = false"
            />
            <UButton
              :label="props.confirmLabel"
              :icon="props.confirmIcon"
              :loading="props.loading"
              :disabled="selected === undefined"
              @click="selected !== undefined && emit('pick', selected)"
            />
          </div>
        </div>
      </div>
    </template>
  </UModal>
</template>
