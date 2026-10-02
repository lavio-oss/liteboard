export default defineAppConfig({
  ui: {
    colors: {
      primary: 'green',
      neutral: 'gray'
    },
    modal: {
      slots: {
        overlay: 'fixed inset-0',
        content: 'bg-default divide-y divide-default flex flex-col focus:outline-none fixed w-[calc(100vw-2rem)] max-w-lg rounded-lg shadow-lg ring ring-default !top-6 !translate-y-0 !max-h-[calc(100dvh-3rem)] overflow-hidden',
        header: 'flex items-center gap-1.5 p-4 sm:px-6 min-h-(--ui-header-height)',
        wrapper: '',
        body: 'flex-1 min-h-0 overflow-y-auto p-4 sm:p-6',
        footer: 'flex items-center gap-1.5 p-4 sm:px-6',
        title: 'text-highlighted font-semibold',
        description: 'mt-1 text-muted text-sm',
        close: 'absolute top-4 end-4'
      }
    }
  }
})
