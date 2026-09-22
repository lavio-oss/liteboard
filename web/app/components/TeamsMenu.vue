<script setup lang="ts">
defineProps<{
  collapsed?: boolean
}>()
</script>

<template>
  <div
    class="flex items-center gap-2 px-2 py-2.5"
    :class="collapsed ? 'justify-center' : 'justify-start'"
  >
    <UIcon
      name="i-lucide-cpu"
      class="size-5 shrink-0 text-primary"
    />
    <span
      v-if="!collapsed"
      class="truncate text-base font-semibold text-highlighted"
    >
      LiteBoard
    </span>
  </div>
</template>
