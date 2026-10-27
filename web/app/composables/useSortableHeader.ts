import { UButton } from '#components'

/**
 * Column header button that toggles sorting, matching the headers on the Assets table.
 * UButton is imported rather than resolved with resolveComponent because pages call this
 * after an await, where the component instance is gone and the header would render empty.
 */
export function useSortableHeader() {
  return function sortableHeader(label: string) {
    return ({ column }: { column: { getIsSorted: () => false | 'asc' | 'desc', toggleSorting: (desc?: boolean) => void } }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label,
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    }
  }
}
