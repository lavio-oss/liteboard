<script setup lang="ts">
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useTenantContext } from '~/composables/useTenantContext'
import { formatDateTime } from '~/composables/useDateTime'
import { canWriteProjectData } from '~/utils/projectPermissions'
import * as z from 'zod'
import type { DeviceProfileVersion, DeviceProfileVersionInput } from '~/types/admin'

definePageMeta({
  middleware: 'auth'
})

const route = useRoute()
const toast = useToast()
const { activeTenantId, canManageActiveTenant, loadTenants } = useTenantContext()
const { createDeviceProfileVersion, loadDeviceProfiles, loadProjects } = useAdmin()
await loadTenants()

const projectId = String(route.params.projectId)
const profileId = String(route.params.profileId)

const { data, status, error, refresh } = await useAsyncData(
  `device-profile-${profileId}`,
  async () => {
    const tenantId = activeTenantId.value

    if (tenantId === null) {
      return null
    }

    const [projects, profiles] = await Promise.all([
      loadProjects(tenantId),
      loadDeviceProfiles(tenantId, projectId)
    ])

    return {
      tenantId,
      project: projects.find(project => project.id === projectId) ?? null,
      profile: profiles.find(profile => profile.id === profileId) ?? null
    }
  },
  { watch: [activeTenantId] }
)

const profile = computed(() => data.value?.profile ?? null)
const canWrite = computed(() => data.value?.project != null
  && canWriteProjectData(data.value.project.role, canManageActiveTenant.value))
const versions = computed(() => [...(profile.value?.versions ?? [])].reverse())

const versionSearch = ref('')
const filteredVersions = computed(() => {
  const term = versionSearch.value.trim().toLowerCase()

  return versions.value.filter(version => term === ''
    || `v${version.version}`.includes(term)
    || version.schema_code.toLowerCase().includes(term))
})

const selectedId = ref<string | 'new' | null>(null)
const selectedVersion = computed(() => versions.value.find(version => version.id === selectedId.value) ?? null)
const activeId = computed(() => selectedId.value === 'new' && draft.value !== null
  ? 'new'
  : viewedVersion.value?.id ?? null)
const creating = ref(false)

const metaSchema = z.object({
  schema_code: z.string().trim().min(1, 'Schema code is required.').max(64, 'Schema code cannot exceed 64 characters.'),
  compatibility_note: z.string().max(1000, 'Compatibility note cannot exceed 1000 characters.'),
  strict_mode: z.boolean()
})

const guideOpen = ref(false)
const metaOpen = ref(false)
const metaState = reactive({ schema_code: '', compatibility_note: '', strict_mode: true })
const draft = ref<{
  base: DeviceProfileVersion | null
  meta: { schema_code: string, compatibility_note: string | null, strict_mode: boolean }
  text: string | null
} | null>(null)
const pendingBase = ref<DeviceProfileVersion | null>(null)
const draftSeq = ref(0)

const draftStorageKey = computed(() => `liteboard:schema-draft:${data.value?.tenantId}:${profileId}`)

function clearStoredDraft(): void {
  try {
    localStorage.removeItem(draftStorageKey.value)
  } catch {
    // Draft persistence is a convenience; ignore blocked storage.
  }
}

function saveDraftText(text: string): void {
  if (draft.value === null) {
    return
  }

  draft.value.text = text

  try {
    localStorage.setItem(draftStorageKey.value, JSON.stringify({
      baseId: draft.value.base?.id ?? null,
      meta: draft.value.meta,
      text
    }))
    toast.add({ title: 'Draft saved', description: 'Kept in this browser until you create the version.', color: 'success' })
  } catch {
    toast.add({ title: 'Unable to save draft', description: 'Browser storage is unavailable.', color: 'error' })
  }
}

onMounted(() => {
  try {
    const stored = localStorage.getItem(draftStorageKey.value)

    if (stored === null) {
      return
    }

    const parsed = z.object({
      baseId: z.string().nullable(),
      meta: metaSchema.extend({ compatibility_note: z.string().nullable() }),
      text: z.string()
    }).safeParse(JSON.parse(stored))

    if (parsed.success) {
      draft.value = {
        base: versions.value.find(version => version.id === parsed.data.baseId) ?? null,
        meta: parsed.data.meta,
        text: parsed.data.text
      }
      draftSeq.value++
      selectedId.value = 'new'
    }
  } catch {
    clearStoredDraft()
  }
})

