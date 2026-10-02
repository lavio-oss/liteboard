<script setup lang="ts">
withDefaults(defineProps<{
  loading?: boolean
}>(), {
  loading: false
})
</script>

<template>
  <UButton
    icon="i-lucide-rotate-ccw"
    color="neutral"
    variant="ghost"
    aria-label="Reload"
    title="Reload"
    :loading="loading"
  />
</template>
