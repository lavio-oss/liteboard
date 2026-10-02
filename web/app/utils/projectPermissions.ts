import type { ProjectRole } from '~/types/admin'

export function canWriteProjectData(role: ProjectRole | null, canManageTenant: boolean): boolean {
  return canManageTenant || role === 'admin' || role === 'engineer'
}

export function canDeleteProjectData(role: ProjectRole | null, canManageTenant: boolean): boolean {
  return canManageTenant || role === 'admin'
}

export function canManageProjectMembers(role: ProjectRole | null, canManageTenant: boolean): boolean {
  return canManageTenant || role === 'admin'
}