function openNew(base: DeviceProfileVersion | null): void {
  pendingBase.value = base
  metaState.schema_code = ''
  metaState.compatibility_note = base?.compatibility_note ?? ''
  metaState.strict_mode = base?.definition.strict_mode ?? true
  metaOpen.value = true
}

function confirmMeta(event: { data: z.output<typeof metaSchema> }): void {
  draft.value = {
    base: pendingBase.value,
    meta: {
      ...event.data,
      compatibility_note: event.data.compatibility_note === '' ? null : event.data.compatibility_note
    },
    text: null
  }
  clearStoredDraft()
  draftSeq.value++
  selectedId.value = 'new'
  metaOpen.value = false
}

function discardDraft(): void {
  draft.value = null
  clearStoredDraft()

  if (selectedId.value === 'new') {
    selectedId.value = null
  }
}

const viewedVersion = computed(() => selectedVersion.value ?? versions.value[0] ?? null)

const newVersionItems = computed(() => [[
  { label: 'New version', icon: 'i-lucide-plus', onSelect: () => openNew(null) },
  ...(viewedVersion.value === null
    ? []
    : [{
        label: `New version from v${viewedVersion.value.version}`,
        icon: 'i-lucide-copy-plus',
        onSelect: () => openNew(viewedVersion.value)
      }])
]])

watch(activeTenantId, async (tenantId, previous) => {
  if (previous !== null && tenantId !== previous) {
    await navigateTo('/device-profiles')
  }
})

