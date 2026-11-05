export const HIERARCHY_CARD_WIDTH = 250
export const HIERARCHY_CARD_GAP = 32
export const HIERARCHY_ROW_HEIGHT = 140
const ORIGIN = { x: 80, y: 40 }

export interface HierarchyEntry {
  id: string
  parentId: string | null
}

export interface HierarchyLayout {
  positions: Map<string, { x: number, y: number }>
  childrenOf: Map<string, string[]>
}

/** Selected ids plus every ancestor, so a selected node is never drawn without its path to the root. */
export function withAncestors(selected: Iterable<string>, entries: HierarchyEntry[]): Set<string> {
  const parentOf = new Map(entries.map(entry => [entry.id, entry.parentId]))
  const visible = new Set<string>()

  for (const id of selected) {
    let current: string | null | undefined = parentOf.has(id) ? id : null

    while (current != null && !visible.has(current)) {
      visible.add(current)
      current = parentOf.get(current)
    }
  }

  return visible
}

/** Tidy top-down layout of the visible forest; `entries` order decides sibling order. */
export function layoutHierarchy(entries: HierarchyEntry[], visible: Set<string>): HierarchyLayout {
  const childrenOf = new Map<string, string[]>()
  const roots: string[] = []

  for (const { id, parentId } of entries) {
    if (!visible.has(id)) {
      continue
    }

    if (parentId === null || !visible.has(parentId)) {
      roots.push(id)
    } else {
      childrenOf.set(parentId, [...(childrenOf.get(parentId) ?? []), id])
    }
  }

  const widths = new Map<string, number>()
  const positions = new Map<string, { x: number, y: number }>()

  function width(id: string): number {
    const cached = widths.get(id)

    if (cached !== undefined) {
      return cached
    }

    const children = childrenOf.get(id) ?? []
    const childrenWidth = children.reduce((sum, child) => sum + width(child), 0)
      + Math.max(0, children.length - 1) * HIERARCHY_CARD_GAP
    const result = Math.max(HIERARCHY_CARD_WIDTH, childrenWidth)

    widths.set(id, result)

    return result
  }

  function place(id: string, left: number, depth: number): void {
    const children = childrenOf.get(id) ?? []
    const y = ORIGIN.y + depth * HIERARCHY_ROW_HEIGHT

    if (children.length === 0) {
      positions.set(id, { x: left + (width(id) - HIERARCHY_CARD_WIDTH) / 2, y })

      return
    }

    const childrenWidth = children.reduce((sum, child) => sum + width(child), 0)
      + (children.length - 1) * HIERARCHY_CARD_GAP
    let childLeft = left + (width(id) - childrenWidth) / 2

    for (const child of children) {
      place(child, childLeft, depth + 1)
      childLeft += width(child) + HIERARCHY_CARD_GAP
    }

    const first = positions.get(children[0]!)!
    const last = positions.get(children[children.length - 1]!)!

    positions.set(id, { x: (first.x + last.x) / 2, y })
  }

  let left = ORIGIN.x

  for (const root of roots) {
    place(root, left, 0)
    left += width(root) + HIERARCHY_CARD_GAP * 2
  }

  return { positions, childrenOf }
}

export interface HierarchyFilterItem {
  id: string
  label: string
  searchText?: string
  icon?: string
  iconClass?: string
  children?: HierarchyFilterItem[]
  onSelect?: (event: { preventDefault: () => void }) => void
}

export function flattenFilterItems(items: HierarchyFilterItem[]): HierarchyFilterItem[] {
  return items.flatMap(item => [item, ...flattenFilterItems(item.children ?? [])])
}
