<script setup lang="ts">
definePageMeta({
  middleware: 'auth'
})
</script>

<template>
  <UDashboardPanel id="liteboard-assets" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Assets">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex min-h-96 items-center justify-center rounded-lg border border-dashed border-default p-8 text-center text-sm text-muted">
        Liteboard Assets is not configured yet.
      </div>
    </template>
  </UDashboardPanel>
</template>
