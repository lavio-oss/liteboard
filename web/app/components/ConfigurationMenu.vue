<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

// The three screens that configure how telemetry is described and acted on.
const items: NavigationMenuItem[][] = [[
  { label: 'Device profiles', icon: 'i-lucide-file-cog', to: '/device-profiles' },
  { label: 'Alert rules', icon: 'i-lucide-list-checks', to: '/alert-rules' },
  { label: 'Webhooks', icon: 'i-lucide-webhook', to: '/webhooks' }
]]
</script>

<template>
  <UNavigationMenu
    :items="items"
    highlight
    class="-mx-1 flex-1"
  />
</template>
