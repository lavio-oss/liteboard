export function initialProvisioningProjectId(
  filteredProjectId: string | null,
  writableProjectIds: string[]
): string | null {
  return filteredProjectId !== null && writableProjectIds.includes(filteredProjectId)
    ? filteredProjectId
    : null
}
