export type WorkspaceKey = 'admin' | 'liteboard'

const sharedSettingsPaths = new Set(['/settings', '/settings/security'])

export function useWorkspaceContext() {
  const route = useRoute()
  const activeWorkspace = useState<WorkspaceKey>('workspace:active', () => (
    route.path === '/admin' || route.path.startsWith('/admin/') ? 'admin' : 'liteboard'
  ))

  watch(() => route.path, (path) => {
    if (path === '/admin' || path.startsWith('/admin/')) {
      activeWorkspace.value = 'admin'
    } else if (!sharedSettingsPaths.has(path)) {
      activeWorkspace.value = 'liteboard'
    }
  }, { immediate: true })

  function selectWorkspace(workspace: WorkspaceKey): void {
    activeWorkspace.value = workspace
  }

  return {
    activeWorkspace,
    isAdminWorkspace: computed(() => activeWorkspace.value === 'admin'),
    selectWorkspace
  }
}
