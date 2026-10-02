<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

defineProps<{
  collapsed?: boolean
}>()

const { isAdminWorkspace, selectWorkspace } = useWorkspaceContext()
const brandLogo = useBrandLogo()

const workspaces = [{
  label: 'Liteboard',
  icon: 'i-lucide-cpu',
  to: '/'
}, {
  label: 'LiteBoard Admin',
  icon: 'i-lucide-shield-check',
  to: '/admin'
}] as const

const selectedWorkspace = computed(() => isAdminWorkspace.value ? workspaces[1] : workspaces[0])

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
      :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :class="[!collapsed && 'py-2']"
      :ui="{
        trailingIcon: 'text-dimmed'
      }"
    >
      <template #leading>
        <span class="grid size-6 shrink-0 place-items-center rounded-md bg-primary/10">
          <img :src="brandLogo" alt="" class="size-4">
        </span>
      </template>
    </UButton>
  </UDropdownMenu>
</template>
