<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { formatDateTime } from '~/composables/useDateTime'
import { deviceProfileVersionDefinitionSchema, deviceProfileVersionInputSchema } from '~/types/admin'
import type { DeviceProfile, DeviceProfileVersion, DeviceProfileVersionInput } from '~/types/admin'

type DrawerView = 'versions' | 'form'

const props = withDefaults(defineProps<{
  profile?: DeviceProfile | null
  canWrite?: boolean
  creating?: boolean
}>(), {
  profile: null,
  canWrite: false,
  creating: false
})

const open = defineModel<boolean>('open', { required: true })
const view = defineModel<DrawerView>('view', { required: true })

const emit = defineEmits<{
  create: [payload: DeviceProfileVersionInput]
}>()

const versionSchema = z.object({
  schema_code: z.string().min(1, 'Schema code is required.').max(64, 'Schema code cannot exceed 64 characters.'),
  compatibility_note: z.string().max(1000, 'Compatibility note cannot exceed 1000 characters.'),
  definition: z.string().min(1, 'Schema definition is required.').transform((text, ctx) => {
    let parsed: unknown

    try {
      parsed = JSON.parse(text)
    } catch (error: unknown) {
      ctx.addIssue({ code: 'custom', message: error instanceof SyntaxError ? `Invalid JSON: ${error.message}` : 'Schema definition must be valid JSON.' })

      return z.NEVER
    }

    const result = deviceProfileVersionDefinitionSchema.safeParse(parsed)

    if (!result.success) {
      ctx.addIssue({
        code: 'custom',
        message: result.error.issues.map((issue) => {
          const path = issue.path.map(part => typeof part === 'number' ? `[${part}]` : String(part)).join('.')

          return path === '' ? issue.message : `${path}: ${issue.message}`
        }).join('\n')
      })

      return z.NEVER
    }

    return result.data
  })
})

type VersionFormState = z.input<typeof versionSchema>

const form = useTemplateRef('form')
const state = reactive<VersionFormState>({
  schema_code: '',
  compatibility_note: '',
  definition: ''
})

const readonly = computed(() => !props.canWrite || props.creating)
const orderedVersions = computed(() => [...(props.profile?.versions ?? [])].reverse())
const description = computed(() => {
  if (props.profile === null) {
    return 'Device profile versions'
  }

  return view.value === 'versions'
    ? `${props.profile.versions.length} versions`
    : `${props.profile.code} · Version schema`
})

interface PreviewField {
  name: string
  type: string
  unit?: string
}

function getPreviewFields(
  fields: Record<string, { type: string; unit?: string }> | undefined,
  limit = 5
): PreviewField[] {
  if (!fields) {
    return []
  }

  return Object.entries(fields)
    .slice(0, limit)
    .map(([name, config]) => ({
      name,
      type: config.type,
      unit: config.unit
    }))
}

function getRemainingFieldCount(
  fields: Record<string, unknown> | undefined,
  limit = 5
): number {
  if (!fields) {
    return 0
  }

  const total = Object.keys(fields).length

  return Math.max(0, total - limit)
}

watch(
  () => [open.value, props.profile?.id] as const,
  ([isOpen]) => {
    if (isOpen) {
      view.value = 'versions'
    }
  }
)

function openForm(version: DeviceProfileVersion | null): void {
  state.schema_code = version === null || props.canWrite ? '' : version.schema_code
  state.compatibility_note = version?.compatibility_note ?? ''
  state.definition = version === null ? '' : JSON.stringify(version.definition, null, 2)
  view.value = 'form'
}

function onSubmit(event: FormSubmitEvent<z.output<typeof versionSchema>>): void {
  emit('create', deviceProfileVersionInputSchema.parse({
    ...event.data,
    compatibility_note: event.data.compatibility_note === '' ? null : event.data.compatibility_note
  }))
}
</script>

