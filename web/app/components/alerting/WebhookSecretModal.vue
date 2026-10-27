<script setup lang="ts">
const props = defineProps<{
  secret: string
}>()

const open = defineModel<boolean>('open', { required: true })
const { copy, copied } = useClipboard()
</script>

<template>
  <UModal
    v-model:open="open"
    title="Signing secret"
  >
    <template #body>
      <div class="space-y-4">
        <p class="text-sm text-muted">
          Copy it now. It is shown only once and cannot be retrieved later.
        </p>
        <UInput
          :model-value="props.secret"
          readonly
          class="w-full font-mono"
        />
        <p class="text-xs text-muted">
          Verify requests with HMAC-SHA256 over <code>{timestamp}.{body}</code> and compare with
          <code>X-LiteBoard-Signature: v1=…</code>.
        </p>
        <div class="flex justify-end gap-2">
          <UButton
            :label="copied ? 'Copied' : 'Copy'"
            :icon="copied ? 'i-lucide-check' : 'i-lucide-copy'"
            color="neutral"
            variant="outline"
            @click="copy(props.secret)"
          />
          <UButton label="Done" @click="open = false" />
        </div>
      </div>
    </template>
  </UModal>
</template>
