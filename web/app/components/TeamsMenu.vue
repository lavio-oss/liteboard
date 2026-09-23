<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

defineProps<{
  collapsed?: boolean
}>()

const { isAdminWorkspace, selectWorkspace } = useWorkspaceContext()

const workspaces = [{
  label: 'LiteBoard Admin',
  icon: 'i-lucide-shield-check',
  to: '/admin'
}, {
  label: 'Liteboard',
  icon: 'i-lucide-cpu',
  to: '/'
}] as const

const selectedWorkspace = computed(() => isAdminWorkspace.value ? workspaces[0] : workspaces[1])

const items = computed<DropdownMenuItem[][]>(() => [workspaces.map(workspace => ({
  label: workspace.label,
  icon: workspace.icon,
  onSelect: () => {
    selectWorkspace(workspace.to === '/admin' ? 'admin' : 'liteboard')
    void navigateTo(workspace.to)
  }
}))])
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-52' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      :label="collapsed ? undefined : selectedWorkspace.label"
      :icon="selectedWorkspace.icon"
      :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :class="[!collapsed && 'py-2']"
      :ui="{
        leadingIcon: 'size-5 text-primary',
        trailingIcon: 'text-dimmed'
      }"
    />
  </UDropdownMenu>
</template>
