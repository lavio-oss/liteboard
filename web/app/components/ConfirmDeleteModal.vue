<script setup lang="ts">
const props = withDefaults(defineProps<{
  title: string
  description: string
  loading?: boolean
}>(), {
  loading: false
})

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  confirm: []
}>()
</script>

<template>
  <UModal
    v-model:open="open"
    :title="props.title"
  >
    <template #body>
      <p v-if="props.description" class="mb-4 text-sm text-muted">
        {{ props.description }}
      </p>
      <div class="flex justify-end gap-2">
        <UButton
          label="Cancel"
          color="neutral"
          variant="subtle"
          :disabled="props.loading"
          @click="open = false"
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
