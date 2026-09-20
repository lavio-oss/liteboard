<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

defineProps<{
  collapsed?: boolean
}>()

const colorMode = useColorMode()
const { logout, user } = useAuth()
const toast = useToast()

const authenticatedUser = computed(() => {
  if (user.value === null) {
    throw new Error('UserMenu requires an authenticated user.')
  }

  return user.value
})

async function handleLogout(): Promise<void> {
  try {
    await logout()
    await navigateTo('/login')
  } catch {
    toast.add({
      title: 'Unable to sign out',
      description: 'Please try again.',
      color: 'error'
    })
  }
}

const items = computed<DropdownMenuItem[][]>(() => ([[{
  type: 'label',
  label: authenticatedUser.value.name
}], [{
  label: 'Profile',
  icon: 'i-lucide-user',
  to: '/settings'
}, {
  label: 'Appearance',
  icon: 'i-lucide-sun-moon',
  children: [{
    label: 'Light',
    icon: 'i-lucide-sun',
    type: 'checkbox',
    checked: colorMode.value === 'light',
    onSelect(e: Event) {
      e.preventDefault()

      colorMode.preference = 'light'
    }
  }, {
    label: 'Dark',
    icon: 'i-lucide-moon',
    type: 'checkbox',
    checked: colorMode.value === 'dark',
    onSelect(e: Event) {
      e.preventDefault()

      colorMode.preference = 'dark'
    }
  }]
}], [{
  label: 'Log out',
  icon: 'i-lucide-log-out',
  onSelect: () => {
    void handleLogout()
  }
}]]))
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-48' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      :label="collapsed ? undefined : authenticatedUser.name"
      :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :ui="{
        trailingIcon: 'text-dimmed'
      }"
    />
  </UDropdownMenu>
</template>