<template>
  <UDrawer
    v-model:open="open"
    direction="right"
    :handle="false"
    handle-only
    close
    :description="description"
    :ui="{
      content: 'w-full sm:max-w-3xl select-text',
      container: 'flex h-full flex-col select-text',
      body: 'flex-1 min-h-0 overflow-y-auto p-4 sm:p-6 select-text',
      footer: 'flex w-full items-center justify-between border-t border-default p-4 sm:p-6'
    }"
  >
    <template #title>
      <div class="flex items-center gap-2.5">
        <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
          <UIcon name="i-lucide-layers" class="size-4.5" />
        </div>
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2">
            <span class="truncate font-semibold">{{ props.profile?.name ?? 'Device profile versions' }}</span>
            <UBadge
              v-if="props.profile?.code"
              color="neutral"
              variant="subtle"
              class="font-mono text-xs"
            >
              {{ props.profile.code }}
            </UBadge>
          </div>
        </div>
      </div>
    </template>



    <template #body>
      <template v-if="view === 'versions'">
        <UEmpty
          v-if="orderedVersions.length === 0"
          icon="i-lucide-layers"
          title="No versions yet"
          description="Create the first version schema for this profile."
          variant="naked"
        >
          <template #actions>
            <UButton
              v-if="props.canWrite"
              label="Create version"
              icon="i-lucide-plus"
              color="primary"
              @click="openForm(null)"
            />
          </template>
        </UEmpty>

        <div v-else class="flex flex-col gap-3">
          <div
            v-for="version in orderedVersions"
            :key="version.id"
            role="button"
            tabindex="0"
            class="group relative flex flex-col gap-3 rounded-xl border border-default bg-card/60 p-4 text-left transition-all duration-200 hover:border-primary/50 hover:bg-elevated/40 hover:shadow-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-primary select-text cursor-pointer"
            @click="openForm(version)"
            @keydown.enter.prevent="openForm(version)"
            @keydown.space.prevent="openForm(version)"
          >
            <!-- Card Header -->
            <div class="flex items-start justify-between gap-3">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-sm font-semibold text-highlighted group-hover:text-primary transition-colors">
                  {{ version.schema_code }}
                </span>

                <UBadge
                  v-if="version.definition?.strict_mode"
                  color="neutral"
                  variant="outline"
                  size="xs"
                  class="gap-1 text-[11px]"
                >
                  <UIcon name="i-lucide-shield-check" class="size-3 text-success" />
                  Strict mode
                </UBadge>
              </div>

              <div class="flex shrink-0 items-center gap-1 text-xs text-dimmed group-hover:text-primary transition-colors">
                <span class="font-medium">Inspect</span>
                <UIcon
                  name="i-lucide-chevron-right"
                  class="size-4 transition-transform group-hover:translate-x-0.5"
                />
              </div>
            </div>

            <!-- Schema Fields Preview -->
            <div class="space-y-1.5">
              <div class="flex items-center justify-between text-xs text-dimmed">
                <span class="flex items-center gap-1 font-medium">
                  <UIcon name="i-lucide-list-tree" class="size-3.5" />
                  Schema fields ({{ Object.keys(version.definition?.fields ?? {}).length }})
                </span>
              </div>

              <div class="flex flex-wrap gap-1.5">
                <span
                  v-for="field in getPreviewFields(version.definition?.fields)"
                  :key="field.name"
                  class="inline-flex items-center gap-1 rounded-md border border-default/60 bg-elevated/60 px-2 py-0.5 font-mono text-[11px] text-muted"
                >
                  <span class="font-medium text-highlighted">{{ field.name }}</span>
                  <span class="text-dimmed text-[10px]">{{ field.unit ? `${field.type} · ${field.unit}` : field.type }}</span>
                </span>

                <span
                  v-if="getRemainingFieldCount(version.definition?.fields) > 0"
                  class="inline-flex items-center rounded-md px-1.5 py-0.5 font-mono text-[11px] font-medium text-dimmed"
                >
                  +{{ getRemainingFieldCount(version.definition?.fields) }} more
                </span>
              </div>
            </div>

            <!-- Card Footer -->
            <div class="flex items-center justify-between border-t border-default/40 pt-2 text-xs text-dimmed">
              <div class="flex items-center gap-1.5">
                <UIcon name="i-lucide-calendar" class="size-3.5" />
                <span>{{ formatDateTime(version.created_at) }}</span>
              </div>

              <span class="text-xs font-medium text-primary opacity-0 transition-opacity group-hover:opacity-100 flex items-center gap-1">
                Click to view schema
                <UIcon name="i-lucide-arrow-right" class="size-3" />
              </span>
            </div>
          </div>
        </div>
      </template>

      <UForm
        v-else
        ref="form"
        :schema="versionSchema"
        :state="state"
        class="space-y-5"
        @submit="onSubmit"
      >
        <UFormField
          name="schema_code"
          label="Schema code"
          required
          description="Must be unique within the project."
        >
          <UInput
            v-model="state.schema_code"
            class="w-full"
            :readonly="readonly"
            maxlength="64"
            placeholder="e.g. temperature_v2"
          />
        </UFormField>

        <UFormField name="compatibility_note" label="Compatibility note">
          <UTextarea
            v-model="state.compatibility_note"
            class="w-full"
            :readonly="readonly"
            :rows="3"
            maxlength="1000"
            placeholder="Describe compatibility with previous versions"
          />
        </UFormField>

        <UFormField
          name="definition"
          label="Schema definition (JSON)"
          required
          description="Use strict_mode and fields. Each field needs type, unit, and required."
          :ui="{ error: 'whitespace-pre-line' }"
        >
          <UTextarea
            v-model="state.definition"
            class="w-full"
            :ui="{ base: 'font-mono text-sm' }"
            :readonly="readonly"
            :rows="22"
            placeholder="{&#10;  &quot;strict_mode&quot;: true,&#10;  &quot;fields&quot;: {&#10;    &quot;temperature&quot;: {&#10;      &quot;type&quot;: &quot;float&quot;,&#10;      &quot;unit&quot;: &quot;°C&quot;,&#10;      &quot;required&quot;: true,&#10;      &quot;min&quot;: -20,&#10;      &quot;max&quot;: 100&#10;    }&#10;  }&#10;}"
          />
        </UFormField>
      </UForm>
    </template>

    <template #footer>
      <div v-if="view === 'versions'" class="flex w-full items-center justify-between gap-4">
        <UButton
          label="Close"
          color="neutral"
          variant="subtle"
          @click="open = false"
        />

        <UButton
          v-if="props.canWrite"
          label="Create version"
          icon="i-lucide-plus"
          color="primary"
          @click="openForm(null)"
        />
      </div>

      <div v-else class="flex w-full items-center justify-between gap-4">
        <UButton
          label="Back to versions"
          icon="i-lucide-arrow-left"
          color="neutral"
          variant="ghost"
          :disabled="props.creating"
          @click="view = 'versions'"
        />

        <div class="flex items-center gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="subtle"
            :disabled="props.creating"
            @click="view = 'versions'"
          />
          <UButton
            v-if="props.canWrite"
            label="Create version"
            icon="i-lucide-plus"
            color="primary"
            :loading="props.creating"
            @click="form?.submit()"
          />
        </div>
      </div>
    </template>
  </UDrawer>
</template>
