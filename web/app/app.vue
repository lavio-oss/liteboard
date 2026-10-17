<script setup lang="ts">
const colorMode = useColorMode()
const { isLoading } = useLoadingIndicator({ throttle: 200 })
const { isScreenLoading } = useScreenLoading()
const brandLogo = useBrandLogo()

const color = computed(() => colorMode.value === 'dark' ? '#1b1718' : 'white')

useHead({
  meta: [
    { charset: 'utf-8' },
    { name: 'viewport', content: 'width=device-width, initial-scale=1' },
    { key: 'theme-color', name: 'theme-color', content: color }
  ],
  link: [
    { rel: 'icon', type: 'image/svg+xml', href: brandLogo }
  ],
  htmlAttrs: {
    lang: 'en'
  }
})

const title = 'LiteBoard'
const description = 'LiteBoard asset and master data management platform.'

useSeoMeta({
  title,
  description,
  ogTitle: title,
  ogDescription: description,
  twitterCard: 'summary_large_image'
})
</script>

<template>
  <UApp>
    <UTheme :props="{ drawer: { handle: false, handleOnly: true, dismissible: false } }">
      <Transition
        enter-active-class="transition-opacity duration-150"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="isLoading || isScreenLoading"
          role="status"
          aria-live="polite"
          class="fixed inset-0 z-[100] flex flex-col items-center justify-center gap-3 bg-default/70 backdrop-blur-sm"
        >
          <UIcon name="i-lucide-loader-circle" class="size-8 animate-spin text-primary motion-reduce:animate-none" />
          <span class="text-sm font-medium text-highlighted">Loading</span>
        </div>
      </Transition>

      <NuxtLayout>
        <NuxtPage />
      </NuxtLayout>
    </UTheme>
  </UApp>
</template>
