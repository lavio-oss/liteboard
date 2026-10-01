<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

const open = ref(false)
const { isAdminWorkspace } = useWorkspaceContext()

const adminLinks = [{
  label: 'Dashboard',
  type: 'label' as const
}, {
  label: 'Home',
  icon: 'i-lucide-house',
  to: '/admin'
}, {
  label: 'IIoT Tree',
  icon: 'i-lucide-network',
  to: '/admin/iiot-tree'
}, {
  label: 'Masterdata',
  type: 'label' as const
}, {
  label: 'Tenants',
  icon: 'i-lucide-building-2',
  to: '/admin/tenants'
}, {
  label: 'Projects',
  icon: 'i-lucide-folder-kanban',
  to: '/admin/projects'
}, {
  label: 'Assets',
  icon: 'i-lucide-boxes',
  to: '/admin/assets'
}, {
  label: 'Users',
  icon: 'i-lucide-users',
  to: '/admin/users'
}, {
  label: 'Settings',
  to: '/settings',
  icon: 'i-lucide-settings',
  defaultOpen: true,
  type: 'trigger' as const,
  children: [{
    label: 'General',
    to: '/settings',
    exact: true
  }, {
    label: 'Security',
    to: '/settings/security'
  }]
}] satisfies NavigationMenuItem[]

const liteboardLinks = [{
  label: 'Dashboard',
  icon: 'i-lucide-layout-dashboard',
  to: '/'
}, {
  label: 'Assets',
  icon: 'i-lucide-boxes',
  to: '/assets'
}, {
  label: 'Devices',
  icon: 'i-lucide-router',
  to: '/devices'
}, {
  label: 'Settings',
  to: '/settings',
  icon: 'i-lucide-settings',
  defaultOpen: true,
  type: 'trigger' as const,
  children: [{
    label: 'General',
    to: '/settings',
    exact: true
  }, {
    label: 'Security',
    to: '/settings/security'
  }]
}] satisfies NavigationMenuItem[]

const links = computed<NavigationMenuItem[][]>(() => [
  isAdminWorkspace.value ? adminLinks : liteboardLinks
])

const groups = computed(() => [{
  id: 'links',
  label: 'Go to',
  items: links.value.flat().map(item => ({
    label: item.label,
    icon: item.icon,
    to: item.to
  }))
}, {
  id: 'code',
  label: 'Code',
  items: [{
    id: 'source',
    label: 'View page source',
    icon: 'i-simple-icons-github',
    to: 'https://github.com/LavioDev/LiteBoard',
    target: '_blank'
  }]
}])
</script>

<template>
  <UDashboardGroup unit="rem">
    <UDashboardSidebar
      id="default"
      v-model:open="open"
      collapsible
      resizable
      class="bg-elevated/25"
      :ui="{ footer: 'lg:border-t lg:border-default' }"
    >
      <template #header="{ collapsed }">
        <TeamsMenu :collapsed="collapsed" />
      </template>

      <template #default="{ collapsed }">
        <UDashboardSearchButton :collapsed="collapsed" class="bg-transparent ring-default" />

        <UNavigationMenu
          :collapsed="collapsed"
          :items="links[0]"
          orientation="vertical"
          tooltip
          popover
        />
      </template>

      <template #footer="{ collapsed }">
        <UserMenu :collapsed="collapsed" />
      </template>
    </UDashboardSidebar>

    <UDashboardSearch :groups="groups" />

    <slot />
  </UDashboardGroup>
</template>
