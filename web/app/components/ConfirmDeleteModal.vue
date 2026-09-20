<script setup lang="ts">
const props = withDefaults(defineProps<{
  open: boolean
  title: string
  description: string
  loading?: boolean
}>(), {
  loading: false
})

const emit = defineEmits<{
  'update:open': [value: boolean]
  'confirm': []
}>()
</script>

<template>
  <UModal
    :open="props.open"
    :title="props.title"
    :description="props.description"
    @update:open="emit('update:open', $event)"
  >
    <template #body>
      <div class="flex justify-end gap-2">
        <UButton
          label="Cancel"
          color="neutral"
          variant="subtle"
          :disabled="props.loading"
          @click="emit('update:open', false)"
        />
        <UButton
          label="Delete"
          color="error"
          variant="solid"
          :loading="props.loading"
          @click="emit('confirm')"
        />
      </div>
    </template>
  </UModal>
</template>