async function createVersion(payload: DeviceProfileVersionInput): Promise<void> {
  const tenantId = data.value?.tenantId

  if (tenantId === undefined) {
    throw new Error('A tenant is required before creating a version.')
  }

  creating.value = true

  try {
    const version = await createDeviceProfileVersion(tenantId, projectId, profileId, payload)

    if (activeTenantId.value !== tenantId) {
      return
    }

    await refresh()
    draft.value = null
    clearStoredDraft()
    selectedId.value = version.id
    toast.add({
      title: 'Version created',
      description: `Created version ${version.version}.`,
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to create version', description: error.message, color: 'error' })
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="device-profile-detail" :ui="{ body: 'p-0 sm:p-0' }">
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UButton
            icon="i-lucide-arrow-left"
            color="neutral"
            variant="ghost"
            to="/device-profiles"
            aria-label="Back to device profiles"
          />
        </template>

        <template #title>
          <span class="truncate">{{ profile?.name ?? 'Device profile' }}</span>
          <UBadge
            v-if="profile"
            color="neutral"
            variant="subtle"
            class="ml-2 font-mono text-xs"
          >
            {{ profile.code }}
          </UBadge>
        </template>

        <template #right>
          <UButton
            label="Schema guide"
            icon="i-lucide-book-open-check"
            color="neutral"
            variant="outline"
            @click="guideOpen = true"
          />
          <UDropdownMenu
            v-if="canWrite"
            :items="newVersionItems"
            :content="{ align: 'end' }"
          >
            <UButton
              label="New version"
              icon="i-lucide-plus"
              trailing-icon="i-lucide-chevron-down"
            />
          </UDropdownMenu>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="status === 'pending' && !data" class="p-6 text-sm text-muted">
        Loading…
      </div>

      <UAlert
        v-else-if="error || !profile"
        class="m-6"
        color="error"
        variant="subtle"
        title="Unable to load device profile"
        :description="error instanceof ApiError ? error.message : 'The device profile was not found.'"
      />

      <div v-else class="flex h-full min-h-0 flex-col md:flex-row">
        <aside class="flex shrink-0 flex-col border-b border-default md:w-64 md:border-r md:border-b-0">
          <div class="flex shrink-0 flex-col gap-2 border-b border-default p-3">
            <UInput
              v-model="versionSearch"
              icon="i-lucide-search"
              placeholder="Search versions..."
            />
          </div>

          <div class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto p-3">
            <div
              v-if="draft"
              class="flex items-start gap-1 rounded-lg border border-dashed border-primary/50 px-3 py-2 transition-colors hover:bg-elevated/60"
              :class="activeId === 'new' ? 'bg-primary/10' : ''"
            >
              <button type="button" class="flex min-w-0 flex-1 flex-col items-start gap-0.5 text-left" @click="selectedId = 'new'">
                <span class="flex items-center gap-2">
                  <span class="font-mono text-sm font-semibold text-highlighted">New</span>
                  <UBadge color="warning" variant="subtle" size="xs">Draft</UBadge>
                </span>
                <span class="w-full truncate text-xs text-muted">{{ draft.meta.schema_code }}</span>
                <span class="text-xs text-dimmed">Waiting for fields</span>
              </button>
              <UButton
                icon="i-lucide-x"
                color="neutral"
                variant="ghost"
                size="xs"
                aria-label="Discard draft"
                @click="discardDraft"
              />
            </div>

            <button
              v-for="version in filteredVersions"
              :key="version.id"
              type="button"
              class="flex flex-col items-start gap-0.5 rounded-lg px-3 py-2 text-left transition-colors hover:bg-elevated/60"
              :class="activeId === version.id ? 'bg-primary/10 ring ring-primary/30' : ''"
              @click="selectedId = version.id"
            >
              <span class="flex w-full items-center justify-between gap-2">
                <span class="font-mono text-sm font-semibold text-highlighted">v{{ version.version }}</span>
                <UIcon
                  v-if="version.definition.strict_mode"
                  name="i-lucide-shield-check"
                  class="size-4 text-success"
                />
              </span>
              <span class="w-full truncate text-xs text-muted">{{ version.schema_code }}</span>
              <span class="text-xs text-dimmed">{{ formatDateTime(version.created_at) }}</span>
            </button>

            <p v-if="filteredVersions.length === 0" class="px-3 py-2 text-sm text-muted">
              {{ versions.length === 0 ? 'No versions yet.' : 'No matching versions.' }}
            </p>
          </div>
        </aside>

        <section class="min-w-0 flex-1 overflow-y-auto p-4 sm:p-6">
          <template v-if="activeId !== null">
            <AdminDeviceProfileVersionEditor
              v-if="draft"
              v-show="activeId === 'new'"
              :key="`new-${draftSeq}`"
              :base="draft.base"
              :meta="draft.meta"
              :can-write="true"
              :creating="creating"
              :draft-text="draft.text"
              @create="createVersion"
              @save-draft="saveDraftText"
            />
            <AdminDeviceProfileVersionEditor
              v-if="activeId !== 'new' && viewedVersion"
              :key="viewedVersion.id"
              :base="viewedVersion"
              :meta="{ schema_code: viewedVersion.schema_code, compatibility_note: viewedVersion.compatibility_note, strict_mode: viewedVersion.definition.strict_mode }"
              :can-write="false"
              :creating="false"
            />
          </template>
        </section>
      </div>
      <AdminDeviceProfileGuideModal v-model:open="guideOpen" />

      <UModal v-model:open="metaOpen" title="New version" description="Set the version details, then define its fields.">
        <template #body>
          <UForm
            id="version-meta-form"
            :schema="metaSchema"
            :state="metaState"
            class="space-y-4"
            @submit="confirmMeta"
          >
            <UFormField name="schema_code" label="Schema code" required>
              <UInput
                v-model="metaState.schema_code"
                class="w-full"
                maxlength="64"
                placeholder="e.g. temperature_v2"
                autofocus
              />
            </UFormField>

            <UFormField name="strict_mode" label="Strict mode" description="Reject telemetry containing fields not defined in the schema.">
              <USwitch v-model="metaState.strict_mode" />
            </UFormField>

            <UFormField name="compatibility_note" label="Compatibility note">
              <UTextarea
                v-model="metaState.compatibility_note"
                class="w-full"
                :rows="3"
                maxlength="1000"
                placeholder="Describe compatibility with previous versions"
              />
            </UFormField>
          </UForm>
        </template>

        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="subtle"
              @click="metaOpen = false"
            />
            <UButton label="Continue" type="submit" form="version-meta-form" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
