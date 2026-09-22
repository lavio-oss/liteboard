<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { useDateTime } from '~/composables/useDateTime'

defineProps<{
  collapsed?: boolean
}>()

const colorMode = useColorMode()
const { logout, user } = useAuth()
const { utcLabel } = useDateTime()
const toast = useToast()
const timezoneModalOpen = ref(false)

const authenticatedUser = computed(() => {
  if (user.value === null) {
    throw new Error('UserMenu requires an authenticated user.')
  }

  return user.value
})

function toggleColorMode(): void {
  colorMode.preference = colorMode.value === 'dark' ? 'light' : 'dark'
}

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

const items = computed<DropdownMenuItem[][]>(() => ([
  [{
    type: 'label',
    label: authenticatedUser.value.name
  }],
  [{
    label: 'Profile',
    icon: 'i-lucide-user',
    to: '/settings'
  }, {
    label: `Timezone (${utcLabel.value})`,
    icon: 'i-lucide-globe',
    onSelect(e: Event) {
      e.preventDefault()
      timezoneModalOpen.value = true
    }
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
    }, {
      label: 'System',
      icon: 'i-lucide-monitor',
      type: 'checkbox',
      checked: colorMode.preference === 'system',
      onSelect(e: Event) {
        e.preventDefault()
        colorMode.preference = 'system'
      }
    }]
  }],
  [{
    label: 'Log out',
    icon: 'i-lucide-log-out',
    onSelect: () => {
      void handleLogout()
    }
  }]
]))
</script>

<template>
  <div v-if="!collapsed" class="flex flex-col gap-1.5 w-full">
    <UDropdownMenu
      :items="items"
      :content="{ align: 'center', collisionPadding: 12 }"
      :ui="{ content: 'w-56' }"
    >
      <UButton
        :label="authenticatedUser.name"
        trailing-icon="i-lucide-chevrons-up-down"
        color="neutral"
        variant="ghost"
        block
        class="justify-between text-left data-[state=open]:bg-elevated"
        :ui="{
          trailingIcon: 'text-dimmed'
        }"
      />
    </UDropdownMenu>

    <div class="flex items-center gap-1.5 px-0.5">
      <UButton
        :label="utcLabel"
        icon="i-lucide-globe"
        color="neutral"
        variant="subtle"
        size="xs"
        class="flex-1 justify-center font-medium truncate"
        title="Change display timezone"
        @click="timezoneModalOpen = true"
      />

      <UButton
        :icon="colorMode.value === 'dark' ? 'i-lucide-moon' : 'i-lucide-sun'"
        color="neutral"
        variant="subtle"
        size="xs"
        :title="`Theme: ${colorMode.value === 'dark' ? 'Dark' : 'Light'} (Click to toggle)`"
        :aria-label="`Switch to ${colorMode.value === 'dark' ? 'light' : 'dark'} mode`"
        @click="toggleColorMode"
      />
    </div>
  </div>

  <div v-else class="flex flex-col items-center gap-1.5 w-full">
    <UDropdownMenu
      :items="items"
      :content="{ align: 'center', collisionPadding: 12 }"
      :ui="{ content: 'w-48' }"
    >
      <UButton
        icon="i-lucide-user"
        color="neutral"
        variant="ghost"
        square
        class="data-[state=open]:bg-elevated"
      />
    </UDropdownMenu>

    <UButton
      :icon="colorMode.value === 'dark' ? 'i-lucide-moon' : 'i-lucide-sun'"
      color="neutral"
      variant="ghost"
      square
      size="xs"
      :title="`Theme: ${colorMode.value === 'dark' ? 'Dark' : 'Light'} (Click to toggle)`"
      @click="toggleColorMode"
    />

    <UButton
      icon="i-lucide-globe"
      color="neutral"
      variant="ghost"
      square
      size="xs"
      :title="`Timezone: ${utcLabel}`"
      @click="timezoneModalOpen = true"
    />
  </div>

  <TimezoneModal v-model:open="timezoneModalOpen" />
</template>
