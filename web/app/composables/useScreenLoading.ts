const isScreenLoading = ref(false)

export function useScreenLoading() {
  const loadingIndicator = useLoadingIndicator()

  async function withScreenLoading<T>(action: () => Promise<T>, minMs = 400): Promise<T> {
    const startTime = Date.now()
    loadingIndicator.start({ force: true })
    isScreenLoading.value = true

    try {
      return await action()
    } finally {
      const elapsed = Date.now() - startTime
      const remaining = Math.max(0, minMs - elapsed)
      if (remaining > 0) {
        await new Promise(resolve => setTimeout(resolve, remaining))
      }
      loadingIndicator.finish({ force: true })
      isScreenLoading.value = false
    }
  }

  return {
    isScreenLoading: readonly(isScreenLoading),
    withScreenLoading
  }
}
